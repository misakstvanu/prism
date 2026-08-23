<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Otel;

use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RecordTranslator;
use Misakstvanu\Prism\Support\TraceContext;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\Span;
use Throwable;

/**
 * Where a span sits: which trace it belongs to, which span it hangs off, and
 * how far into the trace it started (US-015).
 *
 * The OpenTelemetry lane exists for exactly one thing the capture engine's
 * records cannot express — **nesting**. Nightwatch's cache, query and outgoing
 * sensors fire on *completion* only, so no decorator over them can ever produce
 * anything but flat siblings sharing a trace id, while an OTel span carries a
 * parent span id by construction. This class holds the two facts that turn that
 * construction into the two columns Prism's `spans` table has always had:
 *
 *   - **{@see current()} — the active span**, read at the moment a signal is
 *     recorded. That is what parents a signal OTel does not emit a span for
 *     (a cache event; see {@see stampSpan()}) into the tree the instrumented
 *     spans built around it.
 *
 *   - **{@see originNanos()} — where a trace began.** Prism's `offset_ms` is
 *     milliseconds from the *front of the trace*, so every bar in a waterfall
 *     is laid out against one origin. OTel spans carry absolute epoch
 *     timestamps instead, so the origin has to be remembered: the processor
 *     calls {@see observe()} from `onStart`, which is the only hook that runs
 *     before a child's own start — a registry built at `onEnd` would learn the
 *     root's start *after* the first child had already needed it.
 *
 * **A span's emitted `span_id` must equal the id its children name in
 * `parent_span_id`**, or the server's tree assembly silently drops those
 * children to roots and the waterfall goes flat with nothing reporting a
 * problem. For an instrumented span that is free — both ids come from the SDK's
 * own span context. For a stamped one it is why {@see newSpanId()} mints the
 * id here rather than at each call site.
 *
 * Every method is total and swallowing: a span lane that throws would cost the
 * host the request it was describing, and a missing lineage costs a flatter
 * waterfall.
 */
final class SpanLineage
{
    /**
     * How many traces' origins to remember at once.
     *
     * A queue worker or an Octane process handles one trace after another
     * forever, so this map has to be bounded or it is a leak. It is only ever
     * read while the trace is still open, so the cap needs to cover concurrent
     * traces in one process rather than history — sixteen is generous for a
     * runtime that handles one execution at a time and cheap regardless (an
     * int per entry).
     */
    private const MAX_TRACES = 16;

    /**
     * Trace id → the epoch nanoseconds its earliest span started at.
     *
     * @var array<string, int>
     */
    private array $origins = [];

    /**
     * Note where a trace begins. Keeps the *earliest* start seen rather than
     * the first one reported: upstream's HTTP server instrumentation opens the
     * request span and then back-dates a separate `app bootstrap` span to the
     * same instant, and a later instrumentation is free to do the same.
     */
    public function observe(string $traceId, int $startEpochNanos): void
    {
        if ($traceId === '' || $startEpochNanos <= 0) {
            return;
        }

        // The trace this process is now in, published where a signal recorded
        // *after* every span has ended can still read it — and where Laravel
        // carries it across a queue boundary. See {@see TraceContext::adopt()};
        // `onStart` is the first moment the id exists at all, which is why it
        // is done from here rather than at the end of the execution.
        TraceContext::adopt($traceId);

        $known = $this->origins[$traceId] ?? null;

        if ($known !== null) {
            if ($startEpochNanos < $known) {
                $this->origins[$traceId] = $startEpochNanos;
            }

            return;
        }

        $this->origins[$traceId] = $startEpochNanos;

        // Oldest first: a plain FIFO eviction, because the trace least recently
        // opened is the one least likely to still be laying out bars. By key
        // rather than `array_shift`, which walks the whole array to reindex
        // integer keys it will never find here.
        while (count($this->origins) > self::MAX_TRACES) {
            unset($this->origins[array_key_first($this->origins)]);
        }
    }

    /** Where a trace began, or null if this process never saw it start. */
    public function originNanos(string $traceId): ?int
    {
        return $this->origins[$traceId] ?? null;
    }

