<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Metrics;

use Illuminate\Contracts\Container\Container;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Runtime;
use Throwable;

/**
 * Samples this instance's own health — CPU load, memory pressure and uptime —
 * and buffers it as a `replica_metric` event (US-051), so the console can chart
 * the health of every running replica even one that is up but idle.
 *
 * It is the counterpart to {@see QueueMetrics}: both emit the same
 * `replica_metric` signal, but where queue depth is only meaningful on a process
 * that works the queue, an instance's CPU/memory/uptime matter on every replica
 * — a web front-end just as much as a worker — so this collector is registered
 * unconditionally by {@see PrismServiceProvider::registerSystemMetrics()}.
 *
 * Like queue polling it is sampled on an interval (default 60s) and piggybacked
 * onto whatever flush happens next rather than sent on its own request (AC1):
 * {@see PrismServiceProvider::flush()} calls {@see collect()} before draining the
 * buffer, so a due sample rides an existing flush.
 *
 * Two robustness rules make it safe in any host (AC4):
 *
 *   - Every reading is taken from an OS source that may not exist (Linux
 *     `sys_getloadavg()`, `/proc/meminfo`, `/proc/uptime`). A source the host
 *     does not expose yields a null for that field rather than an error, so a
 *     non-Linux host reports nulls instead of throwing.
 *   - The whole collect path is wrapped so a sampling fault can never surface
 *     into the host application it monitors.
 *
 * The interval is honoured across processes, not just within one: the in-memory
 * guard short-circuits a persistent runtime (a worker, Octane) between samples,
 * and an atomic cache throttle coordinates the many short-lived php-fpm
 * processes of a web replica so they still sample at most once per interval
 * rather than once per request.
 */
class SystemMetrics
{
    /** The telemetry signal name (US-026); the server routes it to `replica_metrics`. */
    private const EVENT_TYPE = 'replica_metric';

    /** Cache-key prefix for the cross-process once-per-interval throttle. */
    private const THROTTLE_PREFIX = 'prism:metrics:system:';

    /**
     * Epoch second of the last sample taken in this process, or null before the
     * first. On a persistent runtime it short-circuits the interval check with no
     * cache round-trip; on php-fpm the process is rebuilt each request, so the
     * cache throttle below is what actually bounds the rate there.
     */
    private ?int $lastSampledAt = null;

