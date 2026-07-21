<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Capture;

use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Throwable;

/**
 * Turns a queued job's lifecycle into buffered `job` telemetry events (US-047),
 * mirroring the server's `jobs` columns (US-006): the resolved job class, its
 * queue, connection, UUID, attempt number, the time it waited in the queue, its
 * runtime, the scrubbed payload and — for a failure — the full exception with
 * stack trace.
 *
 * {@see PrismServiceProvider::registerJobCapture()} wires four listeners to this
 * one singleton, one per lifecycle stage:
 *
 *   - `JobProcessing` → {@see recordStart()} stamps the processing-start time and
 *     computes the queue wait, so the terminal event can report both. Queue wait
 *     is dispatch-to-processing-start: the dispatch time rides the job payload
 *     under {@see QUEUED_AT_KEY} (stamped by the provider's queue hook), so it is
 *     correct even for a job delayed for minutes before a worker picks it up.
 *   - `JobProcessed` → {@see recordProcessed()} buffers a `processed` event.
 *   - `JobReleasedAfterException` → {@see recordReleased()} buffers a `retried`
 *     event — the attempt threw and the job was released back for another try.
 *   - `JobFailed` → {@see recordFailed()} buffers a `failed` event carrying the
 *     exception. These three outcomes are mutually exclusive per attempt.
 *
 * A worker processes one job at a time, so the per-job timing is keyed by the
 * job UUID and resolved on the matching terminal event. The provider flushes the
 * buffer after each terminal event, so a job's telemetry ships at the end of the
 * job rather than accumulating across a long-running worker (AC5).
 *
 * The package's own jobs — the flush job (US-038) and anything else it dispatches
 * — are never captured, so shipping a batch cannot generate the telemetry the
 * next batch ships. Every public method is best-effort: a malformed job object
 * can never surface an error into the host, exactly as a telemetry client must
 * behave.
 */
final class JobCapture
{
    /** The telemetry signal name (US-026); the server routes it to `jobs`. */
    private const EVENT_TYPE = 'job';

    /**
     * Job-payload key carrying the dispatch time (microseconds since the epoch),
     * stamped by the provider's queue hook so the queue wait can be measured from
     * dispatch to processing start (AC4).
     */
    public const QUEUED_AT_KEY = 'prism_queued_at';

    /**
     * Per-job processing state, keyed by job UUID: the processing-start time (in
     * microseconds) and the already-computed queue wait (in milliseconds). Set on
     * `JobProcessing` and consumed on the matching terminal event.
     *
     * @var array<string, array{started: float, wait: float}>
     */
    private array $pending = [];

