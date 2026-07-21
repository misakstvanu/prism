<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/** Matches a canonical UUID, the shape a generated trace id takes. */
const TRACE_UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

/**
 * A minimal queued job whose handler records the trace it runs under, so a test
 * can prove a dispatched job continues its originator's trace (US-041 AC4/AC5).
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
 * registerCapture() wires the trace middleware, the outgoing-HTTP propagation
 * and the queue payload hook (US-041). Uniquely named because Pest loads every
 * test file into one process.
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

it('honours a valid incoming trace id so a trace spans services', function () {
    TraceContext::start('svc-a-trace-123');

    expect(TraceContext::traceId())->toBe('svc-a-trace-123');
});

it('rejects an unusable incoming trace id and starts a fresh one', function (string $bad) {
    TraceContext::start($bad);

    expect(TraceContext::traceId())
        ->not->toBe($bad)
        ->toMatch(TRACE_UUID_PATTERN);
})->with([
    'spaces and punctuation' => 'not a valid id!',
    'blank' => '   ',
    'too long' => str_repeat('a', 200),
]);

it('starts a trace from the incoming header at the front of the request', function () {
    bootTracing();

    Route::get('/trace-probe', fn () => TraceContext::traceId());

    $response = $this->withHeaders([TraceContext::HEADER => 'upstream-trace-9'])
        ->get('/trace-probe');

    $response->assertOk();
    expect($response->getContent())->toBe('upstream-trace-9');
});

it('starts a fresh trace for a request with no incoming header', function () {
    bootTracing();

    Route::get('/trace-probe', fn () => TraceContext::traceId());

    $content = $this->get('/trace-probe')->assertOk()->getContent();

    expect($content)->toMatch(TRACE_UUID_PATTERN);
});

it('propagates the current trace id onto outgoing HTTP calls', function () {
    bootTracing();
    Http::fake();

    TraceContext::start('outgoing-trace-5');

    Http::get('https://downstream.test/api/resource');

    Http::assertSent(fn ($request) => $request->hasHeader(TraceContext::HEADER)
        && $request->header(TraceContext::HEADER) === ['outgoing-trace-5']);
});

it('does not overwrite a trace header the caller set explicitly', function () {
    bootTracing();
    Http::fake();

    TraceContext::start('ambient-trace');

    Http::withHeaders([TraceContext::HEADER => 'caller-set-trace'])
        ->get('https://downstream.test/x');

    Http::assertSent(fn ($request) => $request->header(TraceContext::HEADER) === ['caller-set-trace']);
});

it('stamps the originating trace onto a dispatched job and continues it when the job runs', function () {
    bootTracing();
    config(['queue.default' => 'sync']);

    TraceContext::start('request-trace-42');

    $payloadTrace = null;
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$payloadTrace) {
        $payloadTrace = $event->job->payload()[TraceContext::JOB_PAYLOAD_KEY] ?? null;
    });

    TraceRecorderJob::dispatch();

    // AC4: the dispatch stamped the request's trace onto the job payload, and
    // the job ran under that same trace.
    expect($payloadTrace)->toBe('request-trace-42')
        ->and(TraceRecorderJob::$captured)->toBe('request-trace-42');
});

it('continues a payload trace across a worker boundary regardless of the ambient trace', function () {
    bootTracing();

    // The worker's current trace is unrelated to the job that just arrived.
    TraceContext::start('worker-ambient-trace');

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([TraceContext::JOB_PAYLOAD_KEY => 'origin-trace-77']);

    event(new JobProcessing('redis', $job));

    // AC5: the job runs under the trace stamped by whoever queued it, not the
    // worker's ambient trace.
    expect(TraceContext::traceId())->toBe('origin-trace-77');
});

it('starts a fresh trace for a job queued without a trace id', function () {
    bootTracing();

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    event(new JobProcessing('redis', $job));

    expect(TraceContext::traceId())->toMatch(TRACE_UUID_PATTERN);
});
