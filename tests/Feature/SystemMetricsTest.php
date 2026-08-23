<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Nightwatch\Contracts\Ingest;
use Laravel\Nightwatch\Core;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Flush\SpoolScheduler;
use Misakstvanu\Prism\Metrics\QueueMetrics;
use Misakstvanu\Prism\Metrics\SystemMetrics;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RecordTranslator;
use Misakstvanu\Prism\Otel\SpanFlush;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/**
 * A transport double recording the envelopes it is handed, so the health sample
 * an execution's end produces (AC1) — and the replica name it ships under (AC2)
 * — can be asserted without a network. Uniquely named: Pest loads every test
 * file into one process, so it must not collide with the doubles elsewhere.
 */
class SystemMetricsTransport implements Transport
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
 * A collector with fixed OS readings, so the CPU/memory/uptime maths is asserted
 * against known inputs rather than the live host. cores=4 and load=2.0 give 50%;
 * (1000-250)/1000 gives 75%; the uptime's first field is 12345.
 */
class FixedSystemMetrics extends SystemMetrics
{
    protected function loadAverage(): ?float
    {
        return 2.0;
    }

    protected function cpuCores(): int
    {
        return 4;
    }

    protected function meminfo(): ?string
    {
        return "MemTotal:        1000 kB\nMemAvailable:     250 kB\n";
    }

    protected function uptimeRaw(): ?string
    {
        return "12345.67 9999.00\n";
    }
}

/**
 * A collector whose every OS source is unavailable — the shape of a host (e.g.
 * Windows) that exposes no load average, /proc/meminfo or /proc/uptime — proving
 * a missing source yields a null field rather than throwing (AC4).
 */
class BlindSystemMetrics extends SystemMetrics
{
    protected function loadAverage(): ?float
    {
        return null;
    }

    protected function meminfo(): ?string
    {
        return null;
    }

    protected function uptimeRaw(): ?string
    {
        return null;
    }
}

/**
 * Reconfigure the app with full credentials and re-boot so registerCapture()
 * wires the system-metrics collector. `$asWorker` controls the launching command
 * the replica-type inference reads: a `queue:work` process reports `worker`, any
 * other command a `web` replica. Every other capture domain is left off so a
 * re-boot's listeners never interfere with the metric assertions, and the queue
 * is a single `sync` connection so boot resolves a real default without ever
 * reaching a network backend.
 *
 * @param  array<string, mixed>  $overrides
 */
