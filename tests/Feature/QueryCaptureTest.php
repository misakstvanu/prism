<?php

use Illuminate\Database\Events\QueryExecuted;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\ExceptionCapture;
use Misakstvanu\Prism\Capture\QueryCapture;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/**
 * Reconfigure the app with full credentials and re-boot so registerCapture()
 * binds the QueryCapture singleton and wires the QueryExecuted listener. The
 * flush strategy is `sync` and no kernel drains the buffer, so a captured query
 * stays readable directly off the buffer. Recursion and trace state are reset
 * first so a scope left open elsewhere cannot leak in.
 *
 * @param  array<string, mixed>  $overrides
 */
function bootQueries(array $overrides = []): void
{
    config(array_merge([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'sync',
        'prism.batch.queue_threshold' => 0,
        'prism.capture.queries' => true,
        'prism.query.slow_threshold_ms' => 100,
    ], $overrides));

    Recursion::reset();
    TraceContext::reset();

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(Scrubber::class);
    app()->forgetInstance(ExceptionCapture::class);
    app()->forgetInstance(QueryCapture::class);

    (new PrismServiceProvider(app()))->boot();
}

/** A QueryExecuted event on the app's default (lazy) connection. */
function queryEvent(string $sql, array $bindings = [], float $timeMs = 1.0): QueryExecuted
{
    return new QueryExecuted($sql, $bindings, $timeMs, app('db')->connection());
}

/**
 * The first buffered query event, or null when none was captured.
 *
 * @return array<string, mixed>|null
 */
function bufferedQuery(): ?array
{
    return app(EventBuffer::class)->all()['query'][0] ?? null;
}

// -- AC1: the captured fields -----------------------------------------------

it('captures sql, bindings, connection name and duration', function () {
    bootQueries();

    event(queryEvent('select * from users where id = ?', [7], 12.5));

    $event = bufferedQuery();
    expect($event)->not->toBeNull();

    $payload = $event['payload'];

    expect($payload['sql'])->toBe('select * from users where id = ?')
        ->and($payload['bindings'])->toBe([7])
        ->and($payload['connection'])->toBeString()->not->toBe('')
        ->and($payload['duration_ms'])->toBe(12.5);
});

// -- AC3: correlation via trace id ------------------------------------------

it('links a query to the enclosing execution via the trace id', function () {
    bootQueries();

    TraceContext::start('trace-from-request');
    event(queryEvent('select 1'));

    $event = bufferedQuery();

    expect($event['trace_id'])->toBe('trace-from-request')
        ->and($event['timestamp'])->toBeString()
        ->and($event)->toHaveKey('user_id');
});

// -- AC2: the slow-query always-keep marker ---------------------------------

it('marks a query slower than the threshold for always-keep', function () {
    bootQueries(['prism.query.slow_threshold_ms' => 100]);

    event(queryEvent('select * from big_table', [], 250.0));

    expect(bufferedQuery()['payload']['slow'])->toBe(1);
});

it('does not mark a query below the threshold as slow', function () {
    bootQueries(['prism.query.slow_threshold_ms' => 100]);

    event(queryEvent('select 1', [], 3.0));

    expect(bufferedQuery()['payload']['slow'])->toBe(0);
});

it('honours a custom slow-query threshold', function () {
    bootQueries(['prism.query.slow_threshold_ms' => 5]);

    event(queryEvent('select 1', [], 8.0));

    expect(bufferedQuery()['payload']['slow'])->toBe(1);
});

it('marks nothing slow when the threshold is zero', function () {
    bootQueries(['prism.query.slow_threshold_ms' => 0]);

    event(queryEvent('select pg_sleep(10)', [], 10000.0));

    expect(bufferedQuery()['payload']['slow'])->toBe(0);
});

// -- AC5: bindings containing credentials are scrubbed ----------------------

it('scrubs credential-shaped named bindings before buffering', function () {
    bootQueries();

    event(queryEvent(
        'insert into users (email, password) values (:email, :password)',
        ['email' => 'a@b.test', 'password' => 'topsecret'],
        4.0,
    ));

    $bindings = bufferedQuery()['payload']['bindings'];

    expect($bindings['password'])->toBe(Scrubber::REDACTED)
        ->and($bindings['email'])->toBe('a@b.test');
});

// -- Recursion guard: never capture the package's own queries ----------------

it('never captures a query while the package is doing its own work', function () {
    bootQueries();

    Recursion::suppress(function () {
        event(queryEvent('select * from prism_internal'));
    });

    expect(app(EventBuffer::class)->count())->toBe(0);
});

// -- AC4: capture is skipped entirely when disabled -------------------------

it('registers no query listener when query capture is disabled', function () {
    // A fresh Laravel app already carries a framework QueryExecuted listener, so
    // the proof is that our boot adds NONE, not that none exist at all. Disable
    // request capture too so the US-043 query-counter listener is not the one
    // added. Measure the listener count around the boot itself.
    config([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.batch.flush' => 'sync',
        'prism.capture.queries' => false,
        'prism.capture.requests' => false,
    ]);
    Recursion::reset();
    TraceContext::reset();
    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(QueryCapture::class);

    $before = count(app('events')->getRawListeners()[QueryExecuted::class] ?? []);
    (new PrismServiceProvider(app()))->boot();
    $after = count(app('events')->getRawListeners()[QueryExecuted::class] ?? []);

    expect($after)->toBe($before)
        ->and(app()->bound(QueryCapture::class))->toBeFalse();

    // And a fired query produces nothing.
    event(queryEvent('select 1'));
    expect(app(EventBuffer::class)->count())->toBe(0);
});

it('registers exactly one query listener when query capture is enabled', function () {
    config([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.batch.flush' => 'sync',
        'prism.capture.queries' => true,
        'prism.capture.requests' => false,
    ]);
    Recursion::reset();
    TraceContext::reset();
    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(QueryCapture::class);

    $before = count(app('events')->getRawListeners()[QueryExecuted::class] ?? []);
    (new PrismServiceProvider(app()))->boot();
    $after = count(app('events')->getRawListeners()[QueryExecuted::class] ?? []);

    expect($after)->toBe($before + 1)
        ->and(app()->bound(QueryCapture::class))->toBeTrue();
});
