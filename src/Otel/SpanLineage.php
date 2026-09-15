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
 * Where a span sits — trace, parent span, offset into the trace (US-015). The OTel lane exists
 * for **nesting**, which the capture engine's records cannot express: Nightwatch's cache, query
 * and outgoing sensors fire on *completion* only, yielding flat siblings sharing a trace id,
 * where an OTel span carries a parent span id by construction. {@see current()} is the active
 * span, read as a signal is recorded, parenting a signal OTel emits no span for (a cache event,
 * {@see stampSpan()}) into the instrumented tree. {@see originNanos()} is where a trace began:
 * `offset_ms` counts milliseconds from the *front of the trace*, one origin per waterfall, while
 * OTel spans carry absolute epoch timestamps — hence {@see observe()} from `onStart`, the only
 * hook before a child's own start, where a registry built at `onEnd` would learn the root's
 * start *after* the first child had needed it. **An emitted `span_id` must equal the id its
 * children name in `parent_span_id`**, or tree assembly silently drops those children to roots
 * and the waterfall goes flat with nothing reporting a problem — free for an instrumented span
 * (both ids off the SDK's own span context), and why {@see newSpanId()} mints a stamped one
 * here, not at each call site. Every method is total and swallowing: a throw would cost the host
 * the request it was describing, a missing lineage only a flatter waterfall.
 */
final class SpanLineage
{
    /**
     * How many traces' origins to remember at once. A queue worker or Octane process handles
     * one trace after another forever, so this map must be bounded or it leaks; only read
     * while the trace is still open, so the cap covers concurrent traces in one process, not
     * history — sixteen is generous for a one-execution-at-a-time runtime and cheap
     * regardless (an int per entry).
     */
    private const MAX_TRACES = 16;

    /** @var array<string, int> Trace id → the epoch nanoseconds its earliest span started at. */
    private array $origins = [];

    /**
     * Note where a trace begins, keeping the *earliest* start seen, not the first reported:
     * upstream's HTTP server instrumentation opens the request span then back-dates a
     * separate `app bootstrap` span to the same instant, as a later one may.
     */
    public function observe(string $traceId, int $startEpochNanos): void
    {
        if ($traceId === '' || $startEpochNanos <= 0) {
            return;
        }

        // The trace this process is now in, published where a signal recorded *after*
        // every span ended can still read it, and where Laravel carries it across a queue
        // boundary ({@see TraceContext::adopt()}). From here, not the execution's end:
        // `onStart` is the first moment the id exists at all.
        TraceContext::adopt($traceId);

        $known = $this->origins[$traceId] ?? null;

        if ($known !== null) {
            if ($startEpochNanos < $known) {
                $this->origins[$traceId] = $startEpochNanos;
            }

            return;
        }

        $this->origins[$traceId] = $startEpochNanos;

        // Plain FIFO eviction: the trace least recently opened is least likely to still
        // be laying out bars. By key rather than `array_shift`, which walks the whole
        // array to reindex integer keys it will never find here.
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
     * The active span's trace and span ids, or null when nothing is active — a console
     * command with no instrumentation, a signal raised outside any request, an SDK switched
     * off (whose current span is the API's invalid non-recording one).
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
     * The trace this execution belongs to, or null when OpenTelemetry has nothing to say about
     * it (US-017): what {@see PrismIngest} rewrites every capture-engine record's `trace_id`
     * to, deliberately wider than {@see current()} because the engine writes its `request`
     * record from `terminate()`, long after upstream's middleware ended the request span and
     * detached its scope — an active-span-only answer would leave the one row every detail
     * screen is keyed on carrying a different id from all of its children. A seam rather than
     * a static call at the ingest so a caller can be built without one: {@see PrismIngest}
     * takes a nullable lineage, and with none a record keeps the id it arrived with.
     */
    public function traceId(): ?string
    {
        return TraceContext::otelTraceId();
    }

    /**
     * Give a Prism span event with no identity of its own one and hang it off whatever OTel span
     * is active — the span lane's non-OTel half. The engine reports a cache operation as a
     * record, not a span (upstream's own cache instrumentation only calls `addEvent()`, and a
     * span *event* has no Prism column to land in), so the `--color-span-cache` lane is fed by
     * {@see RecordTranslator}'s translation of that record and joins the instrumented tree here.
     * A fresh `span_id` is minted always, even with nothing active: blank-id spans are
     * indistinguishable and the server reads them as a pile of roots. **The event also adopts
     * the active span's `trace_id`** — parenting only means anything inside one trace, so under
     * the engine's own trace id the row names a parent not in its trace and tree assembly drops
     * it to a root (US-017 generalises this to every record; here the minimum that makes the
     * parent real). `offset_ms` is measured back from now: the record is written the moment the
     * operation completes, so its start is `now - duration` from the trace's front.
     *
     * @param  array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}  $event
     * @return array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}
     */
    public function stampSpan(array $event): array
    {
        $payload = $event['payload'];

        // A producer that already named itself keeps its identity — nothing may re-key
        // a span whose children are already pointing at it.
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
     * Forget every remembered origin. Only for a process shutting the lane down, or a
     * long-lived runtime resetting between executions.
     */
    public function reset(): void
    {
        $this->origins = [];
    }

    /**
     * Milliseconds from the front of a trace to the start of an operation finishing right
     * now, or null when the trace's origin is unknown — the caller then leaves the column
     * at its default rather than inventing a zero drawing the bar at the waterfall front.
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
