<?php

declare(strict_types=1);

use Misakstvanu\Prism\Nightwatch\RecordTranslator;

/**
 * The seam between Nightwatch's records and Prism's wire format (US-002).
 *
 * Every fixture here is a **real** record, produced by driving the upstream
 * sensors through {@see nightwatchRecords()} rather than by hand — see that
 * helper for why. Two of the traps these tests exist for were invisible until
 * a real record was in front of them: an exception record is `v: 3` where every
 * other record is `v: 1`, and half the globals arrive as unresolved `LazyValue`
 * objects rather than strings.
 */
beforeEach(function () {
    $this->translator = new RecordTranslator;
    $this->records = nightwatchRecords();
});

it('produces a record of every type Nightwatch emits', function () {
    expect(array_keys($this->records))->toEqualCanonicalizing([
        'request', 'exception', 'log', 'query', 'outgoing-request', 'cache-event',
        'mail', 'notification', 'queued-job', 'command', 'job-attempt', 'scheduled-task',
    ]);
});

it('maps each Nightwatch record type onto its Prism telemetry type', function (string $t, string $type) {
    expect($this->translator->translate($this->records[$t]))
        ->not->toBeNull()
        ->and($this->translator->translate($this->records[$t])['type'])->toBe($type);
})->with([
    ['request', 'request'],
    ['exception', 'exception'],
    ['log', 'log'],
    ['query', 'query'],
    ['job-attempt', 'job'],
    ['queued-job', 'job'],
    ['scheduled-task', 'schedule'],
    ['command', 'command'],
    ['mail', 'mail'],
    ['notification', 'notification'],
    ['cache-event', 'span'],
    ['outgoing-request', 'span'],
]);

it('lifts the record globals onto the Prism envelope', function () {
    $event = $this->translator->translate($this->records['log']);

    expect($event['trace_id'])->toBe('a1b2c3d4e5f60718293a4b5c6d7e8f90')
        // The log fixture's datetime is fixed, so the whole conversion is
        // assertable: a float microtime becomes an ISO-8601 UTC string, and it
        // keeps its microseconds — a span, a query and the log line between
        // them routinely land in the same second.
        ->and($event['timestamp'])->toBe('2026-08-20T09:15:30.123456+00:00')
        // Nightwatch's execution id is the id of the request or command the
        // signal happened inside, which is what Prism correlates on.
        ->and($event['request_id'])->toBe('execution-1234')
        ->and($event['user_id'])->toBeNull();
});

it('leaves a request record without a request id, because it IS the execution', function () {
    // Nightwatch's request record carries no `execution_id` — every *other*
    // record points at it. Filling this in needs the execution state, which is
    // the ingest's to hold, not a pure translation's to invent.
    expect($this->records['request'])->not->toHaveKey('execution_id')
        ->and($this->translator->translate($this->records['request'])['request_id'])->toBe('');
});

it('resolves the deferred and enum-shaped record values into plain data', function () {
    // Nightwatch defers the expensive globals behind a LazyValue, which resolves
    // when *its own* ingest JSON-encodes the record. Prism does not encode here,
    // so an unresolved value would travel as an object and reach the wire as
    // whatever json_encode later made of it, far from anything that explains it.
    $raw = $this->records['job-attempt'];

    expect($raw['attempt_id'])->toBeObject()
        ->and($raw['queries'])->toBeObject()
        ->and($this->records['log']['execution_stage'])->toBeObject();

    $event = $this->translator->translate($raw);

    array_walk_recursive($event['payload'], function ($value) {
        expect($value)->not->toBeObject();
    });

    expect($event['payload']['queries'])->toBe(0)
        ->and($this->translator->translate($this->records['log'])['payload']['execution_stage'])->toBeString();
});

it('maps a request onto the columns the requests table already has', function () {
    $payload = $this->translator->translate($this->records['request'])['payload'];

    expect($payload['method'])->toBe('GET')
        // Nightwatch records the whole URL; the columns hold its two halves
        // separately. `path` is the dimension the Stream tab filters and scans
        // by, so the query is beside it rather than inside it — and it is a
        // column at all because the detail screen's Query parameters panel used
        // to parse it back off `path`, which by construction never has one.
        ->and($payload['path'])->toBe('/orders/5')
        ->and($payload['query_string'])->toBe('include=lines')
        // ...and spells a route pattern with a leading slash, where Laravel's
        // own Route::uri() — and every row already in the table — does not.
        ->and($payload['route'])->toBe('orders/{order}')
        ->and($payload['status'])->toBe(200)
        ->and($payload['query_count'])->toBe(3)
        ->and($payload['ip'])->toBe('127.0.0.1')
        ->and($payload['user_agent'])->toBe('PrismTest/1.0')
        ->and($payload['memory_mb'])->toBeGreaterThan(0.0);
});

