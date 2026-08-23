<?php

declare(strict_types=1);

use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Flush\SpoolScheduler;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RecordTranslator;
use Misakstvanu\Prism\Nightwatch\RedactRules;
use Misakstvanu\Prism\Nightwatch\RejectRules;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Misakstvanu\Prism\Otel\SpanFlush;
use Misakstvanu\Prism\Otel\SpanLineage;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Transport\Transport;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * The span lane's parent/child tree (US-015).
 *
 * This is the story the whole OpenTelemetry decision exists for. The capture
 * engine reports a cache read, a query and an outgoing call on COMPLETION
 * only — no start hook, nothing that says what was open at the time — so any
 * decorator over those records can produce flat siblings sharing a trace id
 * and nothing else. An OTel span carries a parent span id by construction.
 *
 * Every case below drives a REAL `TracerProvider` with the processor installed
 * the way upstream installs it, rather than handing the processor a fabricated
 * span: the claim is about ids the SDK mints and hands out, and a hand-built
 * span data object would agree with whatever the person writing it believed.
 *
 * The contract that matters is one equality — **a span's emitted `span_id` is
 * the id its children name in `parent_span_id`**. Break it and nothing errors:
 * the server's tree assembly drops every child to a root and the waterfall goes
 * flat, which looks exactly like an application that does no nested work.
 */

/**
 * A live client whose buffer the test can read, plus a tracer whose only
 * processor is Prism's. Uniquely named because Pest loads every test file into
 * one process.
 *
 * @return array{0: EventBuffer, 1: TracerInterface, 2: SpanLineage}
 */
function spanLaneHarness(): array
{
    Recursion::reset();

    $buffer = new EventBuffer(100);
    $lineage = new SpanLineage;

    app()->instance(EventBuffer::class, $buffer);
    app()->instance(PrismServiceProvider::ACTIVE, true);
    app()->forgetInstance(RejectRules::class);

    $tracer = TracerProvider::builder()
        ->addSpanProcessor(new PrismSpanProcessor(app(), $lineage))
        ->build()
        ->getTracer('prism-span-lane-test');

    return [$buffer, $tracer, $lineage];
}

/**
 * The buffered span events, in the order they were recorded, keyed by name so
 * an assertion reads as a claim about a span rather than about an index.
 *
 * @return array<string, array<string, mixed>>
 */
function spanLaneEvents(EventBuffer $buffer): array
{
    $events = [];

    foreach ($buffer->all()['span'] ?? [] as $event) {
        /** @var array<string, mixed> $payload */
        $payload = $event['payload'];

        $events[(string) $payload['name']] = $event;
    }

    return $events;
}

it('describes a request → query → outgoing chain as a real tree', function () {
    [$buffer, $tracer] = spanLaneHarness();

    // The shape upstream produces for one request: a SERVER span the whole
    // request hangs from, with the query and the outgoing call opened while it
    // is active. Nothing here tells any span who its parent is — that is the
    // point.
    $root = $tracer->spanBuilder('GET /orders')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
    $scope = $root->activate();

    $query = $tracer->spanBuilder('SELECT')
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute('db.system.name', 'postgresql')
        ->setAttribute('db.query.text', 'select * from "orders"')
        ->startSpan();
    $query->end();

    // Upstream names this span `POST` and nothing else — the route half of its
    // name is null unless the host installed a resolver — so the lane rebuilds
    // Prism's own label from the attributes; see the naming test below.
    $outgoing = $tracer->spanBuilder('POST')
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute('http.request.method', 'POST')
        ->setAttribute('server.address', 'api.stripe.com')
        ->setAttribute('url.path', '/v1/charges')
        ->setAttribute('url.full', 'https://api.stripe.com/v1/charges')
        ->startSpan();
    $outgoing->end();

    $scope->detach();
    $root->end();

    $events = spanLaneEvents($buffer);

    expect($events)->toHaveCount(3)
        ->and(array_keys($events))->toBe(['SELECT', 'POST api.stripe.com/v1/charges', 'GET /orders']);

    $rootId = $events['GET /orders']['payload']['span_id'];

    // The tree: one root, two children naming it, all three in one trace.
    expect($events['GET /orders']['payload']['parent_span_id'])->toBe('')
        ->and($events['SELECT']['payload']['parent_span_id'])->toBe($rootId)
        ->and($events['POST api.stripe.com/v1/charges']['payload']['parent_span_id'])->toBe($rootId)
        ->and($events['SELECT']['trace_id'])->toBe($events['GET /orders']['trace_id'])
        ->and($events['POST api.stripe.com/v1/charges']['trace_id'])->toBe($events['GET /orders']['trace_id']);

    // And the ids are the SDK's own, in the shape the `spans` table has always
    // held: a 16-hex span id inside a 32-hex trace id.
    expect($rootId)->toMatch('/^[0-9a-f]{16}$/')
        ->and($events['GET /orders']['trace_id'])->toMatch('/^[0-9a-f]{32}$/');

    // The lanes the waterfall draws them in.
    expect($events['GET /orders']['payload']['type'])->toBe('ctrl')
        ->and($events['SELECT']['payload']['type'])->toBe('db')
        ->and($events['POST api.stripe.com/v1/charges']['payload']['type'])->toBe('http');
});

