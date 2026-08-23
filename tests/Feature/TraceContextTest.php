<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;
use OpenTelemetry\SDK\Trace\TracerProvider;

/** Matches a canonical UUID, the shape a generated trace id takes. */
const TRACE_UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

/**
 * A minimal queued job whose handler records the trace it runs under, so a test
 * can read the trace a dispatched job actually ran under.
 */
class TraceRecorderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public static ?string $captured = null;

    public function handle(): void
    {
        self::$captured = TraceContext::traceId();
    }
}

/**
 * Reconfigure the app with full credentials and re-run boot() so
 * registerCapture() wires the queue and Octane listeners that re-anchor the
 * fallback trace (US-041, reduced to those two by US-021 — a request opens no
 * trace of Prism's own any more). Uniquely named because Pest loads every test
 * file into one process.
 */
function bootTracing(): void
{
    config([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
    ]);

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);

    (new PrismServiceProvider(app()))->boot();
}

beforeEach(function () {
    TraceContext::reset();
    TraceRecorderJob::$captured = null;
});

// Queue payload callbacks are stored statically on the framework's Queue class
// and survive across tests in one process; clear them so one test's hook does
// not stamp another test's job payload.
afterEach(fn () => Queue::createPayloadUsing(null));

it('generates a trace id and reuses it until reset', function () {
    $id = TraceContext::traceId();

    expect($id)->toMatch(TRACE_UUID_PATTERN)
        ->and(TraceContext::traceId())->toBe($id);

    TraceContext::reset();

    expect(TraceContext::traceId())->not->toBe($id);
});

/*
 * US-020 — propagation is W3C `traceparent`'s job now, and the bespoke
 * `X-Prism-Trace-Id` header is gone from both the incoming and the outgoing
 * path.
 *
 * The cases below are the *removal*, asserted from the side a host can see: an
 * outgoing call carries no Prism header, a dispatched job's payload carries no
 * Prism key, and an incoming Prism header is not honoured (which is the one a
 * reader is most likely to mistake for a bug rather than a decision — it used to
 * work). What replaced them cannot be shown here at all: the span lane is not
 * registered in this suite, so `traceparent` is asserted end to end in
 * `tests/Otel/W3CPropagationTest.php`, where all three providers are.
 */
it('ignores the removed Prism trace header on an incoming request', function () {
    bootTracing();

    Route::get('/trace-probe', fn () => TraceContext::traceId());

    $content = $this->withHeaders(['X-Prism-Trace-Id' => 'upstream-trace-9'])
        ->get('/trace-probe')
        ->assertOk()
        ->getContent();

    // A header only a Prism client understood, and nothing reads it any more —
    // an upstream service that still sends it gets a fresh trace, exactly as if
    // it had sent nothing.
    expect($content)->not->toBe('upstream-trace-9')
        ->toMatch(TRACE_UUID_PATTERN);
});

it('still answers a request with a usable trace, with nothing opening one', function () {
    bootTracing();

    // US-021 deleted the middleware that used to open a trace at the front of
    // every request: the span lane opens the only trace there is now, and this
    // suite does not register it. So what is asserted is the last of the three
    // answers — the id generated lazily on first read, which is what keeps a
    // host with the OpenTelemetry SDK switched off from leaving a request's
    // signals unkeyed. A plain FPM process is fresh per request, which is why
    // dropping the eager start costs that host nothing.
    Route::get('/trace-probe', fn () => TraceContext::traceId());

    $content = $this->get('/trace-probe')->assertOk()->getContent();

    expect($content)->toMatch(TRACE_UUID_PATTERN);
});

it('stamps no Prism trace header onto an outgoing HTTP call', function () {
    bootTracing();
    Http::fake();

    TraceContext::start();

    Http::get('https://downstream.test/api/resource');

    Http::assertSent(fn ($request) => ! $request->hasHeader('X-Prism-Trace-Id'));
});

it('stamps no Prism trace key onto a dispatched job payload', function () {
    bootTracing();
    config(['queue.default' => 'sync']);

    TraceContext::start();

    $payload = null;
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$payload) {
        $payload = $event->job->payload();
    });

    TraceRecorderJob::dispatch();

    expect($payload)->toBeArray()
        ->and($payload)->not->toHaveKey('prism_trace_id');
});

it('anchors a fresh trace for each job the worker picks up', function () {
    bootTracing();

    // A worker handles one job after another in one long-lived process, so the
    // trace a job runs under must never be the previous job's. With no span
    // lane there is nothing to continue from the payload, so what is asserted
    // is the fallback: a fresh id per job.
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\PriceOrder');

    event(new JobProcessing('redis', $job));
    $first = TraceContext::traceId();

    event(new JobProcessing('redis', $job));
    $second = TraceContext::traceId();

    expect($first)->toMatch(TRACE_UUID_PATTERN)
        ->and($second)->toMatch(TRACE_UUID_PATTERN)
        ->and($second)->not->toBe($first);
});

/*
 * US-017 — OpenTelemetry is the source of truth, and this class is where the
 * two engines are made to agree.
 *
 * The order is: the active span, then the trace published into Laravel's
 * context, then an id of this class's own. The middle answer is the one that is
 * easy to leave out and the one that matters most — the capture engine writes
 * its `request` record from `terminate()`, after upstream's middleware has
 * ended the request span, and Laravel carries the context across a queue
 * boundary where no span of ours exists at all.
 */
it('prefers the OpenTelemetry trace over an id of its own', function () {
    TraceContext::start();

    $tracer = TracerProvider::builder()->build()->getTracer('prism-trace-context-test');
    $span = $tracer->spanBuilder('GET /orders')->startSpan();
    $scope = $span->activate();

    expect(TraceContext::traceId())->toBe($span->getContext()->getTraceId())
        ->and(TraceContext::otelTraceId())->toBe($span->getContext()->getTraceId());

    $scope->detach();
    $span->end();
});

it('still answers the trace after the span that opened it has ended', function () {
    $tracer = TracerProvider::builder()->build()->getTracer('prism-trace-context-test');
    $span = $tracer->spanBuilder('GET /orders')->startSpan();
    $scope = $span->activate();

    $traceId = $span->getContext()->getTraceId();

    // What the span processor does from `onStart`.
    TraceContext::adopt($traceId);

    $scope->detach();
    $span->end();

    // Nothing is active any more, which is exactly the state the engine writes
    // its `request` record in.
    expect(TraceContext::otelTraceId())->toBe($traceId)
        ->and(Context::get(TraceContext::CONTEXT_KEY))->toBe($traceId);
});

it('answers nothing at all when OpenTelemetry has said nothing', function () {
    // A host with the SDK switched off. Nothing to re-key a record onto, so the
    // capture engine's own id stands and nothing is ever left unkeyed.
    expect(TraceContext::otelTraceId())->toBeNull()
        ->and(TraceContext::traceId())->toMatch(TRACE_UUID_PATTERN);
});

it('refuses to publish or read anything that is not a trace id', function (string $bad) {
    TraceContext::adopt($bad);

    expect(Context::get(TraceContext::CONTEXT_KEY))->toBeNull();

    // And a value the host (or a tampered queue payload) put there directly is
    // validated on the way out too, rather than becoming a row's key.
    Context::add(TraceContext::CONTEXT_KEY, $bad);

    expect(TraceContext::otelTraceId())->toBeNull();
})->with([
    'the engine own uuid' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
    'the reserved invalid id' => '00000000000000000000000000000000',
    'upper case hex' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90',
    'too short' => 'a1b2c3d4',
    'blank' => '',
]);
