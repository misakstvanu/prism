<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Transport\Transport;

/**
 * One query is one row and one span, and the request still knows it ran three
 * (US-018).
 *
 * The whole story in one request. Both engines watch a query and an outgoing
 * call, so one producer has to give way — and the reason the capture engine's
 * record is dropped *here*, in Prism's own ingest, rather than through one of
 * the engine's `reject*` callbacks is a detail that would otherwise be invisible
 * until someone noticed a busy request reporting zero queries:
 *
 *   upstream's `QuerySensor::__invoke()` returns `[$record, $resolver]`, and
 *   `$this->executionState->queries++` lives INSIDE `$resolver`, which only runs
 *   at `$ingest->write($resolver())`. A reject callback returns before that.
 *
 * So the counter assertion below is not a nicety — it is the assertion that
 * says the supersession was implemented on the right side of the seam. It is
 * also non-vacuous in both directions: the route really runs three queries and
 * really makes one call, and the lane really is producing spans for them.
 *
 * `tests/Feature/SupersededRecordsTest` covers the rule itself, in every state
 * where it must NOT fire.
 */
beforeEach(function () {
    $this->transport = new SupersededCountersTransport;

    app()->instance(Transport::class, $this->transport);

    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

    Route::get('/prism-superseded-probe', function () {
        DB::select('select 1 as one');
        DB::select('select 2 as two');
        DB::select('select 3 as three');

        Http::get('https://api.example.com/v1/things?page=2');

        return 'ok';
    });
});

it('reports three queries and one outgoing call on a request whose records were dropped', function () {
    $this->get('/prism-superseded-probe')->assertOk();

    $request = supersededEventsOfType($this->transport->sent, 'request')[0] ?? null;

    expect($request)->not->toBeNull();

    /** @var array<string, mixed> $payload */
    $payload = $request['payload'];

    // The counters the engine kept while its records were thrown away.
    expect($payload['queries'])->toBe(3)
        ->and($payload['outgoing_requests'])->toBe(1)
        ->and($payload['query_count'])->toBe(3);
});

it('stores one query row and one db span per query, from one producer', function () {
    $this->get('/prism-superseded-probe')->assertOk();

    $queries = supersededEventsOfType($this->transport->sent, 'query');
    $spans = supersededEventsOfType($this->transport->sent, 'span');

    // `_group` is a capture-engine record field, so its presence is what says
    // an event came from a record rather than from a span. There must be none:
    // the engine's own query record is exactly what the lane supersedes.
    expect(supersededFromRecords($queries))->toBe([]);

    // `bindings` is the legacy `Capture\QueryCapture` listener, which US-021
    // deletes wholesale. Until then it emits beside the lane, and this file is
    // about the two NEW producers rather than about that one.
    $lane = array_values(array_filter(
        $queries,
        static fn (array $event): bool => ! array_key_exists('bindings', $event['payload'])
            && ! array_key_exists('_group', $event['payload']),
    ));

    $dbSpans = array_values(array_filter(
        supersededNotFromRecords($spans),
        static fn (array $event): bool => $event['payload']['type'] === 'db',
    ));

    expect($lane)->toHaveCount(3)
        ->and($dbSpans)->toHaveCount(3);

    foreach ($lane as $event) {
        /** @var array<string, mixed> $payload */
        $payload = $event['payload'];

        expect($payload['sql'])->toContain('select')
            // The span carries the driver rather than Laravel's connection
            // name; no attribute names the connection a query ran on.
            ->and($payload['connection'])->toBe('sqlite')
            ->and($payload['duration_ms'])->toBeFloat()
            // The marker the SERVER's sampler reads to keep a slow query and
            // the trace around it, whatever the workspace's rules say.
            ->and($payload['slow'])->toBe(0)
            // Keyed onto OpenTelemetry's trace (US-017), which is what puts the
            // row beside the span it was derived from on the detail screen.
            ->and($event['trace_id'])->toMatch('/^[0-9a-f]{32}$/');
    }
});

it('stores one http span for the outgoing call, not the engines and the lanes', function () {
    $this->get('/prism-superseded-probe')->assertOk();

    $spans = supersededEventsOfType($this->transport->sent, 'span');

    $fromRecord = array_values(array_filter(
        supersededFromRecords($spans),
        static fn (array $event): bool => $event['payload']['type'] === 'http',
    ));

    $fromLane = array_values(array_filter(
        supersededNotFromRecords($spans),
        static fn (array $event): bool => $event['payload']['type'] === 'http'
            && ! array_key_exists('method', $event['payload']),
    ));

    expect($fromRecord)->toBe([])
        ->and($fromLane)->toHaveCount(1)
        // Named the way Prism has always named an outgoing call. Upstream calls
        // this span `GET` and nothing more — the route half of its name is null
        // unless the host installed a resolver — so a supersession that took
        // the SDK's name would cost the waterfall the only label that says who
        // was called.
        ->and($fromLane[0]['payload']['name'])->toBe('GET api.example.com/v1/things')
        // Redacted the same way the engine's record is: `prism.scrub` reaches
        // the span attribute, or the list covers one producer out of two.
        ->and($fromLane[0]['payload']['url.full'])->toContain('page=2');
});

it('keeps the engines own records when the span lane is switched off', function () {
    config(['prism.otel.enabled' => false]);

    $this->get('/prism-superseded-probe')->assertOk();

    $queries = supersededFromRecords(supersededEventsOfType($this->transport->sent, 'query'));
    $outgoing = array_values(array_filter(
        supersededFromRecords(supersededEventsOfType($this->transport->sent, 'span')),
        static fn (array $event): bool => $event['payload']['type'] === 'http',
    ));

    // A host without the span lane still gets the engine's flat records — the
    // pre-US-018 behaviour, unchanged.
    expect($queries)->toHaveCount(3)
        ->and($outgoing)->toHaveCount(1);
});

/**
 * Every event of one telemetry type across every batch the transport was
 * handed. Uniquely named because Pest loads every test file into one process.
 *
 * @param  list<array<string, mixed>>  $envelopes
 * @return list<array<string, mixed>>
 */
function supersededEventsOfType(array $envelopes, string $type): array
{
    $events = [];

    foreach ($envelopes as $envelope) {
        /** @var list<array<string, mixed>> $batch */
        $batch = $envelope['events'];

        foreach ($batch as $event) {
            if ($event['type'] === $type) {
                $events[] = $event;
            }
        }
    }

    return $events;
}

/**
 * The events that came from a capture-engine record, told apart by `_group` —
 * a record global that rides the translator's passthrough and that nothing else
 * in the pipeline produces.
 *
 * @param  list<array<string, mixed>>  $events
 * @return list<array<string, mixed>>
 */
function supersededFromRecords(array $events): array
{
    return array_values(array_filter(
        $events,
        static fn (array $event): bool => array_key_exists('_group', $event['payload']),
    ));
}

/**
 * @param  list<array<string, mixed>>  $events
 * @return list<array<string, mixed>>
 */
function supersededNotFromRecords(array $events): array
{
    return array_values(array_filter(
        $events,
        static fn (array $event): bool => ! array_key_exists('_group', $event['payload']),
    ));
}

/** A transport that keeps every batch instead of sending it. */
final class SupersededCountersTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $batch): bool
    {
        $this->sent[] = $batch;

        return true;
    }
}