function bootSystem(array $overrides = [], bool $asWorker = false): void
{
    $_SERVER['argv'] = $asWorker ? ['artisan', 'queue:work', 'redis'] : ['artisan', 'route:list'];

    config(array_merge([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => $asWorker ? 'worker-1' : 'web-1',
        'prism.batch.flush' => 'sync',
        'prism.batch.queue_threshold' => 0,
        'prism.capture.metrics' => true,
        'prism.metrics.system_interval' => 60,
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
    app()->forgetInstance(SystemMetrics::class);
    app()->forgetInstance(QueueMetrics::class);

    (new PrismServiceProvider(app()))->boot();

    // Bound AFTER boot on purpose: the ingest's flusher factory resolves the
    // transport at flush time, which is what makes a recording one installed
    // here the transport a sample actually leaves through.
    app()->instance(Transport::class, new SystemMetricsTransport);
}

/**
 * The replica-metric events shipped so far, across every envelope.
 *
 * The observation point moved with US-013: a sample is no longer added to the
 * shared buffer for a later flush to drain, it is handed to the ingest's
 * `writeNow()` and leaves in a batch of its own. Reading the buffer would now
 * assert nothing — it is empty either way.
 *
 * @return list<array<string, mixed>>
 */
function shippedSystemMetrics(): array
{
    /** @var SystemMetricsTransport $transport */
    $transport = app(Transport::class);

    $events = [];

    foreach ($transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            if ($event['type'] === 'replica_metric') {
                $events[] = $event;
            }
        }
    }

    return $events;
}

$prismOriginalArgv = $_SERVER['argv'] ?? null;

afterEach(function () use ($prismOriginalArgv) {
    if ($prismOriginalArgv === null) {
        unset($_SERVER['argv']);
    } else {
        $_SERVER['argv'] = $prismOriginalArgv;
    }

    Carbon::setTestNow();
    Queue::createPayloadUsing(null);
    app()->forgetInstance(SystemMetrics::class);
    app()->forgetInstance(QueueMetrics::class);
});

// -- AC1: sampled on an interval, at the end of an execution -----------------

it('ships a health sample when an execution ends', function () {
    bootSystem();

    // The terminating flush is where the interval is consulted; the sample it
    // finds due ships itself.
    app()->terminate();

    expect(shippedSystemMetrics())->toHaveCount(1);
});

// -- US-013: writeNow, so sampling cannot take the metric with it ------------

it('hands the sample to writeNow and never to the shared buffer', function () {
    $ingest = new RecordingNightwatchIngest;
    $metrics = new FixedSystemMetrics(fn (): Ingest => $ingest, app(), 'r1', 'web', 60);

    $metrics->collect();

    // write() would put it in the buffer Core::flush() discards for an execution
    // the sampler rejected, which is the one thing a replica metric must survive.
    expect($ingest->writtenNow)->toHaveCount(1)
        ->and($ingest->written)->toBeEmpty();
});

it('leaves the shared buffer exactly as it found it', function () {
    bootSystem();

    app(EventBuffer::class)->add('log', ['payload' => ['message' => 'from the execution']]);

    app(SystemMetrics::class)->collect();

    expect(app(EventBuffer::class)->count())->toBe(1);
});

it("sends a record the translator understands, in the engine's own shape", function () {
    $ingest = new RecordingNightwatchIngest;
    $metrics = new FixedSystemMetrics(fn (): Ingest => $ingest, app(), 'r1', 'web', 60);

    $metrics->collect();

    $record = $ingest->writtenNow[0];

    // A Nightwatch-shaped record: versioned, typed, float microtime. Prism's own
    // `t`, because Nightwatch has no system sensor to borrow one from.
    expect($record['v'])->toBe(1)
        ->and($record['t'])->toBe('replica_metric')
        ->and($record['timestamp'])->toBeFloat();

    $event = (new RecordTranslator)->translate($record);

    expect($event)->not->toBeNull()
        ->and($event['type'])->toBe('replica_metric')
        ->and($event['payload']['cpu_percent'])->toBe(50.0);
});

it("ships through Prism's own ingest when the engine still holds a foreign one", function () {
    bootSystem();

    // The state a swap that has not run — or has failed — leaves behind: the
    // `Core` is holding an ingest that is not Prism's. `replica_metric` is a
    // record type Prism invented, so handing it over would post something the
    // agent cannot read to a daemon this package guarantees is not running.
    app()->instance(Core::class, makeNightwatchCore(ingest: new NullNightwatchIngest));

    app(SystemMetrics::class)->collect();

    expect(shippedSystemMetrics())->toHaveCount(1);
});

it('files the sample under no execution, even mid-request', function () {
    bootSystem();

    $ingest = new PrismIngest(
        new EventBuffer(1000),
        new RecordTranslator,
        fn (EventBuffer $buffer): Flusher => new Flusher(
            app('config'),
            $buffer,
            app(Transport::class),
            app(BatchSpool::class),
            app(SpoolScheduler::class),
            app(SpanFlush::class),
        ),
        // An execution is in progress and has an id, exactly as it would be when
        // the terminating flush takes a sample.
        fn (): string => 'execution-that-is-not-the-metrics',
    );

    (new FixedSystemMetrics(fn (): Ingest => $ingest, app(), 'r1', 'web', 60))->collect();

    expect(shippedSystemMetrics()[0]['request_id'])->toBe('');
});

it('samples at most once per configured interval', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 0, 0));

    $ingest = new RecordingNightwatchIngest;
    $metrics = new FixedSystemMetrics(fn (): Ingest => $ingest, app(), 'r1', 'web', 60);

    $metrics->collect();   // first sample: due
    $metrics->collect();   // still within the interval: skipped
    expect($ingest->writtenNow)->toHaveCount(1);

    Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 1, 1)); // +61s
    $metrics->collect();   // interval elapsed: due again
    expect($ingest->writtenNow)->toHaveCount(2);
});

