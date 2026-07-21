<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Metrics;

use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\QueueManager;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Throwable;

/**
 * Samples live queue depth and worker counts and buffers them as a
 * `replica_metric` event (US-048).
 *
 * Queue depth and worker count are point-in-time facts about the queue backend,
 * not per-event telemetry, so they are sampled on an interval and piggybacked
 * onto whatever flush happens next — there is no dedicated request. On a queue
 * worker the buffer is flushed at the end of every job (US-047), so
 * {@see collect()} runs before each of those flushes and adds a sample at most
 * once per configured interval; between samples the call is a cheap timestamp
 * comparison.
 *
 * {@see PrismServiceProvider::registerQueueMetrics()} binds this only on a
 * process that actually works the queue, so a web replica that dispatches jobs
 * but never processes them registers nothing and pays nothing here (AC4).
 *
 * Two robustness rules make it safe to run in any host:
 *
 *   - It reads depth through the host application's own configured queue
 *     connection ({@see QueueManager}), which does a Redis
 *     `LLEN` or a database `count()` — Prism never opens a backend connection of
 *     its own (AC2/AC6).
 *   - Every backend read is wrapped so a connection it cannot reach degrades to
 *     a null depth (and Horizon that is absent or unreadable to null worker
 *     fields) rather than throwing (AC3/AC5). Telemetry must never surface an
 *     error into the host application it monitors.
 */
final class QueueMetrics
{
    /** The telemetry signal name (US-026); the server routes it to `replica_metrics`. */
    private const EVENT_TYPE = 'replica_metric';

    /**
     * Epoch second of the last sample, or null before the first one. Lives on the
     * singleton, so it throttles across the many flushes of a long-lived worker.
     */
    private ?int $lastSampledAt = null;

    /**
     * @param  int  $intervalSeconds  Minimum seconds between samples; zero or
     *                                negative samples on every flush.
     */
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Container $container,
        private readonly int $intervalSeconds = 30,
    ) {}

    /**
     * Add a queue-metrics sample to the buffer when the interval has elapsed, so
     * it ships with the flush this call precedes. A no-op between intervals and
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

    /** Whether enough time has passed since the last sample to take another. */
    private function due(): bool
    {
        if ($this->intervalSeconds <= 0 || $this->lastSampledAt === null) {
            return true;
        }

        return ($this->now() - $this->lastSampledAt) >= $this->intervalSeconds;
    }

    /**
     * Build the metric event: the correlating envelope (a replica metric belongs
     * to no trace, so its trace/request/user ids are empty) plus a payload of the
     * queue depths and worker fields the server spreads over `replica_metrics`.
     *
     * @return array<string, mixed>
     */
    private function sample(): array
    {
        $horizon = $this->horizonMetrics();

        return [
            'timestamp' => now()->toIso8601String(),
            'trace_id' => '',
            'request_id' => '',
            'user_id' => '',
            'payload' => [
                'queues' => $this->queueDepths(),
                'workers' => $horizon['workers'],
                'supervisor' => $horizon['supervisor'],
            ],
        ];
    }

    /**
     * The depth of each configured queue connection's default queue, read
     * directly from the backend. The whole sweep is suppressed so a database
     * connection's `count()` query is not itself captured, and each connection is
     * read defensively so one unreachable backend degrades to a null depth
     * without aborting the rest.
     *
     * @return list<array{connection: string, queue: string, depth: int|null}>
     */
    private function queueDepths(): array
    {
        return Recursion::suppress(function (): array {
            $connections = $this->container['config']->get('queue.connections', []);

            if (! is_array($connections)) {
                return [];
            }

            $depths = [];

            foreach ($connections as $name => $config) {
                if (! is_string($name)) {
                    continue;
                }

                $queue = is_array($config) && isset($config['queue']) && is_string($config['queue'])
                    ? $config['queue']
                    : 'default';

                $depths[] = [
                    'connection' => $name,
                    'queue' => $queue,
                    'depth' => $this->safeSize($name, $queue),
                ];
            }

            return $depths;
        });
    }

    /**
     * The size of one queue through the host's queue manager, or null when the
     * backend cannot be read. `size()` is a Redis `LLEN` (plus delayed/reserved)
     * or a database `count()` — the host's own connection, never one Prism opens.
     */
    private function safeSize(string $connection, string $queue): ?int
    {
        try {
            $size = $this->container->make('queue')->connection($connection)->size($queue);

            return is_numeric($size) ? (int) $size : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Worker count and supervisor name from Horizon, or nulls when Horizon is not
     * installed or cannot be read (AC3). Read through Horizon's own supervisor
     * repository — no direct backend access — and fully guarded so a host without
     * Horizon, or with an unreachable one, reports nulls rather than throwing.
     *
     * @return array{workers: int|null, supervisor: string|null}
     */
    private function horizonMetrics(): array
    {
        $absent = ['workers' => null, 'supervisor' => null];

        $repository = 'Laravel\\Horizon\\Contracts\\SupervisorRepository';

        if (! interface_exists($repository) || ! $this->container->bound($repository)) {
            return $absent;
        }

        try {
            return Recursion::suppress(function () use ($repository, $absent): array {
                $supervisors = $this->container->make($repository)->all();

                if (! is_array($supervisors) || $supervisors === []) {
                    return $absent;
                }

                $workers = 0;
                $supervisor = null;

                foreach ($supervisors as $record) {
                    if (! is_object($record)) {
                        continue;
                    }

                    if ($supervisor === null && isset($record->name) && is_string($record->name)) {
                        $supervisor = $record->name;
                    }

                    $processes = $record->processes ?? null;

                    if (is_array($processes)) {
                        foreach ($processes as $count) {
                            if (is_numeric($count)) {
                                $workers += (int) $count;
                            }
                        }
                    }
                }

                return ['workers' => $workers, 'supervisor' => $supervisor];
            });
        } catch (Throwable) {
            return $absent;
        }
    }

    /** Current epoch second, honouring Carbon's test clock so the interval is testable. */
    private function now(): int
    {
        return now()->getTimestamp();
    }
}
