<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Flush\SpoolScheduler;
use Misakstvanu\Prism\Jobs\DrainSpoolJob;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Misakstvanu\Prism\Otel\SpanFlush;
use Misakstvanu\Prism\Transport\Transport;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * The flush ORDER tail sampling forces (US-019).
 *
 * Tail sampling holds a trace's spans until its decision — until the root ends,
 * or until `decision_wait` (5000ms by default) elapses, which is longer than
 * most requests live. Whatever is released when the tracer is finally flushed
 * has to land in the batch its own execution ships, not in whichever later
 * execution happens to flush next: a span arriving in a stranger's batch is a
 * trace with a hole in it on one screen and an orphan bar on another, and
 * nothing anywhere reports it.
 *
 * So {@see Flusher} force-flushes the tracer **first**, and before its own "is
 * the buffer empty" early return — an execution whose only telemetry is a held
 * span would otherwise return on an empty buffer and force nothing out at all.
 *
 * The processor below stands in for the release: a real
 * {@see SpanProcessorInterface} on a real `TracerProvider` that writes one
 * event into the shared buffer when it is force-flushed. Prism's own processor
 * holds nothing back (it sits beside the tail-sampling decision, not behind it
 * — see the tests/Otel suite), which is exactly why the ordering needs a test
 * that can observe a release at all.
 */
beforeEach(function () {
    $this->tracerScope = null;

    config([
        'prism.app' => 'demo',
        'prism.environment' => 'testing',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'terminate',
        'prism.batch.queue_threshold' => 0,
        'prism.otel.enabled' => true,
        // What SpanLane::registered() reads: the span lane is only asked to
        // flush a tracer Prism's own processor is installed on.
        'opentelemetry.traces.processors' => [PrismSpanProcessor::class],
    ]);
});

afterEach(function () {
    // Context scopes are process-global and detach LIFO; a leaked one would
    // hand the next test in this process a tracer it never installed.
    $this->tracerScope?->detach();
    $this->tracerScope = null;
});

it('force-flushes the tracer before draining, so a released span rides its own batch', function () {
    $buffer = new EventBuffer;
    $buffer->add('log', ['message' => 'during the request', 'timestamp' => 't']);

    $this->tracerScope = installReleasingTracer($buffer, 'released at flush');

    $transport = new TailFlushTransport;

    tailFlusher($buffer, $transport)->flush();

    expect($transport->sent)->toHaveCount(1);

    $names = array_map(
        static fn (array $event): string => (string) ($event['payload']['name'] ?? $event['message'] ?? ''),
        $transport->sent[0]['events'],
    );

    // Both in ONE envelope. Flushing the tracer after the drain would ship the
    // log alone and leave the span for whatever executes next.
    expect($names)->toContain('during the request')
        ->and($names)->toContain('released at flush')
        // ...and nothing is left behind for a later batch to mis-attribute.
        ->and($buffer->isEmpty())->toBeTrue();
});

it('ships an execution whose only telemetry was still held by the decision', function () {
    // The empty-buffer early return is BEFORE the force flush only if the two
    // are the wrong way round; then a request that produced one span and
    // nothing else ships nothing at all, for ever.
    $buffer = new EventBuffer;

    $this->tracerScope = installReleasingTracer($buffer, 'the only thing that happened');

    $transport = new TailFlushTransport;

    tailFlusher($buffer, $transport)->flush();

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['events'])->toHaveCount(1)
        ->and($transport->sent[0]['events'][0]['payload']['name'])->toBe('the only thing that happened');
});

