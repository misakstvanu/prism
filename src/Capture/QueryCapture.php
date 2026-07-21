<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Capture;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;

/**
 * Turns every executed database query into a buffered `query` telemetry event
 * (US-045).
 *
 * {@see PrismServiceProvider::registerQueryCapture()} listens for Laravel's
 * {@see QueryExecuted} event and hands each one here, and binds this as a
 * container singleton so the listener always feeds the same buffer. It is a
 * separate listener from the request query-counter (US-043), which only totals
 * queries for the enclosing request event — this one records each query in full.
 *
 * The recorded payload mirrors the server's `queries` columns (US-006): the SQL,
 * the query bindings, the connection name and the execution time. The trace id
 * rides the event envelope so each query is correlated to its enclosing request
 * or job (AC3), and the authenticated user id is carried for attribution.
 *
 * Bindings are run through the {@see Scrubber} before buffering, so a credential
 * passed as a named binding is redacted at the source and never leaves the
 * process (AC5). Positional bindings have no key to match, matching the
 * scrubber's by-key contract everywhere else.
 *
 * A query slower than `prism.query.slow_threshold_ms` is marked `slow` in its
 * payload. The server's sampler reads that marker and keeps the query — and its
 * whole trace — regardless of any configured sampling rate (US-031/US-045), so a
 * slow query on an otherwise-sampled-out request is never lost.
 *
 * The package's own queries — those run while it flushes a batch — are never
 * captured: {@see capture()} short-circuits under {@see Recursion::suppressed()},
 * so shipping telemetry cannot generate the telemetry the next flush ships.
 */
final class QueryCapture
{
    /** The telemetry signal name (US-026); the server routes it to `queries`. */
    private const EVENT_TYPE = 'query';

    /**
     * @param  float  $slowThresholdMs  Queries at or above this many milliseconds
     *                                  are marked slow for always-keep. Zero (or
     *                                  below) marks nothing slow.
     */
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Scrubber $scrubber,
        private readonly Container $container,
        private readonly float $slowThresholdMs,
    ) {}

    /**
     * Record one executed query into the buffer, unless it is the package's own
     * work (a query run while flushing).
     */
    public function capture(QueryExecuted $event): void
    {
        if (Recursion::suppressed()) {
            return;
        }

        $this->buffer->add(self::EVENT_TYPE, $this->buildEvent($event));
    }

    /**
     * Build the event: the query's own fields as a scrubbed payload, plus the
     * correlating envelope keys (trace id, user id, timestamp) the server reads
     * off the top of the event (US-027).
     *
     * @return array<string, mixed>
     */
    private function buildEvent(QueryExecuted $event): array
    {
        $duration = round((float) ($event->time ?? 0.0), 3);

        $payload = $this->scrubber->scrub([
            'sql' => $event->sql,
            'bindings' => $event->bindings,
            'connection' => $event->connectionName,
            'duration_ms' => $duration,
            'slow' => $this->isSlow($duration) ? 1 : 0,
        ]);

        return [
            'timestamp' => now()->toIso8601String(),
            'trace_id' => TraceContext::traceId(),
            'user_id' => $this->currentUserId(),
            'payload' => $payload,
        ];
    }

    /**
     * Whether the query ran at or beyond the slow threshold, which marks it for
     * always-keep on the server regardless of sampling. A non-positive threshold
     * disables the marker entirely.
     */
    private function isSlow(float $durationMs): bool
    {
        return $this->slowThresholdMs > 0.0 && $durationMs >= $this->slowThresholdMs;
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
