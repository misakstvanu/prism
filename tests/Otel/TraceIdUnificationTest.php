<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/*
 * US-017 — one trace id, and it is OpenTelemetry's.
 *
 * Two engines mint trace ids in this package now. The capture engine mints a
 * UUID per execution; the SDK mints a 32-hex W3C id per trace. Every Prism
 * detail screen correlates a request with the spans, logs, queries and
 * exceptions it produced by that one column, so a console shows a third of a
 * trace if the two disagree — and it shows it without any error anywhere, which
 * is what makes this worth a suite of its own.
 *
 * OTel wins because its id is the one that propagates: over `traceparent` to
 * the next service, and (via Laravel's context) into a queued job's payload.
 *
 * This file runs in the three-provider host, in the order `installed.json`
 * forces, and drives REAL requests through the kernel — the claim is about what
 * a running application ships, and the one moment that is easy to get wrong is
 * invisible from any smaller fixture: the engine writes its `request` record
 * from `terminate()`, by which time upstream's middleware has already ended the
 * request span and detached its scope in its own `finally`. A rewrite that
 * could only read the *active* span would leave the single row every detail
 * screen is keyed on carrying a different id from all of its children.
 */
beforeEach(function () {
    // The application's boot has already bound the real transport as a
    // singleton, so the recorder goes in afterwards — see PrismSpanLaneTest.
    $this->transport = new TraceUnificationRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

it('keys every signal of one execution on the OpenTelemetry trace', function () {
    Route::get('/prism-trace-unification-probe', function () {
        // One of each lane the console correlates, produced for real rather
        // than hand-fed: a query, a cache read, a log line and a reported
        // exception, inside a request that is itself a record.
        DB::select('select 1 as one');
        Cache::get('orders:5');
        Log::warning('trace unification probe');
        report(new RuntimeException('trace unification probe failure'));

        return 'ok';
    });

    $this->get('/prism-trace-unification-probe')->assertOk();

    $events = traceUnificationShipped($this->transport->sent);

    $byType = [];

    foreach ($events as $event) {
        $byType[$event['type']][] = $event['trace_id'];
    }

    // Every lane the story names, and the request that roots them.
    expect(array_keys($byType))->toContain('request', 'span', 'query', 'log', 'exception');

    $traced = array_values(array_filter(
        array_column($events, 'trace_id'),
        static fn (string $traceId): bool => $traceId !== '',
    ));

    expect($traced)->not->toBeEmpty();

    // One id, and it is a W3C trace id rather than the engine's UUID. Asserted
    // as a set so a lane that quietly kept its own id fails here rather than in
    // whichever screen a reader opens next.
    expect(array_values(array_unique($traced)))->toHaveCount(1);
    expect($traced[0])->toMatch('/^[0-9a-f]{32}$/');

    // Spelt out separately because it is the assertion the obvious
    // implementation fails: the request record is written from `terminate()`,
    // after the request span has ended, so it is the row most likely to be left
    // behind under the engine's own id.
    expect($byType['request'])->toBe([$traced[0]]);
});

it('leaves a signal that belongs to no execution out of the trace', function () {
    Route::get('/prism-trace-metric-probe', fn () => 'ok');

    $this->get('/prism-trace-metric-probe')->assertOk();

    $metrics = array_values(array_filter(
        traceUnificationShipped($this->transport->sent),
        static fn (array $event): bool => $event['type'] === 'replica_metric',
    ));

    expect($metrics)->not->toBeEmpty();

    // CPU, memory and queue depth are facts about the PROCESS, sampled on a
    // wall-clock interval; the execution that happened to be open when the
    // interval elapsed had nothing to do with them, so filing them under its
    // trace would be a correlation the console then draws. A record that names
    // no trace is not given one.
    foreach ($metrics as $metric) {
        expect($metric['trace_id'])->toBe('');
    }
});

it('publishes the trace into laravel context, so it survives a queue boundary', function () {
    $dehydrated = null;

    Route::get('/prism-trace-context-probe', function () use (&$dehydrated) {
        // Exactly what Laravel's own ContextServiceProvider puts into every
        // queued job's payload, taken at the moment a job would be dispatched.
        $dehydrated = Context::dehydrate();

        return TraceContext::otelTraceId() ?? '';
    });

    $traceId = $this->get('/prism-trace-context-probe')->assertOk()->getContent();

    expect($traceId)->toMatch('/^[0-9a-f]{32}$/')
        ->and($dehydrated)->toBeArray();

    // The other side of the boundary: a worker process with no request span of
    // its own, whose context Laravel hydrates from the payload on
    // `JobProcessing`. The trace still answers, which is what keeps a job's
    // records under the trace that dispatched it.
    Context::flush();

    expect(TraceContext::otelTraceId())->toBeNull();

    Context::hydrate($dehydrated);

    expect(TraceContext::otelTraceId())->toBe($traceId)
        ->and(TraceContext::traceId())->toBe($traceId);
});

/**
 * Every event in every batch the transport was handed. Uniquely named because
 * Pest loads every test file into one process.
 *
 * @param  list<array<string, mixed>>  $envelopes
 * @return list<array<string, mixed>>
 */
function traceUnificationShipped(array $envelopes): array
{
    $events = [];

    foreach ($envelopes as $envelope) {
        /** @var list<array<string, mixed>> $batch */
        $batch = $envelope['events'];

        foreach ($batch as $event) {
            $events[] = $event;
        }
    }

    return $events;
}

/** A transport that keeps every batch instead of sending it. */
final class TraceUnificationRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $batch): bool
    {
        $this->sent[] = $batch;

        return true;
    }
}
