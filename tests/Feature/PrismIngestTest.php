<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Flush\SpoolScheduler;
use Misakstvanu\Prism\Jobs\SendBatchJob;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RecordTranslator;
use Misakstvanu\Prism\Otel\SpanFlush;
use Misakstvanu\Prism\Otel\SpanLineage;
use Misakstvanu\Prism\Transport\Transport;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Prism's pipeline standing behind Nightwatch's ingest interface (US-003).
 *
 * The first two tests are the ones this file exists for. Upstream,
 * `Core::finishExecution()` is `sampling ? digest() : flush()`, so on this
 * interface **`flush()` means discard and `digest()` means send** — backwards
 * from every other flush in the package. Get it the wrong way round and there
 * is no error anywhere: either every execution the sampler rejected is shipped
 * and billed, or nothing is ever shipped at all and the pipeline just looks
 * dead. So both directions are driven through a **real** `Core` rather than by
 * calling the two methods by hand, which is the only way to be sure which one
 * upstream reaches for.
 */
beforeEach(function () {
    config([
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'terminate',
        'prism.batch.queue_threshold' => 0,
    ]);

    $this->transport = new IngestRecordingTransport;
    $this->records = nightwatchRecords();
});

it('transmits nothing at all for an execution the sampler rejected', function () {
    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport);
    $core = makeNightwatchCore(ingest: $ingest);

    $ingest->write($this->records['query']);
    $ingest->write($this->records['log']);

    // Non-vacuous: there is a batch here to lose.
    expect($buffer->count())->toBe(2);

    $core->dontSample();
    $core->finishExecution();

    expect($this->transport->sent)->toBe([])
        ->and($buffer->isEmpty())->toBeTrue();
});

it('ships the batch for an execution the sampler kept', function () {
    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport);
    $core = makeNightwatchCore(ingest: $ingest);

    $ingest->write($this->records['query']);
    $ingest->write($this->records['log']);

    $core->sample(1.0);
    $core->finishExecution();

    expect($this->transport->sent)->toHaveCount(1)
        ->and($this->transport->sent[0]['events'])->toHaveCount(2)
        ->and($buffer->isEmpty())->toBeTrue();
});

it('buffers a translated record under its Prism telemetry type', function () {
    $buffer = new EventBuffer(100);

    prismIngest($buffer, $this->transport)->write($this->records['cache-event']);

    expect(array_keys($buffer->all()))->toBe(['span'])
        ->and($buffer->count())->toBe(1)
        ->and($buffer->all()['span'][0]['trace_id'])->toBe('a1b2c3d4e5f60718293a4b5c6d7e8f90');
});

it('drops and counts a record with no Prism counterpart', function () {
    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport);

    // A `user` record is an identity upsert, not a telemetry event: Prism has
    // no table for it, so the translator answers null.
    $ingest->write(['v' => 1, 't' => 'user', 'timestamp' => microtime(true), 'id' => '5']);

    expect($buffer->isEmpty())->toBeTrue()
        ->and($ingest->dropped())->toBe(1);
});

it('never lets a malformed record reach the host', function () {
    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport);

    $ingest->write([]);
    $ingest->write(['v' => 99, 't' => 'request', 'timestamp' => microtime(true)]);

    expect($buffer->isEmpty())->toBeTrue()
        ->and($ingest->dropped())->toBe(2);
});

it('builds the batch through the existing flusher, envelope and all', function () {
    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport);

    $ingest->write($this->records['request']);
    $ingest->digest();

    $envelope = $this->transport->sent[0];

    expect($envelope['v'])->toBe(1)
        ->and($envelope['app'])->toBe('demo')
        ->and($envelope['env'])->toBe('production')
        ->and($envelope['replica'])->toBe('web-1')
        ->and($envelope['events'][0]['type'])->toBe('request');
});

it('honours the configured flush strategy when it digests', function () {
    Queue::fake();
    config(['prism.batch.queue_threshold' => 1]);

    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport);

    $ingest->write($this->records['query']);
    $ingest->write($this->records['log']);
    $ingest->digest();

    // Over the threshold the flusher queues rather than sending inline — the
    // proof that digest() goes through the real Flusher and not around it.
    expect($this->transport->sent)->toBe([]);
    Queue::assertPushed(SendBatchJob::class, 1);
});

