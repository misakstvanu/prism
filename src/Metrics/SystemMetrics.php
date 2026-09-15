<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Metrics;

use Closure;
use Illuminate\Contracts\Container\Container;
use Laravel\Nightwatch\Contracts\Ingest;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RecordTranslator;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Runtime;
use Throwable;

/**
 * Samples this instance's own health — CPU load, memory pressure, uptime — as a `replica_metric` event
 * (US-051), charting even a replica that is up but idle. Counterpart to {@see QueueMetrics}, same signal:
 * queue depth is only meaningful on a process that works the queue, while CPU/memory/uptime matter on every
 * replica, so {@see PrismServiceProvider::registerSystemMetrics()} registers this one unconditionally.
 * Interval-sampled (default 60s), not per flush: {@see PrismServiceProvider::flush()} calls
 * {@see collect()} at every execution's end and most of those calls do nothing.
 *
 * **A due sample is shipped with {@see Ingest::writeNow()}, never buffered** (US-013). The capture engine
 * Nightwatch answers a sampler-rejected execution in `Core::finishExecution()` with `flush()`, which on
 * {@see PrismIngest} *discards the whole buffer*: a buffered metric would vanish whenever the request it
 * rode was sampled out, and CPU, memory and uptime say nothing about whether one request was interesting.
 * Its own batch keeps the Replicas screen and the `replica_cpu` alert on the interval's rate, independently
 * of the sampling rate. The record is Nightwatch-shaped (`v`/`t`/`timestamp` and the fields beside them —
 * the ingest's vocabulary) but its type `replica_metric` is **Prism's own**: Nightwatch's `Sensors`
 * directory has no system sensor and no queue sensor, so there is no upstream counterpart to replace, and
 * {@see RecordTranslator} knows the type.
 *
 * Safe in any host (AC4): each reading comes from an OS source that may not exist (Linux
 * `sys_getloadavg()`, `/proc/meminfo`, `/proc/uptime`) and yields null for that field rather than an error,
 * so a non-Linux host reports nulls instead of throwing, and the whole collect path is wrapped so a
 * sampling fault never surfaces into the host application it monitors. The interval holds across processes,
 * not only within one: the in-memory guard short-circuits a persistent runtime (a worker, Octane) between
 * samples, and an atomic cache throttle bounds a web replica's many short-lived php-fpm processes to one
 * sample per interval, not one per request.
 */
class SystemMetrics
{
    /**
     * The telemetry signal name (US-026) — the server routes it to `replica_metrics` — and, since US-013,
     * the record type handed to the ingest. Prism's own: Nightwatch has no system sensor, no upstream `t`.
     */
    private const EVENT_TYPE = 'replica_metric';

    /** Cache-key prefix for the cross-process once-per-interval throttle. */
    private const THROTTLE_PREFIX = 'prism:metrics:system:';

    /**
     * Epoch second of the last sample in this process, null before the first. On a persistent runtime it
     * short-circuits the interval check with no cache round-trip; on php-fpm the process is rebuilt each
     * request, so the cache throttle below is what bounds the rate there.
     */
    private ?int $lastSampledAt = null;

    /**
     * @param  Closure(): ?Ingest  $ingest  Resolved per sample, not held: the
     *                                      ingest Prism installs over
     *                                      `Core::$ingest` is assigned in a
     *                                      `booted` callback, which on a re-boot
     *                                      (Octane, a test) runs after this
     *                                      collector was built.
     * @param  string  $replica  This instance's name — scopes the throttle key so
     *                           distinct replicas on a shared cache do not
     *                           throttle one another.
     * @param  string  $replicaType  `web` or `worker` — inferred from the process
     *                               ({@see Runtime::isQueueWorker()}) or overridden
     *                               in config (AC3).
     * @param  int  $intervalSeconds  Minimum seconds between samples; zero or
     *                                negative samples on every flush.
     */
    public function __construct(
        private readonly Closure $ingest,
        private readonly Container $container,
        private readonly string $replica,
        private readonly string $replicaType,
        private readonly int $intervalSeconds = 60,
    ) {}

    /**
     * Ship a health sample when the interval has elapsed. A no-op between intervals and while the package
     * is doing its own work; never throws.
     */
    public function collect(): void
    {
        try {
            if (Recursion::suppressed() || ! $this->due()) {
                return;
            }

            $ingest = ($this->ingest)();

            if ($ingest === null) {
                return;
            }

            // Marked sampled BEFORE the send: the send path swallows its own failures, but a resolver
            // above it might throw, and that must not turn one unreachable ingest into a sample per flush.
            $this->lastSampledAt = $this->now();

            $ingest->writeNow($this->record());
        } catch (Throwable) {
            // Telemetry must never surface an error into the host application.
        }
    }

    /**
     * Whether enough time has passed since the last sample to take another. The in-process guard runs
     * first, so a persistent runtime never touches the cache between intervals. Once the interval has
     * elapsed (or the process is fresh, as under php-fpm), an atomic cache add returns true only for the
     * first caller in the window: a shared cache bounds the rate per replica, an array cache per process.
     * The op is suppressed so it is not itself captured; a cache failure falls back to the in-process
     * decision, which already passed.
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
     * Build the record: the globals the translator reads off every record plus the health fields, which
     * need no restating — Prism raised this signal in its own vocabulary, so the translator's passthrough
     * carries them into the payload under exactly these names. A field the host does not expose is null
     * (AC4); the reported replica type rides along too (AC3). A replica metric belongs to no trace and to
     * no execution: `trace_id` is blank and there is no `execution_id`, so {@see PrismIngest} must not
     * stamp one onto it the way it does for a request. The timestamp is a **float microtime**, the shape
     * every Nightwatch record carries and the only one the translator accepts; off Carbon rather than
     * `microtime(true)` so `Carbon::setTestNow()` can still move it.
     *
     * @return array<string, mixed>
     */
    private function record(): array
    {
        return [
            'v' => 1,
            't' => self::EVENT_TYPE,
            'timestamp' => (float) now()->format('U.u'),
            'trace_id' => '',
            'cpu_percent' => $this->cpuPercent(),
            'memory_percent' => $this->memoryPercent(),
            'uptime_seconds' => $this->uptimeSeconds(),
            'type' => $this->replicaType,
        ];
    }

    /**
     * CPU utilisation as a percentage: the one-minute load average over the core count, clamped to 0..100.
     * Null where the host exposes no load average (e.g. Windows).
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
     * Memory in use as a percentage of total, from `/proc/meminfo`: `(MemTotal - MemAvailable) / MemTotal`.
     * Falls back to `MemFree` on kernels without `MemAvailable`; null when the file is unreadable or
     * malformed.
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
     * Uptime in whole seconds, from `/proc/uptime`'s first field (seconds since boot). Null when unreadable.
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
     * The one-minute load average, null where the host exposes none. A seam so a test can simulate that.
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
     * Logical CPUs, counted from `/proc/cpuinfo`; 1 when unreadable. A seam so a test can fix the count.
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
     * Raw `/proc/meminfo`, null where it does not exist. A seam to feed known figures or simulate absence.
     */
    protected function meminfo(): ?string
    {
        $contents = @file_get_contents('/proc/meminfo');

        return is_string($contents) ? $contents : null;
    }

    /**
     * Raw `/proc/uptime`, null where it does not exist. A seam to feed a known uptime or simulate absence.
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
