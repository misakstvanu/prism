<?php

declare(strict_types=1);

use Laravel\Nightwatch\Core;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Metrics\SystemMetrics;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Transport\Transport;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Replica metrics through the REAL capture engine (US-013).
 *
 * `replica_metric` is the one signal with no upstream counterpart: Nightwatch's
 * `Sensors` directory has neither a system sensor nor a queue sensor, so CPU,
 * memory, uptime and queue depth are still Prism's own to collect. What changed
 * with the engine is where a sample goes — and the choice of method is the whole
 * story, because both plausible readings ship a metric on an ordinary request
 * and only one of them survives the case that matters.
 *
 * `Core::finishExecution()` is `sampling ? digest() : flush()`, and on Prism's
 * ingest `flush()` **discards the buffer**. A metric written with `write()`
 * would therefore be thrown away with the rest of an execution the sampler
 * rejected — so a workspace that turned its request rate down to 5% would find
 * the Replicas screen and the `replica_cpu` alert going dark at the same rate,
 * for a reason nothing on either screen could explain. `writeNow()` puts the
 * sample in a batch of its own, which is what makes a replica's health
 * independent of whether one request happened to be interesting.
 *
 * These run in `tests/Host` because the claim is about the engine's own
 * sampling decision, which needs Nightwatch's provider registered ahead of
 * Prism's — the order every real install produces.
 */
beforeEach(function () {
    $this->transport = new MetricsRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

it('transmits a metric from an execution the engine sampled out', function () {
    bootPrismForMetrics(['requests' => 0.0]);

    $core = app(Core::class);
    $core->configureRequestSampling();

    expect($core->sampling())->toBeFalse();

    // Buffered by the execution, exactly as a log line inside a request would
    // be — and discarded with it below. It is the control: without it, a
    // transport that recorded nothing at all would pass the metric assertion
    // for the wrong reason.
    $core->log(metricsLogRecord());

    app(SystemMetrics::class)->collect();

    $core->finishExecution();

    expect(metricTypes())->toContain('replica_metric')
        ->and(metricTypes())->not->toContain('log');
});

it('transmits a metric from an execution the engine kept, without waiting for it', function () {
    bootPrismForMetrics(['requests' => 1.0]);

    $core = app(Core::class);
    $core->configureRequestSampling();

    app(SystemMetrics::class)->collect();

    // Shipped on the spot: the metric is already out before the execution has
    // decided anything, which is the same property the sampled-out case relies
    // on read from the other side.
    expect(metricTypes())->toBe(['replica_metric']);

    $core->finishExecution();
});

it('names the replica the envelope names, so the fleet reads as one process', function () {
    bootPrismForMetrics(['requests' => 0.0]);

    app(SystemMetrics::class)->collect();

    expect($this->transport->sent[0]['replica'])->toBe('web-1')
        ->and($this->transport->sent[0]['app'])->toBe('demo')
        ->and($this->transport->sent[0]['env'])->toBe('production');
});

it('files the metric under no execution, however deep inside one it was taken', function () {
    bootPrismForMetrics(['requests' => 1.0]);

    $core = app(Core::class);
    $core->configureRequestSampling();

    // There is a live execution with an id, and PrismIngest fills a blank
    // `request_id` from it for every record that belongs to one. A replica's
    // CPU does not: it would file the whole fleet's health under whichever
    // request was finishing when the interval elapsed.
    expect($core->executionState->id)->not->toBe('');

    app(SystemMetrics::class)->collect();

    $event = $this->transport->sent[0]['events'][0];

    expect($event['type'])->toBe('replica_metric')
        ->and($event['request_id'])->toBe('')
        ->and($event['trace_id'])->toBe('');
});

it('carries the health fields into the payload under the column names', function () {
    bootPrismForMetrics(['requests' => 1.0]);

    app(SystemMetrics::class)->collect();

    $payload = $this->transport->sent[0]['events'][0]['payload'];

    expect($payload)->toHaveKeys(['cpu_percent', 'memory_percent', 'uptime_seconds', 'type'])
        ->and($payload['type'])->toBe('web');
});

/**
 * Re-run Prism's registration and boot with a given `sample` block and a metrics
 * interval of zero, so every `collect()` in a test is due.
 *
 * `register()` as well as `boot()`, for the reason `NightwatchSamplingTest` sets
 * out: the sampling rates are derived at register time. The recording transport
 * is re-installed afterwards because a boot that reaches `registerCapture()`
 * re-binds `Transport` as a singleton of its own.
 *
 * @param  array<string, float>  $rates
 */
function bootPrismForMetrics(array $rates): void
{
    config([
        'prism.sample' => array_replace((array) config('prism.sample'), $rates),
        'prism.metrics.system_interval' => 0,
        'prism.metrics.queue_interval' => 0,
    ]);

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(SystemMetrics::class);

    $provider = new PrismServiceProvider(app());
    $provider->register();
    $provider->boot();

    app()->instance(Transport::class, test()->transport);

    app(EventBuffer::class)->clear();
}

/**
 * The Prism event types that actually left over the transport.
 *
 * @return list<string>
 */
function metricTypes(): array
{
    $types = [];

    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            $types[] = (string) ($event['type'] ?? '');
        }
    }

    return $types;
}

/** A log line for the engine's own entry point — one buffered record to be discarded. */
function metricsLogRecord(): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        channel: 'testing',
        level: Level::Warning,
        message: 'Disk almost full',
        context: ['free' => '2%'],
    );
}

/**
 * A transport that records envelopes instead of sending them. Named apart from
 * the equivalents elsewhere in the suite because Pest loads every test file into
 * one process, where a redeclared class is fatal.
 */
final class MetricsRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
