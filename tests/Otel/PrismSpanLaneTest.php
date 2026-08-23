<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Misakstvanu\Prism\Transport\Transport;

/**
 * The span lane end to end, in a host with all three providers registered in
 * the order `installed.json` forces (US-015).
 *
 * `PrismSpanProcessorTest` proves what the processor makes of a span. This file
 * proves the two things that test cannot: that the processor is **installed on
 * the tracer upstream built** — through the `traces.processors` slot, so
 * keepsuit keeps its resource, its sampler and its propagators — and that a
 * real request through the kernel therefore leaves a nested waterfall in the
 * batch Prism ships.
 *
 * That distinction has bitten this run twice already: the derived
 * `opentelemetry.*` keys are read while upstream BOOTS, and upstream boots
 * before this package, so a key written at the wrong moment is a config value
 * that reads correctly and is consulted by nobody.
 */
beforeEach(function () {
    // The application's boot has already bound the real transport as a
    // singleton, so the recorder goes in afterwards — a lifecycle test that
    // quietly ran against a real `HttpTransport` would read as "no telemetry
    // was produced" rather than as a broken double.
    $this->transport = new SpanLaneRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

it('registers the processor in the slot upstream reads, rather than replacing the tracer', function () {
    expect(config('opentelemetry.traces.processors'))->toBe([PrismSpanProcessor::class]);
});

it('reports the lane as configured, in a host with all three providers', function () {
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    config(['prism.endpoint' => 'https://prism.test/api/ingest']);

    // The `ingest` line says records reach Prism; this one says whether they
    // reach it as a tree. Read here rather than in the config-level suite
    // because only this ordering — upstream registering and booting first —
    // proves the key was written in time to be read.
    $this->artisan('prism:check')
        ->expectsOutputToContain('otel spans')
        ->assertExitCode(0);
});

it('ships a nested waterfall for a real request', function () {
    // A REAL query rather than a hand-opened span: the child has to come from
    // the instrumentation upstream registered, or this asserts nothing about
    // the wiring under test.
    Route::get('/prism-span-lane-probe', function () {
        DB::select('select 1 as one');

        return 'ok';
    });

    $this->get('/prism-span-lane-probe')->assertOk();

    $spans = spanLaneShipped($this->transport->sent);

    // The request root and the query the route ran under it.
    expect(count($spans))->toBeGreaterThanOrEqual(2);

    $byName = [];

    foreach ($spans as $span) {
        $byName[(string) $span['payload']['name']] = $span;
    }

    expect($byName)->toHaveKey('SELECT')
        ->and($byName)->toHaveKey('GET /prism-span-lane-probe');

    $root = $byName['GET /prism-span-lane-probe'];
    $query = $byName['SELECT'];

    // The whole point of the lane: a parent id naming a span that IS in the
    // trace, so the server's depth-first walk finds a child rather than a
    // second root.
    expect($root['payload']['parent_span_id'])->toBe('')
        ->and($query['payload']['parent_span_id'])->toBe($root['payload']['span_id'])
        ->and($query['trace_id'])->toBe($root['trace_id'])
        ->and($query['payload']['type'])->toBe('db')
        ->and($root['payload']['type'])->toBe('ctrl');
});

it('joins a cache event to the tree the instrumented spans built', function () {
    // OTel emits no cache span at all — upstream's cache instrumentation only
    // calls addEvent(), and a span EVENT has no Prism column to land in — so
    // the `--color-span-cache` lane is the capture engine's own `cache-event`
    // record, parented to whatever span was active when the read happened.
    Route::get('/prism-span-cache-probe', function () {
        Cache::get('orders:5');
        Cache::put('orders:5', ['id' => 5], 60);

        return 'ok';
    });

    $this->get('/prism-span-cache-probe')->assertOk();

    $spans = spanLaneShipped($this->transport->sent);
    $roots = [];
    $cache = [];

    foreach ($spans as $span) {
        // `_group` is a capture-engine record field, so its presence is what
        // says this span came from a translated `cache-event` rather than from
        // the SDK — which is the whole claim, the SDK producing no cache span
        // at all.
        if ($span['payload']['type'] === 'cache' && isset($span['payload']['_group'])) {
            $cache[] = $span;
        } elseif ($span['payload']['type'] === 'ctrl' && $span['payload']['parent_span_id'] === '') {
            $roots[] = $span;
        }
    }

    expect($cache)->not->toBeEmpty()
        ->and($roots)->toHaveCount(1);

    foreach ($cache as $span) {
        expect($span['payload']['parent_span_id'])->toBe($roots[0]['payload']['span_id'])
            // The trace id moves with the parent, or the row names a parent
            // that is not in its own trace and the server's tree assembly drops
            // it to a root.
            ->and($span['trace_id'])->toBe($roots[0]['trace_id'])
            ->and($span['payload']['span_id'])->toMatch('/^[0-9a-f]{16}$/');
    }
});

/**
 * Every `span` event in every batch the transport was handed. Uniquely named
 * because Pest loads every test file into one process.
 *
 * @param  list<array<string, mixed>>  $envelopes
 * @return list<array<string, mixed>>
 */
function spanLaneShipped(array $envelopes): array
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

/**
 * A transport that keeps every batch instead of sending it, so a lifecycle test
 * can read what the request actually produced.
 */
final class SpanLaneRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $batch): bool
    {
        $this->sent[] = $batch;

        return true;
    }
}