it('nests a grandchild under its own parent, not under the root', function () {
    [$buffer, $tracer] = spanLaneHarness();

    $root = $tracer->spanBuilder('GET /orders')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
    $rootScope = $root->activate();

    $middle = $tracer->spanBuilder('OrderController@index')->startSpan();
    $middleScope = $middle->activate();

    $leaf = $tracer->spanBuilder('SELECT')->setAttribute('db.system.name', 'postgresql')->startSpan();
    $leaf->end();

    $middleScope->detach();
    $middle->end();
    $rootScope->detach();
    $root->end();

    $events = spanLaneEvents($buffer);

    // Three levels. Depth is NOT a column and never was — the server derives it
    // by walking these two ids depth-first — so what is asserted is the lineage
    // the walk reads.
    expect($events['SELECT']['payload']['parent_span_id'])
        ->toBe($events['OrderController@index']['payload']['span_id'])
        ->and($events['OrderController@index']['payload']['parent_span_id'])
        ->toBe($events['GET /orders']['payload']['span_id'])
        ->and($events['GET /orders']['payload']['parent_span_id'])->toBe('');
});

it('measures every offset from the front of the trace and every duration in milliseconds', function () {
    [$buffer, $tracer] = spanLaneHarness();

    // Fixed timestamps, so this is arithmetic rather than a race: the root
    // starts the trace, the child starts 20ms in and runs for 5ms.
    $start = 1_700_000_000_000_000_000;

    $root = $tracer->spanBuilder('GET /orders')
        ->setSpanKind(SpanKind::KIND_SERVER)
        ->setStartTimestamp($start)
        ->startSpan();
    $scope = $root->activate();

    $child = $tracer->spanBuilder('SELECT')
        ->setAttribute('db.system.name', 'postgresql')
        ->setStartTimestamp($start + 20_000_000)
        ->startSpan();
    $child->end($start + 25_000_000);

    $scope->detach();
    $root->end($start + 120_000_000);

    $events = spanLaneEvents($buffer);

    expect($events['GET /orders']['payload']['offset_ms'])->toBe(0.0)
        ->and($events['GET /orders']['payload']['duration_ms'])->toBe(120.0)
        ->and($events['SELECT']['payload']['offset_ms'])->toBe(20.0)
        ->and($events['SELECT']['payload']['duration_ms'])->toBe(5.0)
        // The envelope's own timestamp is the span's start, in the microsecond
        // ISO-8601 shape every other signal ships.
        ->and($events['GET /orders']['timestamp'])->toBe('2023-11-14T22:13:20.000000+00:00');
});

it('maps each span onto one of the waterfall lanes, and never drops one it cannot place', function (
    string $name,
    int $kind,
    array $attributes,
    string $expected,
) {
    [$buffer, $tracer] = spanLaneHarness();

    $span = $tracer->spanBuilder($name)->setSpanKind($kind)->setAttributes($attributes)->startSpan();
    $span->end();

    expect(spanLaneEvents($buffer)[$name]['payload']['type'])->toBe($expected);
})->with([
    // One rule covers SQL and Redis, because both instrumentations name the
    // system rather than the driver.
    'a query' => ['SELECT', SpanKind::KIND_CLIENT, ['db.system.name' => 'postgresql'], 'db'],
    'a redis command' => ['GET', SpanKind::KIND_CLIENT, ['db.system.name' => 'redis'], 'db'],
    'an outgoing call' => ['POST api.example.com', SpanKind::KIND_CLIENT, ['http.request.method' => 'POST', 'server.address' => 'api.example.com'], 'http'],
    // Kind-gated deliberately: the INCOMING request carries the same
    // attributes and is not an outgoing call.
    'the incoming request' => ['GET /orders', SpanKind::KIND_SERVER, ['http.request.method' => 'GET', 'url.full' => 'https://app.test/orders'], 'ctrl'],
    'a view render' => ['view render', SpanKind::KIND_INTERNAL, ['template.name' => 'orders.index'], 'resp'],
    'the framework bootstrap' => ['app bootstrap', SpanKind::KIND_INTERNAL, [], 'mw'],
    // A span in the wrong lane is a colour; a dropped span is a hole in the
    // trace, so anything unrecognised still lands.
    'anything else' => ['something nobody mapped', SpanKind::KIND_INTERNAL, [], 'ctrl'],
    'a queued job' => ['send default', SpanKind::KIND_PRODUCER, ['messaging.system' => 'redis'], 'ctrl'],
]);

