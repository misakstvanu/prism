<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Otel;

use Illuminate\Contracts\Config\Repository;
use Misakstvanu\Prism\Console\CheckCommand;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RejectRules;

/**
 * Which signals the OpenTelemetry span lane owns, and therefore which of the
 * capture engine's records are superseded by it (US-018).
 *
 * Both engines watch a query and an outgoing HTTP call. The capture engine
 * reports each as a flat record on completion; the SDK reports each as a span
 * carrying a real parent, which is the whole reason the span lane exists. Left
 * alone, one query would land as a `queries` row *and* a `db` span while one
 * outgoing call landed as two `http` spans — the same work counted twice, on
 * the screen that exists to show what a request did.
 *
 * So one of the two has to give way, and it is the record. Three rules follow,
 * and the reasoning behind each is the part worth keeping:
 *
 *   - **The supersession is decided here and read in two places** — by
 *     {@see PrismIngest}, which drops the superseded record, and by
 *     {@see PrismSpanProcessor}, which fills the gap that leaves by emitting
 *     the `query` event itself. A rule those two could answer differently is a
 *     signal stored twice or not at all, so it is answered once.
 *
 *   - **A record is dropped only when the span that replaces it is really
 *     being produced.** `prism.otel.enabled` is the host's switch, but it is
 *     not the whole answer: a host that has published `config/opentelemetry.php`
 *     gets no processor from Prism at all (US-014's hands-off rule), and if it
 *     has not added {@see PrismSpanProcessor} to `traces.processors` itself
 *     then dropping the engine's query records would empty the Queries screen
 *     with nothing anywhere saying why. So {@see registered()} is part of the
 *     condition, not a nicety.
 *
 *   - **The cache lane is NOT superseded.** OpenTelemetry emits no cache span
 *     at all — upstream's cache instrumentation only calls `addEvent()`, and a
 *     span *event* has no Prism column to land in — so the engine's
 *     `cache-event` record is the only producer there is, and US-015 parents it
 *     into the tree instead. See {@see PrismSpanProcessor::type()}.
 *
 * The drop deliberately happens in Prism's own ingest rather than through one
 * of the engine's `reject*` callbacks. Its query, cache and outgoing-request
 * sensors increment the execution's own counter *inside* the resolver that
 * builds the record, and a reject callback returns before that runs — so
 * rejecting a query upstream does not merely drop the record, it also reports
 * a request that ran forty queries as having run none. {@see RejectRules} is
 * where losing the counter is the point (an ignored signal did not happen as
 * far as the workspace is concerned); this is where it is not.
 */
final class SpanLane
{
    /**
     * The capture engine's record types the span lane replaces, in both
     * spellings — the sensors emit hyphenated `t` values while every other
     * layer here names signals with underscores.
     *
     * @var list<string>
     */
    private const SUPERSEDED = ['query', 'outgoing_request', 'outgoing-request'];

    public function __construct(
        private readonly Repository $config,
        private readonly SpanLineage $lineage,
    ) {}

    /**
     * Whether the span lane, rather than the capture engine, is the producer of
     * this record's signal — i.e. whether the record is a duplicate to be
     * dropped.
     *
     * Three conditions, and each one closes a way of losing a signal outright:
     * the host has not switched the lane off, Prism's processor is on the
     * tracer this process built ({@see registered()}), and there is an
     * OpenTelemetry trace open right now ({@see live()}). Only when all three
     * hold is a span certain to have been produced in place of the record.
     */
    public function supersedes(string $recordType): bool
    {
        return in_array($recordType, self::SUPERSEDED, true)
            && $this->enabled()
            && $this->registered()
            && $this->live();
    }

    /**
     * Whether an OpenTelemetry trace is open for this execution — the runtime
     * half of the question {@see registered()} answers from config.
     *
     * Configuration says a span *would* be captured; this says one *is* being
     * captured. The two come apart in exactly the places where dropping a
     * record would lose the signal for good: a console command (the console
     * instrumentation is off, so the engine's command sensor owns commands and
     * no trace is ever opened), a query run before the request span opens, and
     * any process where the SDK is present but nothing started a trace. In all
     * of them the engine's record is the only producer there is, so it is kept
     * — and there is no duplicate to keep it from, because no span exists.
     */
    public function live(): bool
    {
        return $this->lineage->traceId() !== null;
    }

    /**
     * Whether {@see PrismSpanProcessor} is installed on the tracer this process
     * built — the one fact that says whether any span will arrive at all.
     *
     * Read off the config the tracer was built from rather than off the tracer,
     * because upstream exposes no way to enumerate a `TracerProvider`'s
     * processors. {@see CheckCommand} reports the same verdict, from here, so
     * the console line an operator reads and the decision the pipeline makes
     * cannot disagree.
     */
    public function registered(): bool
    {
        if (! class_exists(PrismSpanProcessor::class)) {
            return false;
        }

        $processors = $this->config->get('opentelemetry.traces.processors', []);

        return is_array($processors) && in_array(PrismSpanProcessor::class, $processors, true);
    }

    /** The host's own switch for the whole span lane. */
    public function enabled(): bool
    {
        return (bool) $this->config->get('prism.otel.enabled', true);
    }

    /**
     * Whether a query ran at or beyond `prism.query.slow_threshold_ms`.
     *
     * The marker rides the `query` event's payload and the *server's* sampler
     * reads it: a slow query, and the trace around it, is kept whatever the
     * workspace's per-signal rules say. A non-positive threshold marks nothing,
     * which is how the feature is switched off.
     */
    public function isSlowQuery(float $durationMs): bool
    {
        $threshold = $this->config->get('prism.query.slow_threshold_ms', 100);
        $threshold = is_numeric($threshold) ? (float) $threshold : 100.0;

        return $threshold > 0.0 && $durationMs >= $threshold;
    }
}