    /**
     * @param  string  $replica  This instance's name — used to scope the throttle
     *                           key so distinct replicas on a shared cache do not
     *                           throttle one another.
     * @param  string  $replicaType  `web` or `worker` — inferred from the process
     *                               ({@see Runtime::isQueueWorker()}) or overridden
     *                               in config (AC3).
     * @param  int  $intervalSeconds  Minimum seconds between samples; zero or
     *                                negative samples on every flush.
     */
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Container $container,
        private readonly string $replica,
        private readonly string $replicaType,
        private readonly int $intervalSeconds = 60,
    ) {}

    /**
     * Add a health sample to the buffer when the interval has elapsed, so it
     * ships with the flush this call precedes. A no-op between intervals and
     * while the package is doing its own work; never throws.
     */
    public function collect(): void
    {
        try {
            if (Recursion::suppressed() || ! $this->due()) {
                return;
            }

            $this->lastSampledAt = $this->now();
            $this->buffer->add(self::EVENT_TYPE, $this->sample());
        } catch (Throwable) {
            // Telemetry must never surface an error into the host application.
        }
    }

    /**
     * Whether enough time has passed since the last sample to take another.
     *
     * A cheap in-process guard runs first, so a persistent runtime never touches
     * the cache between intervals. Once the interval has elapsed (or the process
     * is fresh, as under php-fpm), an atomic cache add coordinates across every
     * process on this replica — it returns true only for the first caller in the
     * window, so a shared cache bounds the rate per replica and an array cache
     * per process. The cache op is suppressed so it is not itself captured; a
     * cache failure falls back to the in-process decision, which already passed.
     */
    private function due(): bool
    {
        if ($this->intervalSeconds <= 0) {
            return true;
        }

        if ($this->lastSampledAt !== null && ($this->now() - $this->lastSampledAt) < $this->intervalSeconds) {
            return false;
        }

        try {
            return Recursion::suppress(fn (): bool => (bool) $this->container->make('cache')
                ->add(self::THROTTLE_PREFIX.$this->replica, true, $this->intervalSeconds));
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Build the metric event: the correlating envelope (a replica metric belongs
     * to no trace, so its trace/request/user ids are empty) plus a payload of the
     * health fields the server spreads over `replica_metrics`. A field the host
     * does not expose is null (AC4); the reported replica type rides the payload
     * too (AC3, dropped by the server until a column exists for it).
     *
     * @return array<string, mixed>
     */
    private function sample(): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'trace_id' => '',
            'request_id' => '',
            'user_id' => '',
            'payload' => [
                'cpu_percent' => $this->cpuPercent(),
                'memory_percent' => $this->memoryPercent(),
                'uptime_seconds' => $this->uptimeSeconds(),
                'type' => $this->replicaType,
            ],
        ];
    }

    /**
     * CPU utilisation as a percentage: the one-minute load average divided by the
     * number of cores, clamped to 0..100. Null when the host does not expose a
     * load average (e.g. Windows).
     */
    protected function cpuPercent(): ?float
    {
        $load = $this->loadAverage();

        if ($load === null) {
            return null;
        }

        $cores = max(1, $this->cpuCores());

        return round(max(0.0, min(100.0, ($load / $cores) * 100)), 1);
    }

    /**
     * Memory in use as a percentage of total, read from `/proc/meminfo`:
     * `(MemTotal - MemAvailable) / MemTotal`. Falls back to `MemFree` on kernels
     * without `MemAvailable`; null when the file is unreadable or malformed.
     */
    protected function memoryPercent(): ?float
    {
        $info = $this->meminfo();

        if ($info === null) {
            return null;
        }

        $total = $this->meminfoValue($info, 'MemTotal');
        $available = $this->meminfoValue($info, 'MemAvailable') ?? $this->meminfoValue($info, 'MemFree');

        if ($total === null || $total <= 0 || $available === null) {
            return null;
        }

        return round(max(0.0, min(100.0, (($total - $available) / $total) * 100)), 1);
    }

    /**
     * Uptime in whole seconds, from `/proc/uptime`'s first field (seconds since
     * the instance booted). Null when the file is unreadable.
     */
    protected function uptimeSeconds(): ?int
    {
        $raw = $this->uptimeRaw();

        if ($raw === null) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($raw));
        $first = is_array($parts) ? ($parts[0] ?? null) : null;

        return is_string($first) && is_numeric($first) ? (int) (float) $first : null;
    }

    /**
     * The one-minute load average, or null where the host does not expose one.
     * A seam so a test can simulate a host that reports no load.
     */
    protected function loadAverage(): ?float
    {
        if (! function_exists('sys_getloadavg')) {
            return null;
        }

        $load = @sys_getloadavg();

        return is_array($load) && isset($load[0]) && is_numeric($load[0]) ? (float) $load[0] : null;
    }

    /**
     * The number of logical CPUs, counted from `/proc/cpuinfo`; falls back to 1
     * when the file is unreadable. A seam so a test can fix the core count.
     */
    protected function cpuCores(): int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');

        if (is_string($cpuinfo)) {
            $count = preg_match_all('/^processor\s*:/m', $cpuinfo);

            if (is_int($count) && $count > 0) {
                return $count;
            }
        }

        return 1;
    }

    /**
     * The raw contents of `/proc/meminfo`, or null where it does not exist. A
     * seam so a test can feed known memory figures or simulate its absence.
     */
    protected function meminfo(): ?string
    {
        $contents = @file_get_contents('/proc/meminfo');

        return is_string($contents) ? $contents : null;
    }

    /**
     * The raw contents of `/proc/uptime`, or null where it does not exist. A seam
     * so a test can feed a known uptime or simulate its absence.
     */
    protected function uptimeRaw(): ?string
    {
        $contents = @file_get_contents('/proc/uptime');

        return is_string($contents) ? $contents : null;
    }

    /** A named `/proc/meminfo` value in kB, or null when the key is absent. */
    private function meminfoValue(string $info, string $key): ?int
    {
        if (preg_match('/^'.preg_quote($key, '/').':\s+(\d+)/m', $info, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /** Current epoch second, honouring Carbon's test clock so the interval is testable. */
    private function now(): int
    {
        return now()->getTimestamp();
    }
}
