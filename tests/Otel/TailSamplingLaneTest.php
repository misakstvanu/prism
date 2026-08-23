<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\TailSampling\Rules\ErrorsRule;
use Keepsuit\LaravelOpenTelemetry\TailSampling\Rules\SlowTraceRule;
use Keepsuit\LaravelOpenTelemetry\TailSampling\TailSamplingProcessor;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Misakstvanu\Prism\Otel\SpanFlush;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Transport\Transport;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\SpanProcessor\MultiSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Tail sampling, in a host with all three providers registered in the order
 * `installed.json` forces (US-019).
 *
 * The rule this file is about is the client-side half of one the Prism server
 * already enforces: a slow or failing execution's telemetry is kept **whole**,
 * however aggressive sampling is. The server's half keeps a trace once it has
 * arrived; this half keeps it from being dropped before it is sent at all.
 *
 * Two properties, and the second is the one that is easy to break by tidying:
 *
 *   - **Tail sampling is really composed onto the tracer upstream built**, not
 *     merely written into config. The derived `opentelemetry.*` keys are read
 *     while upstream BOOTS and upstream boots before this package, so a key
 *     written at the wrong moment reads correctly and is consulted by nobody.
 *     Asserted off the built `TracerProvider` rather than off the config.
 *
 *   - **{@see PrismSpanProcessor} sits BESIDE the tail-sampling decision, never
 *     behind it**, so a span the decision is still holding is already in
 *     Prism's buffer. Upstream's `TailSamplingProcessor::onStart()` is a no-op
 *     that forwards nothing, and Prism's processor needs `onStart` — it is
 *     where a trace's origin is remembered and where the OTel trace id is
 *     published for every other signal to adopt (US-015, US-017). Composing
 *     Prism downstream would look neater and would silently cost `offset_ms`
 *     its zero point and every record its shared trace id.
 *
 * {@see SpanFlush} is the other half of the story and is force-flushed by
 * {@see Flusher} before the buffer is drained, so upstream's own decision
 * buffers never outlive the execution that filled them.
 */
