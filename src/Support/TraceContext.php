<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use Illuminate\Support\Str;
use Misakstvanu\Prism\Http\Middleware\TraceRequests;
use Misakstvanu\Prism\PrismServiceProvider;

/**
 * Trace context (US-041).
 *
 * Every signal a single execution produces — its requests, exceptions, logs,
 * queries, spans and the jobs it dispatches — must share one identifier so the
 * console can correlate them into a single trace. This is the one place that
 * identifier lives: a capture listener (US-042+) reads {@see traceId()} when it
 * buffers an event, and the propagation wiring in
 * {@see PrismServiceProvider::registerCapture()} keeps that
 * id continuous across every boundary a trace crosses.
 *
 * A trace spans services and processes:
 *
 *   - It is established at the front of every request ({@see start()} from the
 *     {@see TraceRequests} middleware) and at
 *     the start of every queued job (the provider's `JobProcessing` listener).
 *   - An incoming `X-Prism-Trace-Id` header continues a trace begun upstream, so
 *     one trace can span several services (AC2).
 *   - Outgoing calls through Laravel's HTTP client carry the header on, so a
 *     downstream service continues the same trace (AC3).
 *   - A dispatched job carries the originating id in its payload, so a job runs
 *     under the trace that queued it — even in a separate worker process
 *     (AC4/AC5).
 *
 * Every method is static and side-effect free (beyond the stored id) so a
 * listener can read the current trace on a hot path with no container lookup —
 * the same stance as {@see Recursion}. A long-lived runtime (Octane, a queue
 * worker) resets it between runs so one execution's trace never bleeds into the
 * next.
 */
final class TraceContext
{
    /**
     * Header carrying the trace id across service boundaries — read off an
     * incoming request to continue an upstream trace, and stamped onto every
     * outgoing HTTP call to propagate the current one.
     */
    public const HEADER = 'X-Prism-Trace-Id';

    /**
     * Key under which the originating trace id rides in a queued job's payload,
     * so the job continues that trace when it runs in another process.
     */
    public const JOB_PAYLOAD_KEY = 'prism_trace_id';

    /** The current trace id, or null before one has been established. */
    private static ?string $traceId = null;

    /**
     * Wall-clock reference (Unix seconds, microsecond precision) marking the
     * start of the current trace, or null before one has been established. A
     * span records its `offset_ms` as the elapsed time from this reference
     * (US-046), so the waterfall can lay out every span against a common origin.
     */
    private static ?float $startedAt = null;

    /**
     * Begin a trace context for a request or job. A valid incoming id (an
     * upstream service's `X-Prism-Trace-Id` header, or a queued job's payload)
     * continues that trace; a missing or unusable one starts a fresh trace.
     *
     * The trace-start reference is (re)set here so span offsets are measured
     * from the front of this request or job rather than from process start.
     */
    public static function start(?string $traceId = null): void
    {
        self::$traceId = self::sanitize($traceId) ?? self::generateId();
        self::$startedAt = self::now();
    }

    /**
     * The current trace id, generating one lazily on first read. Lazy
     * generation covers a read from a context that never called {@see start()}
     * — an outgoing HTTP call from a bare script or console command still gets a
     * traceable id.
     */
    public static function traceId(): string
    {
        return self::$traceId ??= self::generateId();
    }

    /**
     * Milliseconds elapsed since the current trace began, never negative — the
     * `offset_ms` a span records so the console can place it on the trace
     * waterfall (US-046). The reference is initialised lazily on first read, so
     * a span emitted from a context that never called {@see start()} (a bare
     * script, a console command) simply measures from that first read.
     */
    public static function elapsedMs(): float
    {
        $startedAt = self::$startedAt ??= self::now();

        return max(0.0, (self::now() - $startedAt) * 1000);
    }

    /**
     * Clear the trace. Only for a long-lived runtime (Octane, a queue worker)
     * resetting between requests or jobs, so one execution's trace can never
     * leak into the next.
     */
    public static function reset(): void
    {
        self::$traceId = null;
        self::$startedAt = null;
    }

    /** A fresh, random trace identifier. */
    private static function generateId(): string
    {
        return (string) Str::uuid();
    }

    /** Current wall-clock time in Unix seconds with microsecond precision. */
    private static function now(): float
    {
        return microtime(true);
    }

    /**
     * Accept an incoming id only when it is a bounded, safe token — trimmed,
     * non-empty, at most 128 characters and restricted to an unambiguous
     * character set. Anything else (junk, an injection attempt) is rejected so
     * the caller starts a fresh trace instead of honouring it.
     */
    private static function sanitize(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $id = trim($id);

        if ($id === '' || strlen($id) > 128 || preg_match('/[^A-Za-z0-9._-]/', $id) === 1) {
            return null;
        }

        return $id;
    }
}
