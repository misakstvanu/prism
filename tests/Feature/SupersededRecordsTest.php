<?php

declare(strict_types=1);

use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Flush\SpoolScheduler;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RecordTranslator;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Misakstvanu\Prism\Otel\SpanFlush;
use Misakstvanu\Prism\Otel\SpanLane;
use Misakstvanu\Prism\Otel\SpanLineage;
use Misakstvanu\Prism\Transport\Transport;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Which of the capture engine's records the span lane supersedes (US-018).
 *
 * Both engines watch a query and an outgoing HTTP call. Left alone, one query
 * lands as a `queries` row *and* a `db` span while one outgoing call lands as
 * two `http` spans — the same work counted twice on the screen that exists to
 * show what a request did. So one producer gives way, and it is the record.
 *
 * **The drop happens in Prism's ingest, never in one of the engine's `reject*`
 * callbacks**, and that is the whole reason this file exists rather than a
 * couple of lines in `RejectRules`. Upstream's query sensor returns
 * `[$record, $resolver]` and `$this->executionState->queries++` lives *inside*
 * the resolver, which only runs at `$ingest->write($resolver())`. A reject
 * callback returns before that — so rejecting a query upstream would not merely
 * drop the record, it would report a request that ran forty queries as having
 * run none. `SupersededCountersTest` in `tests/Otel` is the other half of that
 * claim, driven through a real request.
 *
 * Every refusal below is a condition on the *span existing*: the lane switched
 * off, Prism's processor not on the tracer, or no OpenTelemetry trace open.
 * Miss any one of them and the record is dropped with nothing produced in its
 * place, which is a signal gone with nothing anywhere saying so.
 */
beforeEach(function () {
    config([
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
        'prism.otel.enabled' => true,
        'prism.query.slow_threshold_ms' => 100,
        'opentelemetry.traces.processors' => [PrismSpanProcessor::class],
    ]);

    $this->records = nightwatchRecords();
    $this->transport = new SupersededRecordingTransport;
});

afterEach(function () {
    supersededScopeClose();
});

it('drops the query and outgoing-request records the span lane already reported', function () {
    supersededActivateTrace();

    $buffer = new EventBuffer(100);
    $ingest = supersededIngest($buffer, $this->transport);

    $ingest->write($this->records['query']);
    $ingest->write($this->records['outgoing-request']);

    // Not a fault, so not a drop: `dropped()` counts records that had no honest
    // event to become, and a supersession is a decision.
    expect($buffer->all())->toBe([])
        ->and($ingest->dropped())->toBe(0);
});

it('keeps the cache event, because OpenTelemetry emits no cache span at all', function () {
    supersededActivateTrace();

    $buffer = new EventBuffer(100);

    supersededIngest($buffer, $this->transport)->write($this->records['cache-event']);

    // Upstream's cache instrumentation only calls addEvent(), and a span EVENT
    // has no Prism column to land in — so the engine's record is the cache
    // lane's only producer and dropping it would leave a hole in every
    // waterfall. Keyed on the record's own `t`, because an outgoing request and
    // a cache event both translate to Prism's `span`: testing the translated
    // type would take this one with the other.
    expect(array_keys($buffer->all()))->toBe(['span']);
});

it('keeps both records when the host has switched the span lane off', function () {
    supersededActivateTrace();
    config(['prism.otel.enabled' => false]);

    $buffer = new EventBuffer(100);
    $ingest = supersededIngest($buffer, $this->transport);

    $ingest->write($this->records['query']);
    $ingest->write($this->records['outgoing-request']);

    expect(array_keys($buffer->all()))->toBe(['query', 'span']);
});

it('keeps both records when Prisms processor is not on the tracer', function () {
    supersededActivateTrace();

    // The state a host that published `config/opentelemetry.php` is in until it
    // adds the processor itself: the SDK is producing spans, and none of them
    // reach Prism. Dropping the records here would empty the Queries screen.
    config(['opentelemetry.traces.processors' => []]);

    $buffer = new EventBuffer(100);
    $ingest = supersededIngest($buffer, $this->transport);

    $ingest->write($this->records['query']);
    $ingest->write($this->records['outgoing-request']);

    expect(array_keys($buffer->all()))->toBe(['query', 'span']);
});

it('keeps both records when no OpenTelemetry trace is open', function () {
    // No `supersededActivateTrace()`: a console command (the console
    // instrumentation is off, so the engine owns commands), a query run before
    // the request span opens, a host whose SDK never started a trace. The
    // instrumentation returns early without a trace, so no span was produced
    // and the record is the only producer there is.
    $buffer = new EventBuffer(100);
    $ingest = supersededIngest($buffer, $this->transport);

    $ingest->write($this->records['query']);
    $ingest->write($this->records['outgoing-request']);

    expect(array_keys($buffer->all()))->toBe(['query', 'span']);
});

/**
 * A `PrismIngest` whose supersession rule is the real one, over a real
 * translator and a real flusher. Uniquely named because Pest loads every test
 * file into one process.
 */
function supersededIngest(EventBuffer $buffer, SupersededRecordingTransport $transport): PrismIngest
{
    return new PrismIngest(
        $buffer,
        new RecordTranslator,
        static fn (EventBuffer $target): Flusher => new Flusher(
            app('config'),
            $target,
            $transport,
            app(BatchSpool::class),
            app(SpoolScheduler::class),
            app(SpanFlush::class),
        ),
        null,
        null,
        null,
        new SpanLane(app('config'), new SpanLineage),
    );
}

/** The scope of the trace {@see supersededActivateTrace()} opened, if any. */
final class SupersededTraceScope
{
    public static mixed $scope = null;

    public static mixed $span = null;
}

/**
 * Open and activate a real OpenTelemetry span, so `SpanLane::live()` answers
 * what it answers inside an instrumented request.
 *
 * A real span rather than a stub: `live()` reads the SDK's own current context,
 * and the question under test is whether a span was really produced.
 */
function supersededActivateTrace(): void
{
    $tracer = TracerProvider::builder()->build()->getTracer('prism-superseded-test');

    SupersededTraceScope::$span = $span = $tracer->spanBuilder('GET /orders')->startSpan();
    SupersededTraceScope::$scope = $span->activate();
}

/** Close it again, so one case's trace can never make the next one pass. */
function supersededScopeClose(): void
{
    SupersededTraceScope::$scope?->detach();
    SupersededTraceScope::$span?->end();

    SupersededTraceScope::$scope = null;
    SupersededTraceScope::$span = null;
}

/** A transport that keeps what it is handed instead of sending it. */
final class SupersededRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $batch): bool
    {
        $this->sent[] = $batch;

        return true;
    }
}
