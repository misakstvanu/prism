<?php

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\CaptureRequests;
use Misakstvanu\Prism\Capture\ExceptionCapture;
use Misakstvanu\Prism\Capture\SpanRecorder;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\SpanStack;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/**
 * A transport double that records the envelopes handed to it instead of sending
 * them, so a full request's flush can be asserted without touching the network.
 * Uniquely named because Pest loads every test file into one process and would
 * fatal on a redeclaration of another file's double.
 */
class RequestCaptureTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}

/**
 * Reconfigure the app with full credentials and re-boot so registerCapture()
 * binds the CaptureRequests singleton, appends the middleware to whatever
 * web/api groups the host defined and wires the query-count listener. Recursion,
 * trace and span-stack state are reset first so a scope left open elsewhere
 * cannot leak in. Trace-span assembly (US-050) is switched off here so these
 * request-capture assertions see only the `request` event — the waterfall spans
 * a request also produces have their own coverage in SpanAssemblyTest.
 *
 * @param  array<string, mixed>  $overrides
 */
function bootRequests(array $overrides = []): void
{
    config(array_merge([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'sync',
        'prism.batch.queue_threshold' => 0,
        'prism.capture.requests' => true,
        'prism.capture.traces' => false,
        // Replica health sampling (US-051) piggybacks a metric onto every flush;
        // disabled here so it never inflates this file's exact event counts.
        'prism.capture.metrics' => false,
    ], $overrides));

    Recursion::reset();
    TraceContext::reset();
    SpanStack::reset();

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(Scrubber::class);
    app()->forgetInstance(ExceptionCapture::class);
    app()->forgetInstance(CaptureRequests::class);
    app()->forgetInstance(SpanRecorder::class);

    (new PrismServiceProvider(app()))->boot();
}

/**
 * The first buffered request event, or null when none was captured.
 *
 * @return array<string, mixed>|null
 */
function bufferedRequest(): ?array
{
    return app(EventBuffer::class)->all()['request'][0] ?? null;
}

// -- AC1: auto-registration on the web and api groups -----------------------

it('auto-registers the capture middleware on the web and api groups', function () {
    // A minimal Testbench kernel defines no groups; give it empty web/api ones
    // so we can prove the middleware is appended to them, as it would be on a
    // real Laravel app that ships both.
    $kernel = app(HttpKernelContract::class);
    $kernel->setMiddlewareGroups(['web' => [], 'api' => []]);

    bootRequests();

    $groups = $kernel->getMiddlewareGroups();

    expect($groups['web'])->toContain(CaptureRequests::class)
        ->and($groups['api'])->toContain(CaptureRequests::class);
});

it('does not throw when a host defines neither the web nor the api group', function () {
    $kernel = app(HttpKernelContract::class);
    $kernel->setMiddlewareGroups([]);

    expect(fn () => bootRequests())->not->toThrow(Throwable::class);
});

// -- AC2: the captured fields -----------------------------------------------

it('captures method, path, route, status, duration, memory, query count, ip, user agent and ids', function () {
    bootRequests();
    $mw = app(CaptureRequests::class);

    $request = Request::create('/orders/42', 'POST', ['amount' => 100], [], [], [
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => 'PrismTest/1.0',
    ]);
    $request->setRouteResolver(fn () => new RoutingRoute(['POST'], 'orders/{id}', []));

    $mw->handle($request, fn () => new Response('ok'));
    $mw->recordQuery();
    $mw->recordQuery();
    $mw->recordQuery();
    $mw->terminate($request, new Response('created', 201));

    $event = bufferedRequest();
    expect($event)->not->toBeNull();

    $payload = $event['payload'];

    expect($payload['method'])->toBe('POST')
        ->and($payload['path'])->toBe('/orders/42')
        ->and($payload['route'])->toBe('orders/{id}')
        ->and($payload['status'])->toBe(201)
        ->and($payload['query_count'])->toBe(3)
        ->and($payload['ip'])->toBe('203.0.113.7')
        ->and($payload['user_agent'])->toBe('PrismTest/1.0')
        ->and($payload['duration_ms'])->toBeFloat()->toBeGreaterThanOrEqual(0.0)
        ->and($payload['memory_mb'])->toBeFloat()->toBeGreaterThan(0.0);

    // The envelope carries what the console correlates and attributes on.
    expect($event['trace_id'])->toBeString()->not->toBe('')
        ->and($event['request_id'])->toBeString()->not->toBe('')
        ->and($event['timestamp'])->toBeString()
        ->and($event)->toHaveKey('user_id');
});