it('carries the span attributes but never lets one overwrite a prism column', function () {
    [$buffer, $tracer] = spanLaneHarness();

    $span = $tracer->spanBuilder('SELECT')
        ->setAttribute('db.system.name', 'postgresql')
        ->setAttribute('db.query.text', 'select 1')
        // A field with no `spans` column is not lost work — it is a column a
        // later story can add without the client changing. One that collides
        // with a column loses, the same rule the record translator follows.
        ->setAttribute('type', 'not-a-lane')
        ->setAttribute('duration_ms', 999999)
        ->startSpan();
    $span->end();

    $payload = spanLaneEvents($buffer)['SELECT']['payload'];

    expect($payload['db.query.text'])->toBe('select 1')
        ->and($payload['type'])->toBe('db')
        ->and($payload['duration_ms'])->toBeLessThan(1000.0);
});

it('attributes a span to the user upstream recorded on it', function () {
    [$buffer, $tracer] = spanLaneHarness();

    $span = $tracer->spanBuilder('GET /orders')->setAttribute('user.id', 42)->startSpan();
    $span->end();

    $events = spanLaneEvents($buffer);

    expect($events['GET /orders']['user_id'])->toBe('42');
});

it('captures nothing while prism is shipping its own batch', function () {
    [$buffer, $tracer] = spanLaneHarness();

    // Building an envelope, spooling it and posting it are a cache write, a
    // queue dispatch and an HTTP call. Without this, sending telemetry is
    // telemetry — and upstream's instrumentations know nothing about Prism's
    // own guard, so this is where the question gets asked for the span lane.
    Recursion::suppress(function () use ($tracer): void {
        $span = $tracer->spanBuilder('POST prism.test/api/ingest')->startSpan();
        $span->end();
    });

    expect($buffer->all())->toBe([]);

    // ...and the control: the same span outside the scope does land.
    $span = $tracer->spanBuilder('POST prism.test/api/ingest')->startSpan();
    $span->end();

    expect($buffer->count())->toBe(1);
});

it('refuses an outgoing call on the http ignore list', function () {
    [$buffer, $tracer] = spanLaneHarness();

    app()->instance(RejectRules::class, new RejectRules(http: ['clickhouse']));

    $ignored = $tracer->spanBuilder('GET clickhouse')
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute('url.full', 'http://clickhouse:8123/?query=SELECT+1')
        ->startSpan();
    $ignored->end();

    $kept = $tracer->spanBuilder('GET api.example.com')
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute('url.full', 'https://api.example.com/v1/things')
        ->startSpan();
    $kept->end();

    // The engine's own record for that call is refused by the same list
    // (US-010); a span lane that ignored the ignore list would keep drawing the
    // call the workspace switched off, and on a dogfooded install would feed
    // the pipeline its own traffic.
    expect(array_keys(spanLaneEvents($buffer)))->toBe(['GET api.example.com']);
});

