<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Misakstvanu\Prism\Otel\SpanLineage;
use OpenTelemetry\API\Trace\Span;
use Throwable;

/**
 * Trace context (US-041, reduced to the OpenTelemetry context by US-020).
 *
 * Every signal a single execution produces — its requests, exceptions, logs,
 * queries, spans and the jobs it dispatches — must share one identifier so the
 * console can correlate them into a single trace. This is the one place that
 * identifier is read from.
 *
 * **This class no longer propagates anything.** Until US-020 it carried a trace
 * across every boundary itself, over a bespoke `X-Prism-Trace-Id` header on an
 * incoming request and an outgoing HTTP call, and a `prism_trace_id` key in a
 * queued job's payload. All three are gone, and what replaced them is the W3C
 * standard the span lane already speaks:
 *
 *   - an **incoming `traceparent`** continues the upstream trace, because
 *     keepsuit's HTTP server middleware makes the request span that context's
 *     child;
 *   - an **outgoing call** through Laravel's HTTP client carries `traceparent`
 *     out, from the same lane's Guzzle middleware;
 *   - a **dispatched job** carries `traceparent` in its payload and runs under a
 *     CONSUMER span parented to the PRODUCER span that queued it.
 *
 * That is a straight upgrade rather than a rename: `X-Prism-Trace-Id` was a
 * header only a Prism client understood, so a monitored Laravel app calling a Go
 * service produced two traces. `traceparent` is spoken by every APM and every
 * language SDK, so it produces one. See the package `UPGRADING.md`, which
 * records the removal for anyone who was stamping the old header by hand.
 *
 * Every method is static and side-effect free (beyond the stored id) so a
 * listener can read the current trace on a hot path — the same stance as
 * {@see Recursion}. The first answer below costs a couple of array lookups in
 * the SDK's own context storage and reaches no container at all, which is the
 * one that answers while a request is being handled; the second resolves a
 * facade the framework has already cached. A long-lived runtime (Octane, a
 * queue worker) resets it between runs so one execution's trace never bleeds
 * into the next — {@see reset()} from the provider's Octane listener and
 * {@see start()} from its `JobProcessing` one. A plain FPM request needs
 * neither: the process is fresh, so the lazy generation in {@see traceId()} is
 * the whole of the fallback (US-021 deleted the middleware that used to open it
 * eagerly, there being nothing left in a request that opens a trace of Prism's
 * own).
 *
 * **Since US-017 OpenTelemetry is the source of truth, and this class is where
 * the two engines are made to agree.** Two producers name a trace — the OTel SDK
 * (a 32-hex W3C id) and the capture engine (a UUID of its own) — and a console
 * that correlates a request with its logs, queries, spans and exceptions by
 * `trace_id` shows a third of a trace if they disagree. OTel wins because its id
 * is the one that travels, so {@see traceId()} answers, in order:
 *
 *   1. **the active span's trace**, which is the live truth while a request or
 *      job is being handled;
 *   2. **the trace in Laravel's {@see Context}**, published by
 *      {@see adopt()} the moment a trace opens. That covers the two
 *      moments the first answer cannot: a signal recorded after the request
 *      span has ended (the capture engine writes its `request` record from
 *      `terminate()`, by which time upstream's middleware has already detached
 *      the scope in its own `finally`) and a signal recorded in a worker, since
 *      Laravel serialises the context into a queued job's payload and hydrates
 *      it on the other side;
 *   3. **an id of its own**, for a host with the SDK switched off entirely —
 *      nothing is ever left unkeyed. That id names one process's execution and
 *      no longer leaves it: with the SDK off there is nothing to propagate,
 *      which is the cost of turning the span lane off and is documented as
 *      such.
 *
 * {@see otelTraceId()} is the first two answers without the third, which is
 * what a caller holding a trace id already needs: a capture-engine record is
 * rewritten only when there is something to rewrite it *to*, and keeps the
 * engine's own id otherwise. See {@see SpanLineage} for the span half.
 */