beforeEach(function () {
    // Installed after the app's boot for the reason PrismSpanLaneTest gives:
    // the real transport is already a singleton, and a lifecycle test that
    // quietly ran against it would read as "no telemetry was produced".
    $this->transport = new TailSamplingRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

it('composes tail sampling with both rules onto the tracer upstream built', function () {
    expect(config('opentelemetry.traces.sampler.tail_sampling.enabled'))->toBeTrue()
        ->and(config('opentelemetry.traces.sampler.tail_sampling.rules'))->toBe([
            ErrorsRule::class => true,
            SlowTraceRule::class => ['enabled' => true, 'threshold_ms' => 2000],
        ]);

    // The config half is not the claim: this is. A key Prism wrote after
    // upstream had already read it would leave the tracer with a plain batch
    // processor and nothing anywhere saying so.
    expect(tailSamplingProcessor() !== null)->toBeTrue('the tracer was built without a TailSamplingProcessor');
});

it('drives the slow-trace threshold from PRISM_SLOW_TRACE_MS', function () {
    // Re-registering is what a host does through the environment; the value has
    // to survive into the rules the tracer is built from.
    config(['prism.otel.slow_trace_ms' => 350]);

    (new PrismServiceProvider(app()))->register();

    $rules = config('opentelemetry.traces.sampler.tail_sampling.rules');

    expect($rules[SlowTraceRule::class])->toBe(['enabled' => true, 'threshold_ms' => 350]);
});

it('keeps a span the tail-sampling decision is still holding', function () {
    // A span whose trace has no root here: the decision cannot be made, so
    // upstream holds it for up to decision_wait — 5000ms by default, longer
    // than the request that produced it lives. This is exactly the state the
    // AC names, reproduced rather than imagined.
    $span = Tracer::newSpan('held work')
        ->setParent(tailSamplingRemoteParent())
        ->start();

    $span->end();

    // Genuinely held: without this the rest of the test passes whether or not
    // tail sampling is doing anything at all.
    expect(tailSamplingHeldTraces())->toHaveCount(1);

    app(Flusher::class)->flush();

    $names = [];

    foreach (tailSamplingShipped($this->transport->sent) as $event) {
        $names[] = (string) $event['payload']['name'];
    }

    expect($names)->toContain('held work')
        // ...and the flusher's force-flush left upstream holding nothing, so a
        // long-lived worker's decision buffers cannot grow across executions.
        ->and(tailSamplingHeldTraces())->toHaveCount(0);
});

it('ships a failing request whole, which is what the errors rule is for', function () {
    Route::get('/prism-tail-sampling-probe', function () {
        DB::select('select 1 as one');

        abort(500, 'checkout blew up');
    });

    $this->get('/prism-tail-sampling-probe')->assertStatus(500);

    $spans = tailSamplingShipped($this->transport->sent);
    $roots = [];
    $children = [];

    foreach ($spans as $span) {
        $span['payload']['parent_span_id'] === ''
            ? $roots[] = $span
            : $children[] = $span;
    }

    // The whole trace, not the root alone: a rule that kept the request span
    // and lost the query under it would draw an empty waterfall for exactly the
    // request a reader opened the screen to understand.
    expect($roots)->toHaveCount(1)
        ->and($children)->not->toBeEmpty();

    foreach ($children as $child) {
        expect($child['trace_id'])->toBe($roots[0]['trace_id']);
    }
});

/**
 * A context whose active span is a valid REMOTE parent, so a span started under
 * it is a child of a root this process never sees — the shape that leaves
 * upstream's tail-sampling buffer with no root to decide on.
 */
function tailSamplingRemoteParent(): Context
{
    return Context::getCurrent()->withContextValue(Span::wrap(SpanContext::createFromRemoteParent(
        str_repeat('a', 32),
        str_repeat('b', 16),
        TraceFlags::SAMPLED,
    )));
}

/**
 * Upstream's tail-sampling processor, off the `TracerProvider` this process
 * built, or null when the tracer was built without one.
 *
 * Reached by reflection deliberately: the claim is about the object graph
 * upstream assembled, and the config key it assembled it from is the very thing
 * that can be written too late to be read.
 */
function tailSamplingProcessor(): ?TailSamplingProcessor
{
    $provider = Globals::tracerProvider();

    if (! $provider instanceof TracerProvider) {
        return null;
    }

    $state = (new ReflectionProperty($provider, 'tracerSharedState'))->getValue($provider);
    $processor = $state->getSpanProcessor();

    $processors = $processor instanceof MultiSpanProcessor
        ? $processor->getSpanProcessors()
        : [$processor];

    foreach ($processors as $candidate) {
        if ($candidate instanceof TailSamplingProcessor) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The trace ids upstream's tail-sampling processor is currently holding a
 * decision on.
 *
 * @return list<string>
 */
function tailSamplingHeldTraces(): array
{
    $processor = tailSamplingProcessor();

    if ($processor === null) {
        return [];
    }

    /** @var array<string, mixed> $buffers */
    $buffers = (new ReflectionProperty(TailSamplingProcessor::class, 'buffers'))->getValue($processor);

    return array_map(strval(...), array_keys($buffers));
}

/**
 * Every `span` event in every batch the transport was handed. Uniquely named
 * because Pest loads every test file into one process.
 *
 * @param  list<array<string, mixed>>  $envelopes
 * @return list<array<string, mixed>>
 */
function tailSamplingShipped(array $envelopes): array
{
    $spans = [];

    foreach ($envelopes as $envelope) {
        /** @var list<array<string, mixed>> $events */
        $events = $envelope['events'];

        foreach ($events as $event) {
            if ($event['type'] === 'span') {
                $spans[] = $event;
            }
        }
    }

    return $spans;
}

/** Keeps every batch instead of sending it, so a flush can be read back. */
final class TailSamplingRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $batch): bool
    {
        $this->sent[] = $batch;

        return true;
    }
}
