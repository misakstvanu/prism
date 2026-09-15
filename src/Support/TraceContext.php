<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Misakstvanu\Prism\Otel\SpanLineage;
use OpenTelemetry\API\Trace\Span;
use Throwable;

/**
 * Trace context (US-041, reduced to the OpenTelemetry context by US-020): the one place the identifier every
 * signal of one execution shares is read from, so the console can correlate them into one trace. Static and
 * side-effect free beyond the stored id, for a hot-path read ({@see Recursion}'s stance): answer 1 below is
 * array lookups in the SDK's own context storage reaching no container at all, answer 2 a cached facade.
 *
 * **This class no longer propagates anything.** Until US-020 it carried a trace across every boundary itself
 * — a bespoke `X-Prism-Trace-Id` header on an incoming request and an outgoing HTTP call, a `prism_trace_id`
 * key in a queued job's payload — all three replaced by the W3C standard the span lane already speaks: an
 * **incoming `traceparent`** continues the upstream trace (keepsuit's HTTP server middleware makes the
 * request span that context's child), an **outgoing call** through Laravel's HTTP client carries it out from
 * the same lane's Guzzle middleware, and a **dispatched job** carries it in its payload and runs under a
 * CONSUMER span parented to the PRODUCER span that queued it. An upgrade, not a rename: `X-Prism-Trace-Id`
 * was understood only by a Prism client, so a monitored Laravel app calling a Go service produced two
 * traces; `traceparent`, spoken by every APM and language SDK, produces one. The package `UPGRADING.md`
 * records the removal for anyone stamping the old header by hand.
 *
 * **Since US-017 OpenTelemetry is the source of truth, and this class is where the two engines are made to
 * agree.** The OTel SDK names a trace with a 32-hex W3C id and the capture engine with a UUID of its own,
 * and a console correlating a request with its logs, queries, spans and exceptions by `trace_id` shows a
 * third of a trace if they disagree. OTel wins because its id is the one that travels, so {@see traceId()}
 * answers, in order:
 *
 *   1. **the active span's trace**, live truth while a request or job runs;
 *   2. **the trace in Laravel's {@see Context}**, published by {@see adopt()} the moment a trace opens,
 *      covering the two moments answer 1 cannot: a signal recorded after the request span ended (the capture
 *      engine writes its `request` record from `terminate()`, by which time upstream's middleware has
 *      already detached the scope in its own `finally`), and one recorded in a worker, the queue payload
 *      carrying it there ({@see CONTEXT_KEY});
 *   3. **an id of its own**, for a host with the SDK switched off entirely — nothing is ever left unkeyed.
 *      It names one process's execution and no longer leaves it: with the SDK off there is nothing to
 *      propagate, the documented cost of turning the span lane off.
 *
 * {@see otelTraceId()} is the first two answers without the third — what a caller already holding a trace id
 * of its own needs: a capture-engine record is rewritten only when there is something to rewrite it *to*,
 * and keeps the engine's own id otherwise. A long-lived runtime (Octane, a queue worker) resets it between
 * runs so one execution's trace never bleeds into the next — {@see reset()} from the provider's Octane
 * listener, {@see start()} from its `JobProcessing` one; a plain FPM request needs neither, the process being
 * fresh, so the lazy generation in {@see traceId()} is the whole fallback (US-021 deleted the middleware that
 * opened it eagerly; nothing in a request opens a trace of Prism's own any more). See {@see SpanLineage} for
 * the span half.
 */
final class TraceContext
{
    /**
     * Key under which the current trace id sits in Laravel's {@see Context} (US-017). Namespaced — the
     * context repository is the host's as much as ours. Laravel dehydrates it into every queued job's
     * payload and hydrates it in the worker, flushing first, so a job runs under the trace that dispatched
     * it and never the previous job's.
     */
    public const CONTEXT_KEY = 'prism.trace_id';

    /** The current trace id, or null before one has been established. */
    private static ?string $traceId = null;

    /**
     * Wall-clock reference (Unix seconds, microsecond precision) marking the start of the current
     * trace, or null before one is established. A span's `offset_ms` is the elapsed time from it
     * (US-046), so the waterfall lays every span out against a common origin.
     */
    private static ?float $startedAt = null;

    /**
     * Begin a trace context for an execution — today a queue worker picking up its next job, the one place
     * a long-lived process starts one. Takes no incoming id any more (US-020): continuing an upstream trace
     * is `traceparent`'s job, read straight off the active span rather than copied into this class, leaving
     * the fallback a host with the OpenTelemetry SDK switched off runs on — a fresh id per execution, so its
     * signals still correlate. The trace-start reference is (re)set here, so elapsed time is measured from
     * the front of this execution rather than from process start.
     */
    public static function start(): void
    {
        self::$traceId = self::generateId();
        self::$startedAt = self::now();
    }

    /**
     * The current trace id: OpenTelemetry's when there is one, otherwise this class's own, generated lazily
     * on first read. Lazy generation covers a read from a context that never called {@see start()} — a bare
     * script or a console command still gets a traceable id — and, since US-017, a host whose OTel SDK is
     * switched off, where the class behaves as it always did.
     */
    public static function traceId(): string
    {
        return self::otelTraceId() ?? (self::$traceId ??= self::generateId());
    }

    /**
     * The OpenTelemetry trace this execution belongs to, or null when there is none. Null is a real
     * answer the caller has to respect: a capture-engine record already names a trace of its own, and
     * rewriting it to an invented id would unkey it rather than correlate it.
     */
    public static function otelTraceId(): ?string
    {
        return self::activeTraceId() ?? self::publishedTraceId();
    }

    /**
     * Publish the trace a span has just opened, so every signal recorded under it agrees — including those
     * recorded after the span ended and those recorded in another process. Called from
     * {@see SpanLineage::observe()}, the span processor's `onStart`, the first moment in a request or job
     * that a trace id exists at all. Writing only on change keeps the cost off the per-span hot path; a
     * throw is swallowed because a context repository is not something a lane may cost the host a request
     * over.
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
            // A host without a container (a bare script driving the SDK) does without the
            // second answer; the active span is still the first.
        }
    }

    /**
     * Milliseconds elapsed since the current trace began, never negative — the `offset_ms` a span
     * records so the console can place it on the trace waterfall (US-046). The reference initialises
     * lazily on first read, so a span emitted from a context that never called {@see start()} (a bare
     * script, a console command) measures from that first read.
     */
    public static function elapsedMs(): float
    {
        $startedAt = self::$startedAt ??= self::now();

        return max(0.0, (self::now() - $startedAt) * 1000);
    }

    /**
     * Clear the trace. Only for a long-lived runtime (Octane, a queue worker) resetting between
     * requests or jobs, so one execution's trace can never leak into the next.
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
     * The trace of whatever OpenTelemetry span is active right now, or null. An SDK that is switched
     * off answers with the API's invalid non-recording span, whose context reports itself invalid — so
     * "the SDK is off" and "nothing is active" are one answer here, not two.
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
     * The trace {@see adopt()} last published into Laravel's context, or null. Validated rather than
     * trusted: the repository is shared with the host and survives a serialisation round trip through a
     * queue payload, so what comes back is checked for a trace id's shape before a row is keyed on it.
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
     * Whether a string is a usable W3C trace id: 32 lowercase hex characters, and not the all-zero id
     * the specification reserves for "invalid".
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