it('records an empty route for a request that matched none', function () {
    bootRequests();
    $mw = app(CaptureRequests::class);

    $request = Request::create('/nowhere', 'GET');
    $mw->handle($request, fn () => new Response('not found', 404));
    $mw->terminate($request, new Response('not found', 404));

    expect(bufferedRequest()['payload']['route'])->toBe('')
        ->and(bufferedRequest()['payload']['status'])->toBe(404);
});

// -- Query counting through the QueryExecuted listener ----------------------

it('counts the queries a request runs and reports the total', function () {
    bootRequests();
    $mw = app(CaptureRequests::class);

    $request = Request::create('/x', 'GET');
    $mw->handle($request, fn () => new Response('ok'));

    // The connection is obtained lazily and never queried — firing the event
    // is enough to exercise the listener that feeds the per-request counter.
    $connection = app('db')->connection();
    event(new QueryExecuted('select 1', [], 0.1, $connection));
    event(new QueryExecuted('select 2', [], 0.2, $connection));

    $mw->terminate($request, new Response('ok', 200));

    expect(bufferedRequest()['payload']['query_count'])->toBe(2);
});

it('resets the query count at the start of each request', function () {
    bootRequests();
    $mw = app(CaptureRequests::class);

    $connection = app('db')->connection();

    $first = Request::create('/first', 'GET');
    $mw->handle($first, fn () => new Response('ok'));
    event(new QueryExecuted('select 1', [], 0.1, $connection));
    $mw->terminate($first, new Response('ok', 200));

    // A second request on the same (singleton) instance starts from zero.
    $second = Request::create('/second', 'GET');
    $mw->handle($second, fn () => new Response('ok'));
    $mw->terminate($second, new Response('ok', 200));

    $events = app(EventBuffer::class)->all()['request'];
    expect($events[0]['payload']['query_count'])->toBe(1)
        ->and($events[1]['payload']['query_count'])->toBe(0);
});

// -- AC3: ignored paths -----------------------------------------------------

it('ignores health-check and Prism\'s own routes by default', function () {
    bootRequests();
    $mw = app(CaptureRequests::class);

    foreach (['/up', '/health/live', '/prism', '/prism/ingest'] as $path) {
        $request = Request::create($path, 'GET');
        $mw->handle($request, fn () => new Response('ok'));
        $mw->terminate($request, new Response('ok', 200));
    }

    expect(app(EventBuffer::class)->count())->toBe(0);
});

it('honours a custom ignore pattern and still captures everything else', function () {
    bootRequests(['prism.ignore.paths' => ['internal/*']]);
    $mw = app(CaptureRequests::class);

    $ignored = Request::create('/internal/metrics', 'GET');
    $mw->handle($ignored, fn () => new Response('ok'));
    $mw->terminate($ignored, new Response('ok', 200));
    expect(app(EventBuffer::class)->count())->toBe(0);

    $kept = Request::create('/dashboard', 'GET');
    $mw->handle($kept, fn () => new Response('ok'));
    $mw->terminate($kept, new Response('ok', 200));
    expect(app(EventBuffer::class)->count())->toBe(1);
});

// -- AC4: request body capture, scrubbing and truncation --------------------