it('discards without sending when it flushes', function () {
    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport);

    $ingest->write($this->records['query']);
    $ingest->flush();

    expect($this->transport->sent)->toBe([])
        ->and($buffer->isEmpty())->toBeTrue();
});

it('ships a one-record batch immediately and leaves the shared buffer alone', function () {
    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport);

    $ingest->write($this->records['query']);
    $ingest->writeNow($this->records['exception']);

    expect($this->transport->sent)->toHaveCount(1)
        ->and($this->transport->sent[0]['events'])->toHaveCount(1)
        ->and($this->transport->sent[0]['events'][0]['type'])->toBe('exception')
        // The buffered query is still buffered: writeNow bypasses the buffer
        // rather than draining it.
        ->and($buffer->count())->toBe(1);
});

it('stamps the execution id onto a request record, which carries none of its own', function () {
    // A `request` record IS the execution, so Nightwatch gives it no
    // `execution_id` and the pure translator leaves `request_id` empty. Without
    // the stamp every row in the `requests` table has a blank key and the
    // request-detail screen cannot find its own children.
    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport, executionId: 'execution-42');

    $ingest->write($this->records['request']);

    expect($buffer->all()['request'][0]['request_id'])->toBe('execution-42');
});

it('leaves a record that named its own execution alone', function () {
    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport, executionId: 'execution-42');

    $ingest->write($this->records['query']);

    // Every record but a request points back at its execution, and that id is
    // the one to keep — stamping over it would re-attribute a queue worker's
    // records to whatever execution the shared Core happened to be on.
    expect($buffer->all()['query'][0]['request_id'])->toBe('execution-1234');
});

it('leaves the key blank rather than costing the host a request', function () {
    $buffer = new EventBuffer(100);
    $ingest = new PrismIngest(
        $buffer,
        new RecordTranslator,
        static fn (EventBuffer $target): Flusher => new Flusher(
            app('config'),
            $target,
            $this->transport,
            app(BatchSpool::class),
            app(SpoolScheduler::class),
            app(SpanFlush::class),
        ),
        static fn (): string => throw new RuntimeException('no execution state'),
    );

    $ingest->write($this->records['request']);

    expect($buffer->all()['request'][0]['request_id'])->toBe('')
        // Blank is a gap in one column; a throw here would be an error in the
        // host's own request.
        ->and($ingest->dropped())->toBe(0);
});

it('re-keys every record onto the OpenTelemetry trace that is open', function () {
    // Two engines mint trace ids: the capture engine a UUID per execution, the
    // SDK a 32-hex W3C id per trace. Every detail screen correlates by that one
    // column, so a console shows a third of a trace if they disagree — and OTel
    // wins because its id is the one that travels to the next service.
    $tracer = TracerProvider::builder()->build()->getTracer('prism-ingest-trace-test');
    $span = $tracer->spanBuilder('GET /orders')->startSpan();
    $scope = $span->activate();

    $traceId = $span->getContext()->getTraceId();

    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport, lineage: new SpanLineage);

    foreach (['request', 'log', 'query', 'exception'] as $type) {
        $ingest->write($this->records[$type]);
    }

    $scope->detach();
    $span->end();

    $written = $buffer->all();

    foreach (['request', 'log', 'query', 'exception'] as $type) {
        expect($written[$type][0]['trace_id'])->toBe($traceId);
    }

    // Non-vacuous: the fixtures arrive under an id of the engine's own, so this
    // is a rewrite rather than a coincidence.
    expect($this->records['log']['trace_id'])->not->toBe($traceId);
});

it('keeps the capture engine own trace id when OpenTelemetry has nothing to say', function () {
    // A host with the SDK switched off, a console command with no
    // instrumentation, a record raised before the first span opens: there is
    // nothing to re-key onto, so nothing is ever left unkeyed.
    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport, lineage: new SpanLineage);

    $ingest->write($this->records['log']);

    expect($buffer->all()['log'][0]['trace_id'])
        ->toBe($this->records['log']['trace_id']);
});

