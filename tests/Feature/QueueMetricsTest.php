<?php

use Illuminate\Support\Carbon;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Metrics\QueueMetrics;
use Misakstvanu\Prism\Metrics\SystemMetrics;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Runtime;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/**
 * A transport double recording the envelopes it is handed, so the metric a flush
 * piggybacks (AC1) can be asserted without a network. Uniquely named — Pest loads
 * every test file into one process, so it must not collide with the doubles in
 * FlushTest/JobCaptureTest.
 */
class QueueMetricsTransport implements Transport
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
 * A stand-in queue connection whose size() is deterministic, so a depth read
 * never touches a real backend. `$size` is either a fixed depth or a callable
 * that throws — the latter proves an unreadable backend degrades to null (AC5).
 */
class FakeQueueConnection
{
    /** @param  int|callable  $size */
    public function __construct(private mixed $size) {}

    public function size($queue = null): int
    {
        return is_callable($this->size) ? ($this->size)() : $this->size;
    }
}

/**
 * A stand-in for the host's queue manager. Implements the two surfaces the client
 * touches: `createPayloadUsing` (called at boot by trace propagation, short-circuited
 * here so no real connection is resolved) and `connection()` (returning a fixed-size
 * connection). Bound as `queue` before boot so the depth read is fully controlled and
 * Prism is proven to read through the host manager rather than its own backend (AC6).
 */
class FakeQueueManager
{
    /** @param  int|callable  $size */
    public function __construct(private mixed $size) {}

    public function createPayloadUsing($callback): self
    {
        return $this;
    }

    public function connection($name = null): FakeQueueConnection
    {
        return new FakeQueueConnection($this->size);
    }
}

/**
 * Reconfigure the app with full credentials and re-boot so registerCapture()
 * wires the queue-metrics collector. `$asWorker` controls the launching command
 * the worker-detection reads: a `queue:work` process binds the collector, any
 * other command (a web replica) does not. Every other capture domain is left off
 * so a re-boot's listeners never interfere with the metric assertions, and the
 * queue is a single `sync` connection so boot resolves a real default without
 * ever reaching a network backend.
 *
 * @param  array<string, mixed>  $overrides
 */
function bootMetrics(array $overrides = [], bool $asWorker = true): void
{
    $_SERVER['argv'] = $asWorker ? ['artisan', 'queue:work', 'redis'] : ['artisan', 'route:list'];

    config(array_merge([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'worker-1',
        'prism.batch.flush' => 'sync',
        'prism.batch.queue_threshold' => 0,
        'prism.capture.metrics' => true,
        'prism.metrics.queue_interval' => 30,
        'prism.capture.jobs' => false,
        'prism.capture.queries' => false,
        'prism.capture.requests' => false,
        'prism.capture.logs' => false,
        'prism.capture.traces' => false,
        'prism.capture.exceptions' => false,
        'queue.default' => 'sync',
        'queue.connections' => ['sync' => ['driver' => 'sync']],
    ], $overrides));

    Recursion::reset();
    TraceContext::reset();

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(Scrubber::class);
    app()->forgetInstance(QueueMetrics::class);

    (new PrismServiceProvider(app()))->boot();

    // US-051's replica-health collector also emits `replica_metric` events and
    // rides every flush on any replica. Drop its binding so this file's
    // assertions see only the queue-metrics collector under test.
    unset(app()[SystemMetrics::class]);
}

/**
 * The replica-metric events buffered so far.
 *
 * @return list<array<string, mixed>>
 */
function bufferedMetrics(): array
{
    return app(EventBuffer::class)->all()['replica_metric'] ?? [];
}

$prismOriginalArgv = $_SERVER['argv'] ?? null;

afterEach(function () use ($prismOriginalArgv) {
    if ($prismOriginalArgv === null) {
        unset($_SERVER['argv']);
    } else {
        $_SERVER['argv'] = $prismOriginalArgv;
    }

    Carbon::setTestNow();
    app()->forgetInstance(QueueMetrics::class);
});

// -- worker detection --------------------------------------------------------