final class TraceContext
{
    /**
     * Key under which the current trace id sits in Laravel's {@see Context}
     * (US-017).
     *
     * Namespaced, because the context repository is the host's as much as
     * ours. Laravel dehydrates it into every queued job's payload and hydrates
     * it in the worker — flushing first, so the value a job runs under is the
     * one that dispatched it and never the previous job's.
     */
    public const CONTEXT_KEY = 'prism.trace_id';

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
     * Begin a trace context for an execution — today a queue worker picking up
     * its next job, which is the one place a long-lived process starts one.
     *
     * It takes no incoming id any more (US-020): continuing an upstream trace is
     * `traceparent`'s job now, and that context is read straight off the active
     * span rather than copied into this class. What is left is the fallback a
     * host with the OpenTelemetry SDK switched off runs on — a fresh id per
     * execution, so its signals still correlate with each other.
     *
     * The trace-start reference is (re)set here so elapsed time is measured from
     * the front of this execution rather than from process start.
     */
    public static function start(): void
    {
        self::$traceId = self::generateId();
        self::$startedAt = self::now();
    }

    /**
     * The current trace id: OpenTelemetry's when there is one, otherwise this
     * class's own, generated lazily on first read.
     *
     * Lazy generation covers a read from a context that never called
     * {@see start()} — a bare script or a console command still gets a traceable
     * id — and, since US-017, a host whose OTel SDK is switched off, where the
     * class behaves exactly as it always did.
     */
    public static function traceId(): string
    {
        return self::otelTraceId() ?? (self::$traceId ??= self::generateId());
    }

    /**
     * The OpenTelemetry trace this execution belongs to, or null when there is
     * none to speak of.
     *
     * Null is a real answer and the caller has to respect it: a capture-engine
     * record already names a trace of its own, and rewriting it to an invented
     * id would unkey it rather than correlate it.
     */
    public static function otelTraceId(): ?string
    {
        return self::activeTraceId() ?? self::publishedTraceId();
    }

    /**
     * Publish the trace a span has just opened, so every signal recorded under
     * it agrees — including the ones recorded after the span has ended, and the
     * ones recorded in another process.
     *
     * Called from {@see SpanLineage::observe()}, i.e. from the span processor's
     * `onStart`, which is the first moment in a request or job that a trace id
     * exists at all. Writing it only when it changes keeps the cost off the
     * per-span hot path; a throw is swallowed because a context repository is
     * not something a lane may cost the host a request over.
     */
    public static function adopt(string $traceId): void
    {
        if (! self::isTraceId($traceId)) {
            return;
        }

        try {
            if (Context::get(self::CONTEXT_KEY) === $traceId) {
                return;
            }

            Context::add(self::CONTEXT_KEY, $traceId);
        } catch (Throwable) {
            // A host without a container (a bare script driving the SDK) simply
            // does without the second answer; the active span is still the
            // first one.
        }
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

    /**
     * The trace of whatever OpenTelemetry span is active right now, or null.
     *
     * An SDK that is switched off answers with the API's invalid non-recording
     * span, whose context reports itself invalid — so "the SDK is off" and
     * "nothing is active" are one answer here rather than two.
     */
    private static function activeTraceId(): ?string
    {
        try {
            $context = Span::getCurrent()->getContext();

            if (! $context->isValid()) {
                return null;
            }

            $traceId = $context->getTraceId();
        } catch (Throwable) {
            return null;
        }

        return self::isTraceId($traceId) ? $traceId : null;
    }

    /**
     * The trace {@see adopt()} last published into Laravel's context, or null.
     *
     * Validated rather than trusted: the repository is shared with the host and
     * survives a serialisation round trip through a queue payload, so what
     * comes back is checked for the shape a trace id has before a row is keyed
     * on it.
     */
    private static function publishedTraceId(): ?string
    {
        try {
            $traceId = Context::get(self::CONTEXT_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_string($traceId) && self::isTraceId($traceId) ? $traceId : null;
    }

    /**
     * Whether a string is a usable W3C trace id: 32 lowercase hex characters,
     * and not the all-zero id the specification reserves for "invalid".
     */
    private static function isTraceId(string $traceId): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $traceId) === 1
            && $traceId !== '00000000000000000000000000000000';
    }

    /** Current wall-clock time in Unix seconds with microsecond precision. */
    private static function now(): float
    {
        return microtime(true);
    }
}