it('runs prism.scrub over the two attributes that can carry a value', function () {
    [$buffer, $tracer] = spanLaneHarness();

    // Once the span lane owns the query and outgoing-request signals (US-018),
    // an ATTRIBUTE is where that text lives — the `Query` and `OutgoingRequest`
    // records `RedactRules` rewrites are dropped before they are translated. A
    // scrub list that stopped applying the day the lane landed would be the
    // quietest possible way to start shipping credentials.
    config(['prism.scrub' => ['password', 'api_key']]);
    app()->instance(RedactRules::class, RedactRules::fromConfig(app('config')));

    $query = $tracer->spanBuilder('UPDATE')
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute('db.system.name', 'postgresql')
        ->setAttribute('db.query.text', 'update "users" set "password" = \'hunter2-sql\' where "id" = 1')
        ->startSpan();
    $query->end();

    $outgoing = $tracer->spanBuilder('GET')
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute('http.request.method', 'GET')
        ->setAttribute('server.address', 'api.example.com')
        ->setAttribute('url.path', '/v1/things')
        ->setAttribute('url.full', 'https://api.example.com/v1/things?api_key=hunter2-url&page=2')
        ->setAttribute('url.query', 'api_key=hunter2-url&page=2')
        ->startSpan();
    $outgoing->end();

    $events = spanLaneEvents($buffer);
    $sql = (string) $events['UPDATE']['payload']['db.query.text'];
    $url = (string) $events['GET api.example.com/v1/things']['payload']['url.full'];

    expect($sql)->not->toContain('hunter2-sql')
        // The statement keeps its shape, which is the point of redacting the
        // value rather than dropping the field.
        ->and($sql)->toContain('update "users" set')
        ->and($url)->not->toContain('hunter2-url')
        // Non-vacuous: everything benign about the same call still arrives.
        ->and($url)->toContain('page=2')
        ->and((string) $events['GET api.example.com/v1/things']['payload']['url.query'])->toContain('page=2');
});

it('emits the queries row a db span also stands for, and never one for redis', function () {
    [$buffer, $tracer] = spanLaneHarness();

    config([
        'prism.otel.enabled' => true,
        'prism.query.slow_threshold_ms' => 10,
        'opentelemetry.traces.processors' => [PrismSpanProcessor::class],
    ]);

    $root = $tracer->spanBuilder('GET /orders')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
    $scope = $root->activate();

    $start = 1_700_000_000 * 1_000_000_000;

    $query = $tracer->spanBuilder('SELECT')
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setStartTimestamp($start)
        ->setAttribute('db.system.name', 'postgresql')
        ->setAttribute('db.query.text', 'select * from "orders"')
        ->startSpan();
    $query->end($start + 25_000_000);

    // A Redis command is a `db` span — one rule covers both instrumentations,
    // because both name the system — and is NOT a row in `queries`: Prism's
    // query signal has always been `QueryExecuted`, which Redis never raises.
    $redis = $tracer->spanBuilder('GET')
        ->setSpanKind(SpanKind::KIND_CLIENT)
        ->setAttribute('db.system.name', 'redis')
        ->setAttribute('db.query.text', 'GET orders:5')
        ->startSpan();
    $redis->end();

    $scope->detach();
    $root->end();

    $queries = $buffer->all()['query'] ?? [];

    expect($queries)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = $queries[0]['payload'];

    expect($payload['sql'])->toBe('select * from "orders"')
        ->and($payload['connection'])->toBe('postgresql')
        ->and($payload['duration_ms'])->toBe(25.0)
        // 25ms against a 10ms threshold: the marker the SERVER's sampler reads
        // to keep a slow query and the trace around it.
        ->and($payload['slow'])->toBe(1);
});

it('buffers nothing when the client is not live', function () {
    Recursion::reset();

    app()->forgetInstance(PrismServiceProvider::ACTIVE);

    $buffer = new EventBuffer(100);
    app()->instance(EventBuffer::class, $buffer);

    $tracer = TracerProvider::builder()
        ->addSpanProcessor(new PrismSpanProcessor(app(), new SpanLineage))
        ->build()
        ->getTracer('prism-span-lane-test');

    $span = $tracer->spanBuilder('GET /orders')->startSpan();
    $span->end();

    // An enabled install with no token never reaches registerCapture(), so
    // nothing would ever ship what this collected.
    expect($buffer->all())->toBe([]);
});

it('closes once, and records nothing after it has', function () {
    [$buffer, , $lineage] = spanLaneHarness();

    $processor = new PrismSpanProcessor(app(), $lineage);
    $tracer = TracerProvider::builder()
        ->addSpanProcessor($processor)
        ->build()
        ->getTracer('prism-span-lane-test');

    expect($processor->forceFlush())->toBeTrue()
        ->and($processor->shutdown())->toBeTrue()
        // The specification: a second shutdown answers false.
        ->and($processor->shutdown())->toBeFalse()
        ->and($processor->forceFlush())->toBeFalse();

    $span = $tracer->spanBuilder('GET /orders')->startSpan();
    $span->end();

    expect($buffer->all())->toBe([]);
});