it('converts every duration from microseconds and every memory figure from bytes', function () {
    // Nightwatch measures in microseconds and bytes; every Prism column is
    // milliseconds and megabytes. An unconverted record reads as a
    // thousand-fold outlier, which looks exactly like a real incident.
    expect($this->records['query']['duration'])->toBe(12500)
        ->and($this->translator->translate($this->records['query'])['payload']['duration_ms'])->toBe(12.5);

    $request = $this->translator->translate($this->records['request'])['payload'];

    expect($request['memory_mb'])->toBe(round($this->records['request']['peak_memory_usage'] / 1048576, 3));
});

it('folds both job halves onto the jobs table, each naming its phase', function () {
    $attempted = $this->translator->translate($this->records['job-attempt']);
    $queued = $this->translator->translate($this->records['queued-job']);

    expect($attempted['type'])->toBe('job')
        ->and($attempted['payload']['phase'])->toBe('attempted')
        ->and($attempted['payload']['job_class'])->toBe('App\Jobs\PriceOrder')
        ->and($attempted['payload']['queue'])->toBe('default')
        ->and($attempted['payload']['connection'])->toBe('redis')
        ->and($attempted['payload']['uuid'])->toBe('e6d1f0a2-1c4b-4f52-9f3a-77c9a1b2c3d4')
        ->and($attempted['payload']['status'])->toBe('processed')
        ->and($attempted['payload']['attempts'])->toBe(2);

    expect($queued['type'])->toBe('job')
        ->and($queued['payload']['phase'])->toBe('queued')
        ->and($queued['payload']['job_class'])->toBe('App\Jobs\PriceOrder')
        // A job that has only just been queued has no outcome yet, so it claims
        // no status rather than reporting a made-up one.
        ->and($queued['payload'])->not->toHaveKey('status');
});

it('reads a released job as the retry it is', function () {
    $record = [...$this->records['job-attempt'], 'status' => 'released'];

    expect($this->translator->translate($record)['payload']['status'])->toBe('retried');
});

it('never leaves the job rollup grouping keys blank on an attempt', function () {
    // `rollup_jobs` populates on insert and groups by (queue, status). A blank
    // either side is not an error anywhere — it is a group of its own, quietly
    // counted, drawn on the Queues panel as a row with no name. The sensor
    // fills both on every attempt, and this is what says so from a real record
    // rather than from the sensor's source.
    $payload = $this->translator->translate($this->records['job-attempt'])['payload'];

    expect($payload['queue'])->not->toBe('')
        ->and($payload['status'])->not->toBe('');
});

it('carries a failed attempt exception preview into the column the failed-jobs table reads', function () {
    $record = [
        ...$this->records['job-attempt'],
        'status' => 'failed',
        'exception_preview' => 'RuntimeException: Order 5 could not be priced.',
    ];

    $payload = $this->translator->translate($record)['payload'];

    expect($payload['status'])->toBe('failed')
        ->and($payload['exception'])->toBe('RuntimeException: Order 5 could not be priced.');
});

it('drops a scheduled task that was skipped, because it never ran', function () {
    // The `schedules` table records an exit code, and both codes a skip could
    // be given are a lie: zero draws a green tick in the history strip for a run
    // that never started, non-zero reports a failure nothing failed at.
    $skipped = [...$this->records['scheduled-task'], 'status' => 'skipped', 'duration' => 0];

    expect($this->translator->translate($skipped))->toBeNull()
        ->and($this->translator->translate($this->records['scheduled-task']))->not->toBeNull();
});

it('turns a scheduled task status back into the exit code the crons screen counts', function () {
    $processed = $this->translator->translate($this->records['scheduled-task'])['payload'];
    $failed = $this->translator->translate([...$this->records['scheduled-task'], 'status' => 'failed'])['payload'];

    expect($processed['command'])->toBe('php -v')
        ->and($processed['expression'])->toBe('*/5 * * * *')
        ->and($processed['host'])->toBe('worker-01')
        ->and($processed['exit_code'])->toBe(0)
        ->and($failed['exit_code'])->toBe(1);
});

