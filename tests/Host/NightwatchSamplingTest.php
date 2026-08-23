<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Transport\Transport;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * `prism.sample.*` driven through the REAL capture engine (US-012).
 *
 * There are three samplers in the chain now and they compose multiplicatively:
 * this one (per execution, in the client), the server's own per-workspace rules
 * on ingest, and — once the span lane lands — OTel's trace sampler. Only the
 * first is what this file is about, and the thing worth pinning about it is
 * that **the unit is an execution, not a signal**. A request that is sampled
 * out takes its queries, cache reads, log lines, outgoing calls and spans with
 * it, because `Core::finishExecution()` answers a rejected execution with
 * `flush()` — which on Prism's ingest means discard.
 *
 * That has one deliberate hole in it, and it is upstream's: an execution that
 * THROWS is re-rolled against the exception rate, which Prism pins at 1.0. So
 * an error is never lost to sampling however low the request rate is dialled.
 * Both halves are asserted below, because a sampler that drops errors and a
 * sampler that drops nothing look identical from a config file.
 *
 * Every assertion goes through `Laravel\Nightwatch\Core`'s own entry points.
 * The rates live in `Core::$config['sampling']`, an array snapshotted during
 * Nightwatch's `register()` — so a test that only read the config repository
 * would pass while every execution ran at upstream's default of 1.0.
 */