it('hangs a cache event off the span that was active when it happened', function () {
    [$buffer, $tracer, $lineage] = spanLaneHarness();

    $records = nightwatchRecords();
    $ingest = spanLaneIngest($buffer, $lineage);

    $root = $tracer->spanBuilder('GET /orders')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
    $scope = $root->activate();

    // OTel emits no cache span at all — upstream's cache instrumentation only
    // calls addEvent(), and a span EVENT has no Prism column to land in — so
    // the `--color-span-cache` lane is the capture engine's own record, joined
    // to the tree here.
    $ingest->write($records['cache-event']);

    $scope->detach();
    $root->end();

    $events = spanLaneEvents($buffer);
    $cache = null;

    foreach ($events as $event) {
        if ($event['payload']['type'] === 'cache') {
            $cache = $event;
        }
    }

    expect($cache)->not->toBeNull();
    expect($cache['payload']['parent_span_id'])->toBe($root->getContext()->getSpanId())
        // The trace id has to move with the parent: parenting only means
        // anything inside one trace, and left under the engine's own trace id
        // the row would name a parent that is not in its trace at all.
        ->and($cache['trace_id'])->toBe($root->getContext()->getTraceId())
        ->and($cache['payload']['span_id'])->toMatch('/^[0-9a-f]{16}$/')
        ->and($cache['payload']['offset_ms'])->toBeGreaterThanOrEqual(0.0);
});

it('still gives a cache event an identity of its own with no span active', function () {
    [$buffer, , $lineage] = spanLaneHarness();

    $records = nightwatchRecords();
    $ingest = spanLaneIngest($buffer, $lineage);

    $ingest->write($records['cache-event']);
    $ingest->write($records['cache-event']);

    $spans = $buffer->all()['span'] ?? [];

    // A blank span id is one every other blank-id span in the trace is
    // indistinguishable from, which the server reads as a pile of roots.
    expect($spans)->toHaveCount(2)
        ->and($spans[0]['payload']['span_id'])->not->toBe($spans[1]['payload']['span_id'])
        ->and($spans[0]['payload']['parent_span_id'] ?? '')->toBe('');
});

it('never re-keys a span that already named itself', function () {
    [, , $lineage] = spanLaneHarness();

    $event = $lineage->stampSpan([
        'type' => 'span',
        'timestamp' => '2026-08-21T09:00:00.000000+00:00',
        'trace_id' => 'trace-1',
        'request_id' => '',
        'user_id' => null,
        'payload' => ['span_id' => 'aaaaaaaaaaaaaaaa', 'parent_span_id' => 'bbbbbbbbbbbbbbbb'],
    ]);

    // Nothing may re-key a span whose children are already pointing at it.
    expect($event['payload']['span_id'])->toBe('aaaaaaaaaaaaaaaa')
        ->and($event['payload']['parent_span_id'])->toBe('bbbbbbbbbbbbbbbb')
        ->and($event['trace_id'])->toBe('trace-1');
});

it('remembers only a bounded number of trace origins', function () {
    $lineage = new SpanLineage;

    // A queue worker handles one trace after another forever, so the registry
    // has to be bounded or it is a leak.
    for ($i = 0; $i < 40; $i++) {
        $lineage->observe('trace-'.$i, 1_000 + $i);
    }

    expect($lineage->originNanos('trace-39'))->toBe(1_039)
        ->and($lineage->originNanos('trace-0'))->toBeNull();
});

it('keeps the earliest start it is told about, because upstream back-dates the bootstrap span', function () {
    $lineage = new SpanLineage;

    $lineage->observe('trace-1', 5_000);
    $lineage->observe('trace-1', 1_000);
    $lineage->observe('trace-1', 9_000);

    expect($lineage->originNanos('trace-1'))->toBe(1_000);
});

/**
 * An ingest writing into the given buffer, with the span lineage wired in. The
 * transport is never reached — nothing here digests — so a null one would do,
 * but the flusher is built the way the provider builds it so the shape under
 * test is the shape that ships.
 */
function spanLaneIngest(EventBuffer $buffer, SpanLineage $lineage): PrismIngest
{
    return new PrismIngest(
        $buffer,
        new RecordTranslator,
        static fn (EventBuffer $target): Flusher => new Flusher(
            app('config'),
            $target,
            app(Transport::class),
            app(BatchSpool::class),
            app(SpoolScheduler::class),
            app(SpanFlush::class),
        ),
        null,
        null,
        $lineage,
    );
}