it('turns a cache event into the cache span lane, operation and all', function () {
    $event = $this->translator->translate($this->records['cache-event']);

    expect($event['type'])->toBe('span')
        // The record's own `type` is the cache operation; the spans column of
        // that name is the waterfall lane. The column wins, and the operation
        // is re-exposed where the lane has always carried it.
        ->and($this->records['cache-event']['type'])->toBe('hit')
        ->and($event['payload']['type'])->toBe('cache')
        ->and($event['payload']['operation'])->toBe('hit')
        ->and($event['payload']['store'])->toBe('redis')
        ->and($event['payload']['key'])->toBe('orders:5')
        ->and($event['payload']['name'])->toBe('cache:hit orders:5 (redis)')
        // Identity and lineage need the active OTel span, and an offset needs
        // the execution's start: both belong to the ingest (US-015).
        ->and($event['payload'])->not->toHaveKey('span_id')
        ->and($event['payload'])->not->toHaveKey('parent_span_id')
        ->and($event['payload'])->not->toHaveKey('offset_ms');
});

it('turns an outgoing request into the http span lane', function () {
    $payload = $this->translator->translate($this->records['outgoing-request'])['payload'];

    expect($payload['type'])->toBe('http')
        ->and($payload['name'])->toBe('GET api.example.com/v1/things')
        ->and($payload['method'])->toBe('GET')
        ->and($payload['host'])->toBe('api.example.com')
        ->and($payload['path'])->toBe('/v1/things')
        ->and($payload['status'])->toBe(201)
        ->and($payload['duration_ms'])->toBe(round($this->records['outgoing-request']['duration'] / 1000, 3));
});

it('maps an exception and a log onto their existing columns', function () {
    $exception = $this->translator->translate($this->records['exception'])['payload'];

    expect($exception['class'])->toBe('RuntimeException')
        ->and($exception['message'])->toBe('Order 5 could not be priced.')
        ->and($exception['line'])->toBeInt()
        // A UInt8 column, and ClickHouse is fussier about a JSON boolean than
        // PHP is.
        ->and($exception['handled'])->toBe(1);

    $log = $this->translator->translate($this->records['log'])['payload'];

    expect($log['level'])->toBe('warning')
        ->and($log['message'])->toBe('Disk almost full')
        // Already JSON-encoded by the sensor; re-encoding would double-escape it.
        ->and($log['context'])->toBe('{"free":"2%"}');
});

it('carries every remaining record field in the payload, and no envelope field twice', function () {
    $record = $this->records['query'];
    $payload = $this->translator->translate($record)['payload'];

    // Fields with no column today are not lost work — they are the columns
    // US-006 and US-007 add without the client changing.
    expect($payload['deploy'])->toBe('deploy-7')
        ->and($payload['server'])->toBe('web-01')
        ->and($payload)->toHaveKey('_group')
        ->and($payload)->toHaveKey('execution_source')
        ->and($payload)->toHaveKey('connection_type')
        ->and($payload['sql'])->toBe('select * from "orders" where "id" = ?');

    foreach (['v', 't', 'timestamp', 'trace_id', 'user', 'execution_id'] as $consumed) {
        expect($payload)->not->toHaveKey($consumed);
    }
});

it('drops a record type Prism has no counterpart for', function () {
    // Nightwatch's `user` record is an identity upsert, not a telemetry event:
    // there is no table for it, so it is dropped and counted, never fatal.
    expect($this->translator->translate(['v' => 1, 't' => 'user', 'timestamp' => microtime(true), 'id' => '1']))->toBeNull()
        ->and($this->translator->translate(['v' => 1, 't' => 'lazy-load', 'timestamp' => microtime(true)]))->toBeNull()
        ->and($this->translator->translate([]))->toBeNull();
});

it('gates the record version PER TYPE, because an exception record is not at version 1', function () {
    // The obvious reading — `v === 1` for everything — drops every exception
    // the host ever reports, silently and forever.
    expect($this->records['exception']['v'])->toBe(3)
        ->and($this->translator->translate($this->records['exception']))->not->toBeNull()
        ->and($this->translator->translate([...$this->records['exception'], 'v' => 1]))->toBeNull()
        ->and($this->translator->translate([...$this->records['log'], 'v' => 3]))->toBeNull();
});

it('drops a record whose timestamp cannot be read rather than inventing one', function () {
    expect($this->translator->translate([...$this->records['log'], 'timestamp' => null]))->toBeNull()
        ->and($this->translator->translate([...$this->records['log'], 'timestamp' => 'yesterday']))->toBeNull();
});

it('translates to the same event twice, because it reads nothing but the record', function () {
    foreach ($this->records as $record) {
        expect($this->translator->translate($record))->toEqual($this->translator->translate($record));
    }
});
