<?php

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Http;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\CacheCapture;
use Misakstvanu\Prism\Capture\ExceptionCapture;
use Misakstvanu\Prism\Capture\HttpCapture;
use Misakstvanu\Prism\Capture\QueryCapture;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/**
 * Reconfigure the app with full credentials and re-boot so registerCapture()
 * wires the cache listeners and the outgoing-HTTP global middleware (US-046).
 * The flush strategy is `sync` and no kernel drains the buffer, so a captured
 * span stays readable directly off the buffer. Recursion and trace state are
 * reset first so a scope left open elsewhere cannot leak in. Uniquely named
 * because Pest loads every test file into one process.
 *
 * @param  array<string, mixed>  $overrides
 */
function bootSpans(array $overrides = []): void
{
    config(array_merge([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'sync',
        'prism.batch.queue_threshold' => 0,
        'prism.capture.traces' => true,
    ], $overrides));

    Recursion::reset();
    TraceContext::reset();

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(Scrubber::class);
    app()->forgetInstance(ExceptionCapture::class);
    app()->forgetInstance(QueryCapture::class);
    app()->forgetInstance(CacheCapture::class);
    app()->forgetInstance(HttpCapture::class);

    (new PrismServiceProvider(app()))->boot();
}

/**
 * The buffered span events, in order.
 *
 * @return list<array<string, mixed>>
 */
function bufferedSpans(): array
{
    return app(EventBuffer::class)->all()['span'] ?? [];
}

/** The first buffered span event, or null when none was captured. */
function firstSpan(): ?array
{
    return bufferedSpans()[0] ?? null;
}

// -- AC1: cache hit / miss / write / forget ---------------------------------

it('captures a cache hit as a span carrying the key and store', function () {
    bootSpans();
    TraceContext::start('trace-abc');

    event(new CacheHit('redis', 'user:42', ['cached']));

    $span = firstSpan();

    expect($span)->not->toBeNull()
        ->and($span['trace_id'])->toBe('trace-abc')
        ->and($span['payload']['type'])->toBe('cache')
        ->and($span['payload']['operation'])->toBe('hit')
        ->and($span['payload']['key'])->toBe('user:42')
        ->and($span['payload']['store'])->toBe('redis')
        ->and($span['payload']['name'])->toContain('user:42')
        ->and($span['payload']['name'])->toContain('redis')
        ->and($span['payload']['offset_ms'])->toBeFloat()
        ->and($span['payload']['duration_ms'])->toBe(0.0)
        ->and($span['payload']['span_id'])->toBeString()->not->toBe('')
        ->and($span['payload']['parent_span_id'])->toBe('');
});

it('captures a cache miss, write and forget as spans', function () {
    bootSpans();

    event(new CacheMissed('file', 'settings'));
    event(new KeyWritten('file', 'settings', 'value', 600));
    event(new KeyForgotten('file', 'settings'));

    $operations = array_map(fn (array $s): string => $s['payload']['operation'], bufferedSpans());

    expect($operations)->toBe(['miss', 'write', 'forget']);
});

it('truncates a very long cache key on the span', function () {
    bootSpans();

    event(new CacheHit('redis', str_repeat('k', 500), 'v'));

    $key = firstSpan()['payload']['key'];

    expect(strlen($key))->toBeLessThan(500)
        ->and($key)->toEndWith('...');
});

it('falls back to a default store name when none is given', function () {
    bootSpans();

    event(new CacheMissed(null, 'anon'));

    expect(firstSpan()['payload']['store'])->toBe('default');
});

// -- AC2: outgoing HTTP capture ---------------------------------------------

it('captures an outgoing HTTP call as a span with method, host, path, status and duration', function () {
    bootSpans();
    TraceContext::start('trace-http');
    Http::fake(['*' => Http::response('ok', 201)]);

    Http::post('https://api.stripe.com/v1/charges', ['amount' => 100]);

    $span = firstSpan();

    expect($span)->not->toBeNull()
        ->and($span['trace_id'])->toBe('trace-http')
        ->and($span['payload']['type'])->toBe('http')
        ->and($span['payload']['method'])->toBe('POST')
        ->and($span['payload']['host'])->toBe('api.stripe.com')
        ->and($span['payload']['path'])->toBe('/v1/charges')
        ->and($span['payload']['status'])->toBe(201)
        ->and($span['payload']['duration_ms'])->toBeFloat()
        ->and($span['payload']['offset_ms'])->toBeFloat()
        ->and($span['payload']['name'])->toBe('POST api.stripe.com/v1/charges');
});

// -- AC3: query strings are scrubbed of credential-shaped params -------------

it('scrubs credential-shaped query parameters from the captured URL', function () {
    bootSpans();
    Http::fake(['*' => Http::response('', 200)]);

    Http::get('https://api.example.com/search?q=widgets&api_key=supersecret&token=abc123');

    $url = firstSpan()['payload']['url'];

    expect($url)->toContain('q=widgets')
        ->and($url)->not->toContain('supersecret')
        ->and($url)->not->toContain('abc123')
        ->and($url)->toContain(rawurlencode(Scrubber::REDACTED));
});

// -- AC5: Prism's own requests are excluded ---------------------------------

it('does not capture an outgoing request marked internal', function () {
    bootSpans();
    Http::fake(['*' => Http::response('', 200)]);

    Http::withHeaders([Recursion::MARKER_HEADER => '1'])
        ->get('https://prism.dev/api/ingest');

    expect(bufferedSpans())->toBe([]);
});

it('never captures cache or HTTP activity while the package is doing its own work', function () {
    bootSpans();
    Http::fake(['*' => Http::response('', 200)]);

    Recursion::suppress(function () {
        event(new CacheHit('redis', 'internal', 'v'));
        Http::get('https://api.example.com/ping');
    });

    expect(app(EventBuffer::class)->count())->toBe(0);
});

// -- Both render on the trace waterfall: emitted as `span` events ------------

it('emits cache and HTTP capture as span events', function () {
    bootSpans();
    Http::fake(['*' => Http::response('', 200)]);

    event(new CacheHit('redis', 'user:1', 'v'));
    Http::get('https://api.example.com/thing');

    $buffered = app(EventBuffer::class)->all();

    expect($buffered)->toHaveKey('span')
        ->and($buffered['span'])->toHaveCount(2)
        ->and(array_keys($buffered))->toBe(['span']);
});

// -- Capture is skipped entirely when traces are disabled -------------------

it('registers no cache or HTTP capture when traces are disabled', function () {
    bootSpans(['prism.capture.traces' => false]);
    Http::fake(['*' => Http::response('', 200)]);

    event(new CacheHit('redis', 'user:1', 'v'));
    Http::get('https://api.example.com/thing');

    expect(app()->bound(CacheCapture::class))->toBeFalse()
        ->and(app()->bound(HttpCapture::class))->toBeFalse()
        ->and(app(EventBuffer::class)->count())->toBe(0);
});