it('leaves a record that names no trace outside the trace', function () {
    $tracer = TracerProvider::builder()->build()->getTracer('prism-ingest-metric-test');
    $span = $tracer->spanBuilder('GET /orders')->startSpan();
    $scope = $span->activate();

    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport, lineage: new SpanLineage);

    // A replica metric is a fact about the PROCESS, sampled on a wall-clock
    // interval; the execution that happened to be open when the interval
    // elapsed had nothing to do with it, so filing it under that trace would be
    // a correlation the console then draws. The mirror of the execution-id rule
    // one method along: that one fills a blank, this one leaves one alone.
    $ingest->write([
        'v' => 1,
        't' => 'replica_metric',
        'timestamp' => microtime(true),
        'trace_id' => '',
        'cpu_percent' => 12.5,
        'memory_percent' => 40.0,
    ]);

    $scope->detach();
    $span->end();

    expect($buffer->all()['replica_metric'][0]['trace_id'])->toBe('');
});

it('pings nothing, because there is no agent to reach', function () {
    $buffer = new EventBuffer(100);
    $ingest = prismIngest($buffer, $this->transport);

    $ingest->ping();

    expect($this->transport->sent)->toBe([])
        ->and($buffer->isEmpty())->toBeTrue();
});

it('ships a full buffer rather than dropping records', function () {
    $buffer = new EventBuffer(3);
    $ingest = prismIngest($buffer, $this->transport);
    $ingest->shouldDigestWhenBufferIsFull(true);

    foreach (['query', 'log', 'cache-event', 'request'] as $t) {
        $ingest->write($this->records[$t]);
    }

    expect($this->transport->sent)->toHaveCount(1)
        ->and($this->transport->sent[0]['events'])->toHaveCount(3)
        // The fourth record opened the next batch instead of being lost.
        ->and($buffer->count())->toBe(1)
        ->and($buffer->dropped())->toBe(0);
});

it('lets a full buffer drop records when it is not to digest', function () {
    $buffer = new EventBuffer(3);
    $ingest = prismIngest($buffer, $this->transport);
    $ingest->shouldDigestWhenBufferIsFull(false);

    foreach (['query', 'log', 'cache-event', 'request'] as $t) {
        $ingest->write($this->records[$t]);
    }

    expect($this->transport->sent)->toBe([])
        ->and($buffer->count())->toBe(3)
        ->and($buffer->dropped())->toBe(1);
});

it('delegates the deprecated shouldDigest to shouldDigestWhenBufferIsFull', function () {
    $buffer = new EventBuffer(2);
    $ingest = prismIngest($buffer, $this->transport);
    $ingest->shouldDigest(false);

    $ingest->write($this->records['query']);
    $ingest->write($this->records['log']);
    $ingest->write($this->records['request']);

    expect($this->transport->sent)->toBe([])
        ->and($buffer->dropped())->toBe(1);
});

it('turns a full buffer back off the way Core does when it stops sampling', function () {
    $buffer = new EventBuffer(2);
    $ingest = prismIngest($buffer, $this->transport);
    $core = makeNightwatchCore(ingest: $ingest);

    // Core::sample() is what sets this, and dontSample() is sample(0) — an
    // execution that will be discarded must not pay to ship a full buffer.
    $core->dontSample();

    $ingest->write($this->records['query']);
    $ingest->write($this->records['log']);
    $ingest->write($this->records['request']);

    expect($this->transport->sent)->toBe([])
        ->and($buffer->dropped())->toBe(1);
});

/**
 * A transport that records the envelopes it is handed instead of sending them.
 * Named apart from `FlushTest`'s equivalent because Pest loads every test file
 * into one process, where a redeclared class is fatal.
 */
final class IngestRecordingTransport implements Transport
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
 * A `PrismIngest` over the given buffer, shipping through the given transport.
 *
 * The flusher factory is the real {@see Flusher} — the point of this class is
 * that everything below it is unchanged, so a double there would assert only
 * that the adapter calls something.
 */
function prismIngest(
    EventBuffer $buffer,
    IngestRecordingTransport $transport,
    ?string $executionId = null,
    ?SpanLineage $lineage = null,
): PrismIngest {
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
        $executionId === null ? null : static fn (): string => $executionId,
        null,
        $lineage,
    );
}
