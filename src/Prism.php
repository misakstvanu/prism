<?php

declare(strict_types=1);

namespace Misakstvanu\Prism;

use Closure;
use Illuminate\Container\Container;
use Keepsuit\LaravelOpenTelemetry\Tracer;
use Laravel\Nightwatch\Facades\Nightwatch;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Misakstvanu\Prism\Otel\SpanLane;
use Throwable;

/**
 * The client's public API surface — the handful of things an application calls
 * directly rather than having captured automatically.
 *
 * Everything here resolves through the container and is a safe no-op when the
 * client is inert (disabled or unconfigured), so a host can call it
 * unconditionally without guarding on whether Prism is set up: a build with
 * `PRISM_ENABLED=false` simply does nothing.
 *
 * **Neither method captures anything itself**: each hands the work to the
 * engine that owns the signal, so a manual report meets exactly the sensors,
 * redact callbacks and ignore lists an automatic one does. An exception goes to
 * the capture engine, a span to the span lane.
 */
final class Prism
{
    /**
     * Manually report a handled exception (US-042). The application caught it
     * and wants it in Prism anyway — a swallowed integration error, a
     * best-effort background task's failure.
     *
     * Delegates to the capture engine's own reporter (US-021), which is what
     * every *unhandled* exception already travels through: the engine's
     * exception sensor builds the record, `prism.scrub` rewrites it through the
     * redact callbacks and `prism.ignore.exceptions` drops it in Prism's
     * ingest, exactly as for an exception that escaped. `handled: true` is the
     * one thing this call says that the automatic path does not — it is the
     * flag that distinguishes an error the application dealt with from one that
     * got away, and it is also what keeps a handled report from re-rolling the
     * execution's sampling decision.
     *
     * A no-op when the client is inert: `ACTIVE` is bound only once capture is
     * wired (enabled + token), and without it there is nowhere to send this.
     */
    public static function captureException(Throwable $e): void
    {
        $app = Container::getInstance();

        if (! $app->bound(PrismServiceProvider::ACTIVE)) {
            return;
        }

        Nightwatch::report($e, handled: true);
    }

    /**
     * Manually instrument a block of code as a trace span (US-050). The closure
     * is timed and recorded as a span carrying its offset from the front of the
     * trace, its duration and its parent span, so arbitrary application work —
     * an expensive computation, a third-party SDK call no instrumentation sees
     * — appears on the trace waterfall.
     *
     * Since US-021 the span is a real OpenTelemetry span rather than one Prism
     * assembled from a stack of its own, which is the point of the span lane:
     * anything opened inside the closure (a query, an outgoing call, another
     * `Prism::span()`) nests beneath it by construction, and the trace it joins
     * is the one that crosses service and queue boundaries.
     * {@see PrismSpanProcessor} is what turns it into the Prism `span` event
     * the waterfall draws, so the lane's own rules — self-monitoring, scrubbing
     * and `prism.ignore.*` — apply to it unchanged.
     *
     * `$type` is one of the prototype's waterfall lanes (`ctrl`, `db`, `http`,
     * `mw`, `cache`, `resp`) and rides the span as an attribute the processor
     * reads back, so a manual span keeps the tint the caller asked for instead
     * of falling through to the default. `$extra` becomes span attributes,
     * which travel into the event's payload.
     *
     * A safe no-op when the lane is not live: the closure still runs and its
     * value is returned, it is simply not recorded — so a host can wrap code in
     * `Prism::span()` unconditionally, including one that has switched the
     * OpenTelemetry SDK off entirely.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  array<string, mixed>  $extra
     * @return TReturn
     */
    public static function span(string $name, Closure $callback, string $type = 'ctrl', array $extra = []): mixed
    {
        $tracer = self::tracer();

        if ($tracer === null || $name === '') {
            return $callback();
        }

        /** @var TReturn */
        return $tracer->newSpan($name)
            ->setAttributes([...$extra, PrismSpanProcessor::TYPE_ATTRIBUTE => $type])
            ->measure(static fn (): mixed => $callback());
    }

    /**
     * The span lane's tracer, or null when there is nothing to record onto.
     *
     * Three conditions, and each is a different way of not being live: the
     * client is inert, the host has switched the lane off with
     * `PRISM_OTEL_ENABLED=false`, or the SDK is not installed at all (the
     * package is a `require`, but a host may have replaced the provider). A
     * throw is answered the same way — a manual span is never worth costing the
     * caller their own work over.
     */
    private static function tracer(): ?Tracer
    {
        $app = Container::getInstance();

        if (! $app->bound(PrismServiceProvider::ACTIVE) || ! $app->bound(Tracer::class)) {
            return null;
        }

        try {
            if (! $app->make(SpanLane::class)->enabled()) {
                return null;
            }

            $tracer = $app->make(Tracer::class);
        } catch (Throwable) {
            return null;
        }

        return $tracer instanceof Tracer ? $tracer : null;
    }
}
