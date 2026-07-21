<?php

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Http;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\CacheCapture;
use Misakstvanu\Prism\Capture\CaptureRequests;
use Misakstvanu\Prism\Capture\ExceptionCapture;
use Misakstvanu\Prism\Capture\HttpCapture;
use Misakstvanu\Prism\Capture\QueryCapture;
use Misakstvanu\Prism\Capture\SpanRecorder;
use Misakstvanu\Prism\Prism;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\SpanStack;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/**
 * Reconfigure the app with full credentials and re-boot so registerCapture()
 * binds the span recorder, the request capturer and the cache/HTTP capturers,
 * with trace assembly on. The flush strategy is `sync` and no kernel drains the
 * buffer, so buffered spans stay readable directly. All static context — the
 * recursion guard, the trace and the open-span stack — is reset first so a scope
 * left open elsewhere in the one Pest process cannot leak in. Uniquely named
 * because Pest loads every test file into one process.
 *
 * @param  array<string, mixed>  $overrides
 */
function bootAssembly(array $overrides = []): void
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
        'prism.capture.traces' => true,
        // Off so a fired QueryExecuted feeds only the aggregate `db` span, not a
        // separate `query` event — keeps the buffer to spans and the request.
        'prism.capture.queries' => false,
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
    app()->forgetInstance(CacheCapture::class);
    app()->forgetInstance(HttpCapture::class);
    app()->forgetInstance(QueryCapture::class);
    app()->forgetInstance(SpanRecorder::class);

    (new PrismServiceProvider(app()))->boot();
}

/**
 * The buffered span events, in order.
 *
 * @return list<array<string, mixed>>
 */
function assemblySpans(): array
{
    return app(EventBuffer::class)->all()['span'] ?? [];
}

/**
 * The span sub-types buffered so far.
 *
 * @return list<string>
 */
function assemblySpanTypes(): array
{
    return array_map(static fn (array $s): string => $s['payload']['type'], assemblySpans());
}

/** The first buffered span of a given sub-type, or null. */
function assemblySpanOfType(string $type): ?array
{
    foreach (assemblySpans() as $span) {
        if ($span['payload']['type'] === $type) {
            return $span;
        }
    }

    return null;
}

// Reset the static open-span stack after each test so a span this file opens can
// never leak a parent onto another file's isolated cache/HTTP spans.
afterEach(function () {
    SpanStack::reset();
    TraceContext::reset();
    Recursion::reset();
});

// -- AC4: manual instrumentation --------------------------------------------

it('records a manually instrumented block as a span carrying offset and duration', function () {
    bootAssembly();
    TraceContext::start('trace-manual');

    $result = Prism::span('expensive-report', function () {
        usleep(1000);

        return 'done';
    });

    $span = assemblySpanOfType('ctrl');

    expect($result)->toBe('done')
        ->and($span)->not->toBeNull()
        ->and($span['trace_id'])->toBe('trace-manual')
        ->and($span['payload']['name'])->toBe('expensive-report')
        ->and($span['payload']['type'])->toBe('ctrl')
        ->and($span['payload']['offset_ms'])->toBeFloat()
        ->and($span['payload']['duration_ms'])->toBeFloat()->toBeGreaterThan(0.0)
        ->and($span['payload']['parent_span_id'])->toBe('')
        ->and($span['payload']['depth'])->toBe(0)
        ->and($span['payload']['span_id'])->toBeString()->not->toBe('');
});

it('lets a manual span choose its type and pass through the closure value', function () {
    bootAssembly();

    $rows = Prism::span('bulk-load', fn (): array => [1, 2, 3], 'db');

    expect($rows)->toBe([1, 2, 3])
        ->and(assemblySpanOfType('db')['payload']['name'])->toBe('bulk-load');
});

it('nests a manual span inside another and keeps the child within the parent', function () {
    bootAssembly();
    TraceContext::start('trace-nested');

    Prism::span('outer', function () {
        usleep(500);
        Prism::span('inner', fn () => usleep(500));
        usleep(500);
    });

    $spans = assemblySpans();
    $outer = collect($spans)->firstWhere('payload.name', 'outer');
    $inner = collect($spans)->firstWhere('payload.name', 'inner');

    expect($outer)->not->toBeNull()
        ->and($inner)->not->toBeNull()
        ->and($outer['payload']['depth'])->toBe(0)
        ->and($outer['payload']['parent_span_id'])->toBe('')
        ->and($inner['payload']['depth'])->toBe(1)
        ->and($inner['payload']['parent_span_id'])->toBe($outer['payload']['span_id']);

    // The child's whole lifetime falls inside its parent's.
    $childStart = $inner['payload']['offset_ms'];
    $childEnd = $childStart + $inner['payload']['duration_ms'];
    $parentStart = $outer['payload']['offset_ms'];
    $parentEnd = $parentStart + $outer['payload']['duration_ms'];

    expect($childStart)->toBeGreaterThanOrEqual($parentStart - 1.0)
        ->and($childEnd)->toBeLessThanOrEqual($parentEnd + 1.0);
});