it('samples on every flush when the interval is zero', function () {
    $ingest = new RecordingNightwatchIngest;
    $metrics = new FixedSystemMetrics(fn (): Ingest => $ingest, app(), 'r1', 'web', 0);

    $metrics->collect();
    $metrics->collect();

    expect($ingest->writtenNow)->toHaveCount(2);
});

it('does not sample while the package is doing its own work', function () {
    bootSystem();

    Recursion::suppress(function () {
        app(SystemMetrics::class)->collect();
    });

    expect(shippedSystemMetrics())->toBeEmpty();
});

// -- AC1: runs on every replica, not only queue workers ----------------------

it('registers health reporting on a web replica, unlike queue polling', function () {
    bootSystem(asWorker: false);

    expect(app()->bound(SystemMetrics::class))->toBeTrue()
        ->and(app()->bound(QueueMetrics::class))->toBeFalse();
});

it('registers nothing when metrics capture is disabled', function () {
    bootSystem(['prism.capture.metrics' => false]);

    expect(app()->bound(SystemMetrics::class))->toBeFalse();
});

// -- AC2: shipped under the configured replica name --------------------------

it('ships the sample under the configured replica name', function () {
    bootSystem(['prism.replica' => 'pod-abc-123']);

    app()->terminate();

    /** @var SystemMetricsTransport $transport */
    $transport = app(Transport::class);

    expect($transport->sent[0]['replica'])->toBe('pod-abc-123');
});

// -- AC3: replica type is inferred and overridable ---------------------------

it('infers a worker type on a queue-worker process', function () {
    bootSystem(asWorker: true);

    app(SystemMetrics::class)->collect();

    expect(shippedSystemMetrics()[0]['payload']['type'])->toBe('worker');
});

it('infers a web type on a non-worker process', function () {
    bootSystem(asWorker: false);

    app(SystemMetrics::class)->collect();

    expect(shippedSystemMetrics()[0]['payload']['type'])->toBe('web');
});

it('lets the replica type be overridden by config', function () {
    // A queue-worker process, but the deployment forces the reported type.
    bootSystem(['prism.metrics.replica_type' => 'web'], asWorker: true);

    app(SystemMetrics::class)->collect();

    expect(shippedSystemMetrics()[0]['payload']['type'])->toBe('web');
});

// -- reporting: CPU / memory / uptime maths ----------------------------------

it('computes CPU, memory and uptime from the host readings', function () {
    $ingest = new RecordingNightwatchIngest;
    $metrics = new FixedSystemMetrics(fn (): Ingest => $ingest, app(), 'r1', 'worker', 60);

    $metrics->collect();

    $payload = $ingest->writtenNow[0];

    expect($payload['cpu_percent'])->toBe(50.0)
        ->and($payload['memory_percent'])->toBe(75.0)
        ->and($payload['uptime_seconds'])->toBe(12345);
});

it('reads real host metrics as null or a number without throwing', function () {
    bootSystem(asWorker: false);

    app(SystemMetrics::class)->collect();

    $payload = shippedSystemMetrics()[0]['payload'];

    foreach (['cpu_percent', 'memory_percent', 'uptime_seconds'] as $key) {
        expect($payload[$key] === null || is_numeric($payload[$key]))->toBeTrue();
    }
});

// -- AC4: degrades to nulls where the host does not expose metrics -----------

it('sends null CPU, memory and uptime when the host exposes none, without throwing', function () {
    $ingest = new RecordingNightwatchIngest;
    $metrics = new BlindSystemMetrics(fn (): Ingest => $ingest, app(), 'r1', 'web', 60);

    $metrics->collect(); // must not throw

    $payload = $ingest->writtenNow[0];

    expect($payload['cpu_percent'])->toBeNull()
        ->and($payload['memory_percent'])->toBeNull()
        ->and($payload['uptime_seconds'])->toBeNull()
        ->and($payload['type'])->toBe('web');
});