it('detects a queue worker from the launching command', function () {
    $_SERVER['argv'] = ['artisan', 'queue:work', 'redis'];
    expect(Runtime::isQueueWorker())->toBeTrue();

    $_SERVER['argv'] = ['artisan', 'queue:listen'];
    expect(Runtime::isQueueWorker())->toBeTrue();

    $_SERVER['argv'] = ['artisan', 'horizon:work'];
    expect(Runtime::isQueueWorker())->toBeTrue();

    $_SERVER['argv'] = ['artisan', 'route:list'];
    expect(Runtime::isQueueWorker())->toBeFalse();

    unset($_SERVER['argv']);
    expect(Runtime::isQueueWorker())->toBeFalse();
});

// -- AC4: nothing is registered on a web replica -----------------------------

it('registers no queue polling on a web replica that never processes jobs', function () {
    bootMetrics(asWorker: false);

    expect(app()->bound(QueueMetrics::class))->toBeFalse();

    // A flush on a web replica ships nothing extra — no metric is piggybacked.
    $transport = new QueueMetricsTransport;
    app()->instance(Transport::class, $transport);
    app()->terminate();

    expect($transport->sent)->toBeEmpty();
});

it('registers nothing when metrics capture is disabled, even on a worker', function () {
    bootMetrics(['prism.capture.metrics' => false]);

    expect(app()->bound(QueueMetrics::class))->toBeFalse();
});

// -- AC1: sampled on an interval and piggybacked onto an existing flush -------

it('piggybacks a queue-metrics sample onto an existing flush', function () {
    bootMetrics();

    $transport = new QueueMetricsTransport;
    app()->instance(Transport::class, $transport);

    // The terminating flush is an existing flush — no dedicated request is made.
    app()->terminate();

    expect($transport->sent)->toHaveCount(1);

    $events = $transport->sent[0]['events'];

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('replica_metric');
});

it('samples at most once per configured interval', function () {
    bootMetrics(['prism.metrics.queue_interval' => 30]);

    Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 0, 0));

    $metrics = app(QueueMetrics::class);

    $metrics->collect();   // first sample: due
    $metrics->collect();   // still within the interval: skipped
    expect(bufferedMetrics())->toHaveCount(1);

    Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 0, 31)); // +31s
    $metrics->collect();   // interval elapsed: due again
    expect(bufferedMetrics())->toHaveCount(2);
});

it('does not sample while the package is doing its own work', function () {
    bootMetrics();

    Recursion::suppress(function () {
        app(QueueMetrics::class)->collect();
    });

    expect(bufferedMetrics())->toBeEmpty();
});

// -- AC2/AC6: depth read through the host's own configured backend ------------

it('reads queue depth per connection from the configured backend', function () {
    // Bind the host queue manager stand-in BEFORE boot, so every depth read goes
    // through the host's manager and never opens a backend of Prism's own (AC6).
    app()->instance('queue', new FakeQueueManager(7));

    bootMetrics([
        'queue.connections' => [
            'redis' => ['driver' => 'redis', 'queue' => 'default'],
            'database' => ['driver' => 'database', 'queue' => 'jobs'],
        ],
    ]);

    app(QueueMetrics::class)->collect();

    expect(bufferedMetrics()[0]['payload']['queues'])->toBe([
        ['connection' => 'redis', 'queue' => 'default', 'depth' => 7],
        ['connection' => 'database', 'queue' => 'jobs', 'depth' => 7],
    ]);
});

// -- AC5: an unreadable backend degrades to null without throwing -------------

it('degrades to a null depth when the backend cannot be read, without throwing', function () {
    app()->instance('queue', new FakeQueueManager(function (): int {
        throw new RuntimeException('Connection refused');
    }));

    bootMetrics([
        'queue.connections' => ['redis' => ['driver' => 'redis', 'queue' => 'default']],
    ]);

    app(QueueMetrics::class)->collect(); // must not throw

    expect(bufferedMetrics()[0]['payload']['queues'])->toBe([
        ['connection' => 'redis', 'queue' => 'default', 'depth' => null],
    ]);
});

// -- AC3: worker fields are null without Horizon -----------------------------

it('reports null worker and supervisor fields when Horizon is not installed', function () {
    bootMetrics();

    app(QueueMetrics::class)->collect();

    $payload = bufferedMetrics()[0]['payload'];

    expect($payload['workers'])->toBeNull()
        ->and($payload['supervisor'])->toBeNull();
});