it('is a safe no-op that still runs the closure when the client is inert', function () {
    // No boot: the client is not active, so nothing is recorded — but the code
    // still runs and its value is returned.
    app()->forgetInstance(PrismServiceProvider::ACTIVE);

    $value = Prism::span('anything', fn (): int => 7);

    expect($value)->toBe(7);
});

// -- AC1: a request produces mw / ctrl / db / cache / http / resp spans -------

it('assembles the request lifecycle into typed waterfall spans', function () {
    bootAssembly();
    Http::fake(['*' => Http::response('ok', 200)]);

    TraceContext::start('trace-request');
    usleep(2000); // stand in for bootstrap + middleware before the controller

    $mw = app(CaptureRequests::class);
    $request = Request::create('/orders/42', 'GET');
    $request->setRouteResolver(fn () => new RoutingRoute(['GET'], 'orders/{id}', []));

    $connection = app('db')->connection();

    $mw->handle($request, function () use ($connection) {
        // Work done inside the controller phase — each nests under the ctrl span.
        event(new CacheHit('redis', 'order:42', ['cached']));
        event(new QueryExecuted('select * from orders', [], 3.0, $connection));
        Http::get('https://api.example.com/enrich');

        return new Response('ok');
    });
    $mw->terminate($request, new Response('ok', 200));

    // Every prototype span type the request exercises is present (US-046 already
    // covers cache + http; US-050 adds mw, ctrl, db, resp).
    expect(assemblySpanTypes())->toContain('mw', 'ctrl', 'db', 'cache', 'http', 'resp');

    $ctrl = assemblySpanOfType('ctrl');
    $cache = assemblySpanOfType('cache');
    $http = assemblySpanOfType('http');
    $db = assemblySpanOfType('db');

    // mw / ctrl / resp are top-level siblings that partition the request.
    expect(assemblySpanOfType('mw')['payload']['parent_span_id'])->toBe('')
        ->and($ctrl['payload']['parent_span_id'])->toBe('')
        ->and(assemblySpanOfType('resp')['payload']['parent_span_id'])->toBe('');

    // cache, http and the aggregate db span nest under the controller phase.
    expect($cache['payload']['parent_span_id'])->toBe($ctrl['payload']['span_id'])
        ->and($cache['payload']['depth'])->toBe(1)
        ->and($http['payload']['parent_span_id'])->toBe($ctrl['payload']['span_id'])
        ->and($http['payload']['depth'])->toBe(1)
        ->and($db['payload']['parent_span_id'])->toBe($ctrl['payload']['span_id'])
        ->and($db['payload']['name'])->toContain('1 query');
});

// -- AC3: no child span extends beyond its parent ---------------------------

it('keeps every child span within the bounds of its parent', function () {
    bootAssembly();
    Http::fake(['*' => Http::response('ok', 200)]);

    TraceContext::start('trace-bounds');
    usleep(1500);

    $mw = app(CaptureRequests::class);
    $request = Request::create('/report', 'GET');
    $request->setRouteResolver(fn () => new RoutingRoute(['GET'], 'report', []));
    $connection = app('db')->connection();

    $mw->handle($request, function () use ($connection) {
        event(new CacheHit('redis', 'k', 'v'));
        event(new QueryExecuted('select 1', [], 2.0, $connection));
        event(new QueryExecuted('select 2', [], 4.0, $connection));
        Http::get('https://api.example.com/thing');
        Prism::span('inner-compute', fn () => usleep(300));

        return new Response('ok');
    });
    $mw->terminate($request, new Response('ok', 200));

    $spans = assemblySpans();
    $byId = [];
    foreach ($spans as $span) {
        $byId[$span['payload']['span_id']] = $span['payload'];
    }

    $children = 0;
    foreach ($spans as $span) {
        $parentId = $span['payload']['parent_span_id'];

        if ($parentId === '') {
            continue;
        }

        // A recorded parent must exist for every child (no orphans).
        expect($byId)->toHaveKey($parentId);

        $child = $span['payload'];
        $parent = $byId[$parentId];

        $childStart = $child['offset_ms'];
        $childEnd = $childStart + $child['duration_ms'];
        $parentStart = $parent['offset_ms'];
        $parentEnd = $parentStart + $parent['duration_ms'];

        // The child begins no earlier than its parent and ends no later (a small
        // epsilon absorbs sub-millisecond rounding of the offsets and durations).
        expect($childStart)->toBeGreaterThanOrEqual($parentStart - 1.0)
            ->and($childEnd)->toBeLessThanOrEqual($parentEnd + 1.0);

        $children++;
    }

    // The scenario really did produce nested children to check.
    expect($children)->toBeGreaterThan(0);
});

// -- Trace toggle: no waterfall spans when trace capture is off --------------

it('emits no lifecycle spans when trace capture is disabled', function () {
    bootAssembly(['prism.capture.traces' => false]);

    $mw = app(CaptureRequests::class);
    $request = Request::create('/x', 'GET');
    $mw->handle($request, fn () => new Response('ok'));
    $mw->terminate($request, new Response('ok', 200));

    expect(assemblySpans())->toBe([])
        ->and(app(EventBuffer::class)->all())->toHaveKey('request');
});