beforeEach(function () {
    $this->transport = new SamplingRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

it('samples a request at the rate prism configured, not the engine default', function () {
    // The end-to-end claim, read from INSIDE the request: the engine's own
    // global middleware calls `configureRequestSampling()`, which reads the
    // rate out of the array Nightwatch built before Prism ever registered.
    bootPrismWithSampleRates(['requests' => 0.0]);

    Route::get('/orders-sampling-probe', fn () => app(Core::class)->sampling() ? 'sampled' : 'dropped');

    $this->get('/orders-sampling-probe')->assertSee('dropped');
});

it('keeps a request at a rate of 1.0, so the refusal above means something', function () {
    bootPrismWithSampleRates(['requests' => 1.0]);

    Route::get('/orders-sampling-kept', fn () => app(Core::class)->sampling() ? 'sampled' : 'dropped');

    $this->get('/orders-sampling-kept')->assertSee('sampled');
});

it('ships nothing about a request the rate dropped', function () {
    bootPrismWithSampleRates(['requests' => 0.0]);

    Route::get('/orders-sampling-shipped', fn () => 'ok');

    $this->get('/orders-sampling-shipped')->assertOk();

    expect(sampledTypes())->not->toContain('request');
});

it('ships a request the rate kept', function () {
    bootPrismWithSampleRates(['requests' => 1.0]);

    Route::get('/orders-sampling-shipped-kept', fn () => 'ok');

    $this->get('/orders-sampling-shipped-kept')->assertOk();

    expect(sampledTypes())->toContain('request');
});

it('ships NOTHING AT ALL for an execution the rate dropped, not merely its own row', function () {
    // The whole point of sampling per execution: the log line below was
    // buffered before anything knew how the execution would end, and it is
    // discarded with the rest of the batch rather than shipped on its own.
    bootPrismWithSampleRates(['requests' => 0.0]);

    $core = app(Core::class);
    $core->configureRequestSampling();

    $core->log(sampledLogRecord());

    expect($core->sampling())->toBeFalse();

    $core->finishExecution();

    expect($this->transport->sent)->toBeEmpty();
});

it('ships nothing over a REAL request lifecycle either, not only when finishExecution is called by hand', function () {
    // The case above drives `Core` directly, so it says nothing about *when*
    // the discard happens relative to Prism's own terminating flush — and the
    // real answer is that it happens after (US-024). `Kernel::terminate()` is
    // `terminateMiddleware()` → `$app->terminate()` → the request-lifecycle
    // handlers, and only the last of those calls `finishExecution()`; Prism's
    // drain hangs off the middle one. So a sampled-out request used to have
    // everything but its own row shipped by the drain moments before the
    // discard emptied a buffer that had nothing left in it.
    bootPrismWithSampleRates(['requests' => 0.0]);

    Route::get('/orders', function () {
        Log::info('an order was priced');

        return 'ok';
    });

    $this->get('/orders')->assertOk();

    expect(sampledSignals())->toBeEmpty();
});

it('ships that same lifecycle whole at a rate of 1.0, so the silence above means something', function () {
    bootPrismWithSampleRates(['requests' => 1.0]);

    Route::get('/orders', function () {
        Log::info('an order was priced');

        return 'ok';
    });

    $this->get('/orders')->assertOk();

    expect(sampledSignals())->toContain('log')
        ->and(sampledSignals())->toContain('request');
});

it('ships the execution the rate kept', function () {
    bootPrismWithSampleRates(['requests' => 1.0]);

    $core = app(Core::class);
    $core->configureRequestSampling();

    $core->log(sampledLogRecord());

    expect($core->sampling())->toBeTrue();

    $core->finishExecution();

    expect(sampledTypes())->toContain('log');
});

it('pulls a sampled-out execution back into the sample the moment it throws', function () {
    // Upstream's rule, preserved deliberately rather than inherited by
    // accident: `Core::report()` re-rolls against `sampling.exceptions` when the
    // execution is not being sampled, and Prism pins that rate at 1.0.
    bootPrismWithSampleRates(['requests' => 0.0]);

    $core = app(Core::class);
    $core->configureRequestSampling();

    expect($core->sampling())->toBeFalse();

    $core->report(new RuntimeException('Order 5 could not be priced.'), handled: false);

    expect($core->sampling())->toBeTrue()
        // An UNHANDLED exception takes `writeNow()`, which ships on the spot —
        // the path a fault escaping to the handler actually travels.
        ->and(sampledTypes())->toContain('exception');
});

it('ships a handled exception, and the execution around it, from a request the rate dropped', function () {
    // The other half of the same rule. A handled exception is buffered rather
    // than shipped at once, so what makes it arrive is that the re-sample turned
    // `finishExecution()` from a discard into a send — and the log line that was
    // already in the buffer comes back with it.
    bootPrismWithSampleRates(['requests' => 0.0]);

    $core = app(Core::class);
    $core->configureRequestSampling();

    $core->log(sampledLogRecord());
    $core->report(new RuntimeException('Order 5 could not be priced.'), handled: true);

    $core->finishExecution();

    expect(sampledTypes())->toContain('exception')
        ->and(sampledTypes())->toContain('log');
});

it('has no way to turn the exception rate down', function () {
    // There is no `prism.sample.exceptions` — the pin is not a default a host
    // can move, so writing one reaches nothing and the fault on a sampled-out
    // request still ships. (The other half, a host reaching for the engine's
    // own `NIGHTWATCH_EXCEPTION_SAMPLE_RATE`, is pinned in `NightwatchConfigTest`:
    // it is the one derived key with no environment escape hatch.)
    bootPrismWithSampleRates(['requests' => 0.0, 'exceptions' => 0.0]);

    $core = app(Core::class);
    $core->configureRequestSampling();
    $core->report(new RuntimeException('Order 5 could not be priced.'), handled: false);

    expect($core->config['sampling']['exceptions'])->toBe(1.0)
        ->and($core->sampling())->toBeTrue()
        ->and(sampledTypes())->toContain('exception');
});

it('samples a command at the rate prism configured', function () {
    // Driven through `configureCommandSampling()` rather than a `CommandStarting`
    // event: `tests/Host` forces the request shape (see `NightwatchHostTestCase`),
    // so the engine's console hooks are not registered here at all and the event
    // would prove nothing about the engine.
    bootPrismWithSampleRates(['commands' => 0.0]);

    $core = app(Core::class);
    $core->configureCommandSampling('queue:work');

    expect($core->sampling())->toBeFalse();
});

it('keeps a command at a rate of 1.0', function () {
    // Its own test rather than a second probe in the one above, and not for
    // tidiness: `configureCommandSampling()` is the only one of the three that
    // consults Laravel's Context first (`getSamplingFromContext`), which the
    // previous `sample()` wrote `false` into. A second probe in one test
    // inherits that verdict and passes for the wrong reason — the per-execution
    // reset trap, one layer below the `Core`.
    bootPrismWithSampleRates(['commands' => 1.0]);

    $core = app(Core::class);
    $core->configureCommandSampling('queue:work');

    expect($core->sampling())->toBeTrue();
});

it('samples a scheduled task at the rate prism configured', function () {
    // Prism says "schedules" wherever it names this signal; upstream says
    // "scheduled_tasks". This is the one of the three rates whose key is
    // renamed at the seam, which is exactly where a mapping goes quietly wrong.
    bootPrismWithSampleRates(['schedules' => 0.0]);

    $core = app(Core::class);
    $core->configureScheduledTaskSampling(app(Schedule::class)->exec('php -v')->everyFiveMinutes());

    expect($core->sampling())->toBeFalse();

    bootPrismWithSampleRates(['schedules' => 1.0]);

    $core = app(Core::class);
    $core->configureScheduledTaskSampling(app(Schedule::class)->exec('php -v')->everyFiveMinutes());

    expect($core->sampling())->toBeTrue();
});

it('captures everything when the rates cannot be read at all', function () {
    // `(float) null` is 0.0, so a `prism.sample` block that went missing would
    // otherwise switch the install off silently and permanently. The safe
    // direction is a bill, not an empty console.
    config(['prism.sample' => null]);

    bootPrismWithSampleRates([]);

    $core = app(Core::class);
    $core->configureRequestSampling();

    expect($core->config['sampling']['requests'])->toBe(1.0)
        ->and($core->sampling())->toBeTrue();
});

/**
 * Re-run Prism's registration and boot with a given `sample` block, so the
 * rates under test are the ones the engine reads.
 *
 * `register()` as well as `boot()`, unlike the other host suites: the sampling
 * rates are derived in `configureNightwatch()`, which runs at REGISTER time and
 * reconciles the already-built `Core` there and again on `booted` — and the app
 * is already booted here, so that callback fires at once.
 *
 * The recording transport is re-installed afterwards, because a boot that
 * reaches `registerCapture()` re-binds `Transport` as a singleton of its own.
 *
 * @param  array<string, float>  $rates
 */
function bootPrismWithSampleRates(array $rates): void
{
    config(['prism.sample' => array_replace((array) config('prism.sample'), $rates)]);

    app()->forgetInstance(PrismServiceProvider::ACTIVE);

    $provider = new PrismServiceProvider(app());
    $provider->register();
    $provider->boot();

    app()->instance(Transport::class, test()->transport);

    app(EventBuffer::class)->clear();
}

/**
 * The Prism event types that actually left over the transport.
 *
 * The observation point for every claim here: an unsampled execution still
 * BUFFERS its records, and what makes it a refusal is that `finishExecution()`
 * discards the buffer instead of shipping it.
 *
 * @return list<string>
 */
function sampledTypes(): array
{
    $types = [];

    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            $types[] = (string) ($event['type'] ?? '');
        }
    }

    return $types;
}

/**
 * The same, minus the replica heartbeat.
 *
 * `replica_metric` has no capture engine behind it: it is a fixed-cadence
 * sample of the process, shipped through `writeNow()` precisely so a replica's
 * health survives an execution the sampler rejected (US-013). It is meant to
 * escape, so an assertion about what a rejected execution shipped sets it
 * aside rather than being written around it.
 *
 * @return list<string>
 */
function sampledSignals(): array
{
    return array_values(array_filter(
        sampledTypes(),
        static fn (string $type): bool => $type !== 'replica_metric',
    ));
}

/** A log line for the engine's own log entry point — one buffered record, whatever else happened. */
function sampledLogRecord(): LogRecord
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
 * the equivalents elsewhere in the suite because Pest loads every test file
 * into one process, where a redeclared class is fatal.
 */
final class SamplingRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
