<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Otel;

use Keepsuit\LaravelOpenTelemetry\TailSampling\TailSamplingProcessor;
use Misakstvanu\Prism\Flush\Flusher;
use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Throwable;

/**
 * Drives the OpenTelemetry `TracerProvider`'s own `forceFlush()` immediately
 * before Prism drains its buffer (US-019).
 *
 * **The ordering is the whole class.** With tail sampling on, upstream's
 * {@see TailSamplingProcessor} holds every span of a trace until the trace's
 * root ends or `decision_wait` (5000ms by default) elapses — longer than most
 * requests live. A flush that drained Prism's buffer first would ship the batch
 * this execution produced and leave whatever was still held to arrive in some
 * later execution's batch, under a trace nothing else in that batch belongs to.
 * Force-flushing first turns that "later, somewhere" into "now, here".
 *
 * Two facts about how it composes, both of which look like details and are not:
 *
 *   - **{@see PrismSpanProcessor} is a top-level processor, NOT downstream of
 *     the tail-sampling one**, so nothing tail sampling holds is held from
 *     Prism. That is deliberate and it is what makes this class safe rather
 *     than load-bearing: upstream's `TailSamplingProcessor::onStart()` is a
 *     no-op that forwards nothing, and Prism's processor needs `onStart` — it
 *     is where a trace's origin is remembered ({@see SpanLineage::observe()})
 *     and where the OTel trace id is published for every other signal to adopt
 *     (US-017). Composing Prism downstream of the tail sampler would silently
 *     cost `offset_ms` its zero point *and* re-key nothing, so the two rules
 *     have to hold together: Prism sits beside the decision, and the decision
 *     is force-flushed so upstream's own buffers never outlive the execution
 *     that filled them.
 *
 *   - **It runs before {@see Flusher}'s "is the buffer empty" early return**,
 *     not after it. An execution whose only telemetry is a held span would
 *     otherwise return on an empty buffer and never force anything out at all.
 *
 * Gated on {@see SpanLane}, which is the package's one answer to "is the span
 * lane really producing spans here": a host with no OpenTelemetry, with the
 * lane switched off, or with a published `config/opentelemetry.php` that never
 * added Prism's processor gets no `Globals::` lookup at all — which matters,
 * because that lookup can initialise the SDK's global instance in a process
 * that had deliberately never built one.
 *
 * Failure is swallowed for {@see Flusher}'s reason: a telemetry flush must
 * never propagate an error into the host it monitors, and a tracer that will
 * not flush costs late spans, not a request.
 */
final class SpanFlush
{
    public function __construct(private readonly SpanLane $lane) {}

    /**
     * Force the tracer provider to release everything it is holding.
     *
     * Answers whether a flush was actually driven, which is what a test asserts
     * on — false means the lane is off or upstream has no SDK provider
     * registered, both of which are ordinary states rather than failures.
     */
    public function flush(): bool
    {
        if (! $this->lane->enabled() || ! $this->lane->registered()) {
            return false;
        }

        try {
            $provider = Globals::tracerProvider();

            // The API interface promises only `getTracer()`; `forceFlush()` is
            // the SDK's. A Noop provider — the shape upstream registers when
            // the SDK is disabled — is not one, so this is also the "nothing
            // was ever built" answer.
            if (! $provider instanceof TracerProviderInterface) {
                return false;
            }

            return $provider->forceFlush();
        } catch (Throwable) {
            return false;
        }
    }
}