    /**
     * @param  list<string>  $ignore  Fully-qualified job classes never captured.
     */
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Scrubber $scrubber,
        private readonly Container $container,
        private readonly array $ignore,
    ) {}

    /**
     * Record the start of processing: stamp the start time and compute the queue
     * wait, keyed by the job UUID for the terminal event to pick up.
     */
    public function recordStart(JobProcessing $event): void
    {
        try {
            $job = $event->job;

            if ($this->shouldSkip($job)) {
                return;
            }

            $this->pending[$this->key($job)] = [
                'started' => $this->nowMicros(),
                'wait' => $this->queueWaitMs($job),
            ];
        } catch (Throwable) {
            // Telemetry must never surface an error into the host application.
        }
    }

    /** Buffer a `processed` event for a job that completed successfully. */
    public function recordProcessed(JobProcessed $event): bool
    {
        return $this->record($event->job, (string) $event->connectionName, 'processed', null);
    }

    /**
     * Buffer a `retried` event for a job released back onto the queue after
     * throwing — the attempt failed but the job will be tried again.
     */
    public function recordReleased(JobReleasedAfterException $event): bool
    {
        return $this->record($event->job, (string) $event->connectionName, 'retried', null);
    }

    /**
     * Buffer a `failed` event for a job that exhausted its retries (or was failed
     * outright), carrying the full exception with its stack trace (AC3).
     */
    public function recordFailed(JobFailed $event): bool
    {
        $exception = $event->exception instanceof Throwable ? $event->exception : null;

        return $this->record($event->job, (string) $event->connectionName, 'failed', $exception);
    }

    /**
     * Build and buffer one job event for a terminal outcome. Returns whether an
     * event was recorded, so the provider knows whether to flush the buffer for
     * this job (AC5) — the package's own and ignored jobs record nothing.
     */
    private function record(object $job, string $connection, string $status, ?Throwable $exception): bool
    {
        try {
            if ($this->shouldSkip($job)) {
                return false;
            }

            $key = $this->key($job);
            $timing = $this->pending[$key] ?? null;
            unset($this->pending[$key]);

            // Queue wait was fixed at processing start; a terminal-only recompute
            // would fold in the runtime, so prefer the stored value.
            $wait = $timing['wait'] ?? $this->queueWaitMs($job);
            $runtime = $timing !== null
                ? max(0.0, round(($this->nowMicros() - $timing['started']) / 1000, 3))
                : 0.0;

            $this->buffer->add(
                self::EVENT_TYPE,
                $this->buildEvent($job, $connection, $status, $exception, $wait, $runtime),
            );

            return true;
        } catch (Throwable) {
            // Telemetry must never surface an error into the host application.
            return false;
        }
    }

    /**
     * The event: the job's own fields as a scrubbed payload the server spreads
     * over the `jobs` columns, plus the correlating envelope keys. The trace id
     * is the one the job runs under — continued from the request that queued it
     * (US-041) — so a job is correlated to its originating request.
     *
     * @return array<string, mixed>
     */
    private function buildEvent(
        object $job,
        string $connection,
        string $status,
        ?Throwable $exception,
        float $wait,
        float $runtime,
    ): array {
        return [
            'timestamp' => now()->toIso8601String(),
            'trace_id' => TraceContext::traceId(),
            'request_id' => '',
            'user_id' => $this->currentUserId(),
            'payload' => $this->scrubber->scrub([
                'job_class' => $this->resolveName($job) ?? '',
                'queue' => $this->queueName($job),
                'connection' => $connection,
                'uuid' => $this->uuid($job) ?? '',
                'status' => $status,
                'attempts' => $this->attempts($job),
                'queue_wait_ms' => $wait,
                'runtime_ms' => $runtime,
                'exception' => $exception !== null ? (string) $exception : '',
                'payload' => $this->payload($job),
            ]),
        ];
    }

    /**
     * Whether a job is skipped entirely: the package's own work (a flush in
     * progress or one of the package's own jobs, so shipping never re-captures
     * itself), or a class on the configured ignore list.
     */
    private function shouldSkip(object $job): bool
    {
        if (Recursion::suppressed()) {
            return true;
        }

        $name = $this->resolveName($job);

        if ($name === null) {
            return false;
        }

        if (str_starts_with($name, 'Misakstvanu\\Prism\\')) {
            return true;
        }

        return in_array($name, $this->ignore, true);
    }

    /**
     * Queue wait in milliseconds: processing-start (now) minus the dispatch time
     * carried on the payload. Prefers the high-precision stamp the provider adds,
     * falling back to the framework's whole-second `createdAt`. Clamped at zero so
     * clock skew never reports a negative wait; zero when no dispatch time is known.
     */
    private function queueWaitMs(object $job): float
    {
        $payload = $this->payload($job);
        $queuedMicros = null;

        $stamp = $payload[self::QUEUED_AT_KEY] ?? null;

        if (is_int($stamp) || is_float($stamp)) {
            $queuedMicros = (float) $stamp;
        } elseif (isset($payload['createdAt']) && is_numeric($payload['createdAt'])) {
            $queuedMicros = (float) $payload['createdAt'] * 1_000_000;
        }

        if ($queuedMicros === null) {
            return 0.0;
        }

        return max(0.0, round(($this->nowMicros() - $queuedMicros) / 1000, 3));
    }

    /** Current time in microseconds, honouring Carbon's test clock. */
    private function nowMicros(): float
    {
        return (float) now()->getPreciseTimestamp(6);
    }

    /** The per-job state key: the job UUID, or a fixed slot when it has none. */
    private function key(object $job): string
    {
        return $this->uuid($job) ?? 'prism.current-job';
    }

    /** The resolved job class name, or null when it cannot be determined. */
    private function resolveName(object $job): ?string
    {
        if (! method_exists($job, 'resolveName')) {
            return null;
        }

        $name = $job->resolveName();

        return is_string($name) ? $name : null;
    }

    /** The job UUID, or null when it has none. */
    private function uuid(object $job): ?string
    {
        if (! method_exists($job, 'uuid')) {
            return null;
        }

        $uuid = $job->uuid();

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /** The queue the job ran on, defaulting to `default` when unnamed. */
    private function queueName(object $job): string
    {
        if (! method_exists($job, 'getQueue')) {
            return 'default';
        }

        $queue = $job->getQueue();

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }

    /** The current attempt number, or 1 when it cannot be determined. */
    private function attempts(object $job): int
    {
        if (! method_exists($job, 'attempts')) {
            return 1;
        }

        $attempts = $job->attempts();

        return is_int($attempts) && $attempts > 0 ? $attempts : 1;
    }

    /**
     * The raw job payload, or an empty array when it cannot be read.
     *
     * @return array<string, mixed>
     */
    private function payload(object $job): array
    {
        if (! method_exists($job, 'payload')) {
            return [];
        }

        $payload = $job->payload();

        return is_array($payload) ? $payload : [];
    }

    /** The authenticated user id, or null with no bound guard or no user. */
    private function currentUserId(): ?string
    {
        if (! $this->container->bound('auth')) {
            return null;
        }

        $auth = $this->container->make('auth');

        if (! is_object($auth) || ! method_exists($auth, 'id')) {
            return null;
        }

        /** @var mixed $id */
        $id = $auth->id();

        return is_scalar($id) ? (string) $id : null;
    }
}