it('captures a scrubbed, size-capped body for a non-GET request', function () {
    bootRequests(['prism.request.max_body' => 40]);
    $mw = app(CaptureRequests::class);

    $request = Request::create('/login', 'POST', [
        'password' => 'topsecret',
        'note' => str_repeat('a', 500),
    ]);
    $mw->handle($request, fn () => new Response('ok'));
    $mw->terminate($request, new Response('ok', 200));

    $body = bufferedRequest()['payload']['body'];

    expect($body)->toBeString()
        ->and(strlen($body))->toBe(40)                 // truncated to the byte cap
        ->and($body)->not->toContain('topsecret')      // secret redacted before truncation
        ->and($body)->toContain('[REDACTED]');
});

it('does not capture a body for a GET request', function () {
    bootRequests();
    $mw = app(CaptureRequests::class);

    $request = Request::create('/search?q=laravel', 'GET');
    $mw->handle($request, fn () => new Response('ok'));
    $mw->terminate($request, new Response('ok', 200));

    expect(bufferedRequest()['payload'])->not->toHaveKey('body');
});

// -- Recursion guard: never capture the package's own request work ----------

it('never captures a request while the package is doing its own work', function () {
    bootRequests();
    $mw = app(CaptureRequests::class);

    $request = Request::create('/x', 'GET');
    $mw->handle($request, fn () => new Response('ok'));

    Recursion::suppress(function () use ($mw, $request) {
        $mw->terminate($request, new Response('ok', 200));
    });

    expect(app(EventBuffer::class)->count())->toBe(0);
});

// -- Per-domain toggle ------------------------------------------------------

it('registers nothing when request capture is disabled', function () {
    $kernel = app(HttpKernelContract::class);
    $kernel->setMiddlewareGroups(['web' => [], 'api' => []]);

    bootRequests(['prism.capture.requests' => false]);

    $groups = $kernel->getMiddlewareGroups();

    expect($groups['web'])->not->toContain(CaptureRequests::class)
        ->and($groups['api'])->not->toContain(CaptureRequests::class)
        ->and(app()->bound(CaptureRequests::class))->toBeFalse();
});

// -- AC5: timing from LARAVEL_START -----------------------------------------

it('measures duration from LARAVEL_START so framework boot is included', function () {
    bootRequests();

    // Simulate a framework boot that began well before the middleware ran. The
    // constant is process-global; other request tests only assert a lower bound
    // on duration, so defining it here cannot break them.
    if (! defined('LARAVEL_START')) {
        define('LARAVEL_START', microtime(true) - 0.5);
    }

    $mw = app(CaptureRequests::class);
    $request = Request::create('/x', 'GET');
    $mw->handle($request, fn () => new Response('ok'));
    $mw->terminate($request, new Response('ok', 200));

    // Duration spans from LARAVEL_START, so it includes the ~500ms of boot.
    expect(bufferedRequest()['payload']['duration_ms'])->toBeGreaterThanOrEqual(400.0);
});

// -- End to end through the kernel ------------------------------------------

it('records a real request end to end and ships it after the response', function () {
    bootRequests();

    $transport = new RequestCaptureTransport;
    app()->instance(Transport::class, $transport);

    Route::middleware(CaptureRequests::class)->post('/orders/{id}', fn () => response('created', 201));

    $this->post('/orders/42', ['amount' => 100, 'password' => 'hunter2'])
        ->assertStatus(201);

    // The buffer was drained to the transport on terminate.
    expect($transport->sent)->toHaveCount(1);

    $events = $transport->sent[0]['events'];
    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('request');

    $payload = $events[0]['payload'];
    expect($payload['method'])->toBe('POST')
        ->and($payload['path'])->toBe('/orders/42')
        ->and($payload['route'])->toBe('orders/{id}')
        ->and($payload['status'])->toBe(201)
        ->and($payload['body'])->toBeString()
        ->and($payload['body'])->not->toContain('hunter2')
        ->and($payload['body'])->toContain('[REDACTED]');
});