it('carries a late span to the server through the spool, when that is the strategy', function () {
    // Where decision_wait outlives the request, the span is released by a LATER
    // execution's force flush — so what has to hold is that the spool carries
    // it too, rather than the batch sitting in a cache the drain has already
    // been past. Two flushes, one drain, both batches on the wire.
    config([
        'prism.batch.flush' => 'spool',
        // Testbench defaults the queue to `sync`, on which the "deferred" drain
        // runs inline and the flusher declines to spool at all.
        'queue.default' => 'database',
    ]);

    Queue::fake();

    $transport = new TailFlushTransport;

    $first = new EventBuffer;
    $first->add('log', ['message' => 'the request', 'timestamp' => 't']);
    tailFlusher($first, $transport)->flush();

    // The next execution's flush is what releases what the decision was still
    // holding from the last one.
    $late = new EventBuffer;
    $this->tracerScope = installReleasingTracer($late, 'late span');
    tailFlusher($late, $transport)->flush();

    expect($transport->sent)->toBeEmpty()
        ->and(app(BatchSpool::class)->pending())->toBe(2);

    (new DrainSpoolJob(lastTailDrainOwner()))->handle(
        app(SpoolScheduler::class),
        app(BatchSpool::class),
        $transport,
    );

    $names = [];

    foreach ($transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            $names[] = (string) ($event['payload']['name'] ?? $event['message'] ?? '');
        }
    }

    expect($names)->toContain('the request')
        ->and($names)->toContain('late span')
        ->and(app(BatchSpool::class)->pending())->toBe(0);
});

it('asks no tracer at all when the span lane is not producing spans', function (array $overrides) {
    // Globals::tracerProvider() initialises the SDK's global instance on first
    // read, so a host with the lane off must never reach it — SpanLane is the
    // one gate, the same one the ingest and prism:check read.
    config($overrides);

    $buffer = new EventBuffer;
    $transport = new TailFlushTransport;

    $this->tracerScope = installReleasingTracer($buffer, 'must not be released');

    tailFlusher($buffer, $transport)->flush();

    // Nothing was released, so there was nothing to ship — where a lane that
    // was asked would have put one span in the buffer and shipped it.
    expect($transport->sent)->toBeEmpty()
        ->and($buffer->isEmpty())->toBeTrue()
        // And said so: the gate refused before any `Globals::` lookup.
        ->and(app(SpanFlush::class)->flush())->toBeFalse();
})->with([
    'the lane is switched off' => [['prism.otel.enabled' => false]],
    'the processor is not registered' => [['opentelemetry.traces.processors' => []]],
]);

/** A flusher over the given buffer and transport, with the real collaborators. */
function tailFlusher(EventBuffer $buffer, Transport $transport): Flusher
{
    return new Flusher(
        app('config'),
        $buffer,
        $transport,
        app(BatchSpool::class),
        app(SpoolScheduler::class),
        app(SpanFlush::class),
    );
}

/**
 * Install a real `TracerProvider` for the rest of the test whose one processor
 * releases a span event into $buffer when it is force-flushed.
 *
 * Through `Configurator`'s context rather than through
 * `Sdk::builder()->buildAndRegisterGlobal()`: `Globals::tracerProvider()` reads
 * the current context first, so this is scoped to the test rather than pinned
 * on the process for every test after it.
 */
function installReleasingTracer(EventBuffer $buffer, string $name): ScopeInterface
{
    $provider = TracerProvider::builder()
        ->addSpanProcessor(new ReleasingSpanProcessor($buffer, $name))
        ->build();

    return Configurator::create()->withTracerProvider($provider)->activate();
}

/** The owner token of the most recently dispatched drain job. */
function lastTailDrainOwner(): ?string
{
    /** @var DrainSpoolJob|null $job */
    $job = Queue::pushed(DrainSpoolJob::class)->last();

    return $job?->owner;
}

/**
 * A span processor that holds one span back and releases it into the Prism
 * buffer on `forceFlush()` — upstream's tail-sampling decision in miniature,
 * and the only way the flush ORDER is observable at all.
 */
final class ReleasingSpanProcessor implements SpanProcessorInterface
{
    private bool $released = false;

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly string $name,
    ) {}

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void {}

    public function onEnd(ReadableSpanInterface $span): void {}

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        if ($this->released) {
            return true;
        }

        $this->released = true;

        $this->buffer->add('span', [
            'timestamp' => '2026-08-22T10:00:00.000000Z',
            'trace_id' => str_repeat('a', 32),
            'payload' => ['name' => $this->name, 'type' => 'ctrl'],
        ]);

        return true;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}

/** Keeps every envelope instead of sending it. */
final class TailFlushTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