    /**
     * The currently active span's trace and span ids, or null when nothing is
     * active — a console command with no instrumentation, a signal raised
     * outside any request, or an SDK that is switched off (whose current span
     * is the API's invalid non-recording one).
     *
     * @return array{trace_id: string, span_id: string}|null
     */
    public function current(): ?array
    {
        try {
            $context = Span::getCurrent()->getContext();

            if (! $context->isValid()) {
                return null;
            }

            return [
                'trace_id' => $context->getTraceId(),
                'span_id' => $context->getSpanId(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The trace this execution belongs to, or null when OpenTelemetry has
     * nothing to say about it (US-017).
     *
     * This is the answer {@see PrismIngest} rewrites every capture-engine
     * record's `trace_id` to, and it is deliberately wider than
     * {@see current()}: the engine writes its `request` record from
     * `terminate()`, long after upstream's middleware has ended the request
     * span and detached its scope, so an answer that could only read the active
     * span would leave the one row every detail screen is keyed on carrying a
     * different id from all of its children.
     *
     * A seam rather than a static call at the ingest so a caller can be built
     * without one — {@see PrismIngest} takes a nullable lineage, and with none
     * a record keeps whatever id it arrived with.
     */
    public function traceId(): ?string
    {
        return TraceContext::otelTraceId();
    }

    /**
     * Give a Prism span event that has no identity of its own one, and hang it
     * off whatever OTel span is active right now.
     *
     * This is the half of the span lane that is *not* an OTel span. The engine
     * reports a cache operation as a record rather than a span — upstream's own
     * cache instrumentation only calls `addEvent()`, and a span *event* has no
     * Prism column to land in — so the `--color-span-cache` lane is fed by
     * {@see RecordTranslator}'s translation of that record, and this is
     * where it joins the tree the instrumented spans built.
     *
     * Three things happen, and the middle one is easy to miss:
     *
     *   - The event gets a fresh `span_id`, always — even with nothing active.
     *     A span with a blank id is one every other blank-id span in the trace
     *     is indistinguishable from, which the server reads as a pile of roots.
     *   - **The event adopts the active span's `trace_id`.** Parenting only
     *     means anything inside one trace: left under the capture engine's own
     *     trace id the row would name a parent that is not in its trace, and the
     *     tree assembly would drop it to a root. (US-017 generalises this to
     *     every record; here it is the minimum that makes the parent real.)
     *   - `offset_ms` is measured back from now: the record is written the
     *     moment the operation completes, so its start is `now - duration`
     *     relative to the front of the trace.
     *
     * @param  array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}  $event
     * @return array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}
     */
    public function stampSpan(array $event): array
    {
        $payload = $event['payload'];

        // A producer that already named itself keeps its identity — nothing
        // else may re-key a span whose children are already pointing at it.
        if (($payload['span_id'] ?? null) !== null && $payload['span_id'] !== '') {
            return $event;
        }

        $payload['span_id'] = $this->newSpanId();

        $current = $this->current();

        if ($current !== null) {
            $payload['parent_span_id'] = $current['span_id'];
            $event['trace_id'] = $current['trace_id'];

            $offset = $this->offsetEndingNow($current['trace_id'], $payload['duration_ms'] ?? null);

            if ($offset !== null) {
                $payload['offset_ms'] = $offset;
            }
        }

        $event['payload'] = $payload;

        return $event;
    }

    /** A fresh 16-character hex span identifier, the shape every span id here takes. */
    public function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Forget every remembered origin. Only for a process shutting the lane down
     * or a long-lived runtime resetting between executions.
     */
    public function reset(): void
    {
        $this->origins = [];
    }

    /**
     * Milliseconds from the front of a trace to the start of an operation that
     * is finishing right now, or null when the trace's origin is unknown (so
     * the caller leaves the column at its default rather than inventing a zero
     * that would draw the bar at the front of the waterfall).
     */
    private function offsetEndingNow(string $traceId, mixed $durationMs): ?float
    {
        $origin = $this->originNanos($traceId);

        if ($origin === null) {
            return null;
        }

        try {
            $elapsedMs = (Clock::getDefault()->now() - $origin) / 1_000_000;
        } catch (Throwable) {
            return null;
        }

        $duration = is_int($durationMs) || is_float($durationMs) ? (float) $durationMs : 0.0;

        return round(max(0.0, $elapsedMs - $duration), 3);
    }
}
