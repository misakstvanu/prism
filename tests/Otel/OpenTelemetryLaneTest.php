<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Route;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\CacheInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\QueryInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\RedisInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Support\NoopSpanExporter;
use Misakstvanu\Prism\Tests\RecordingClientDiscovery;
use OpenTelemetry\SDK\Logs\Exporter\NoopExporter as LogsNoopExporter;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\NoopMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * The span lane, in a host that has all three providers registered in the order
 * `installed.json` forces (US-014).
 *
 * Two claims live here that the config-level suite cannot make. The first is
 * that nothing opens an OTLP connection — a claim about what was *built*, not
 * about what a config key says, and one that a collector nobody is running
 * would answer with the same silence as a working install. The second is that
 * the derived configuration is actually read: upstream boots BEFORE this
 * package, so a value written only at register time is read while
 * `prism.app` and `prism.replica` may still be their defaults.
 */

/**
 * The listeners registered for one event, by class and method where the
 * listener is a callable pair (which is how every upstream instrumentation
 * registers its own).
 *
 * @return list<string>
 */
function otelListenersFor(string $event): array
{
    /** @var array<string, array<int, mixed>> $raw */
    $raw = app('events')->getRawListeners();

    return array_values(array_map(
        fn (mixed $listener): string => is_array($listener)
            ? get_debug_type($listener[0]).'::'.$listener[1]
            : get_debug_type($listener),
        $raw[$event] ?? [],
    ));
}

it('opens no OTLP connection, either while booting or while serving a request', function () {
    Route::get('/otel-transport-probe', fn () => 'ok');

    $this->get('/otel-transport-probe')->assertOk();

    // Every OTLP transport is built through `PsrTransportFactory::create()`,
    // which reaches for a PSR-18 client before it constructs anything. An empty
    // recorder therefore means no transport exists — so there is nothing that
    // could have sent, whatever the network happened to be doing.
    expect(RecordingClientDiscovery::$found)->toBe([]);
});

it('builds the noop exporters, so there is no transport to open in the first place', function () {
    expect(app(SpanExporterInterface::class))->toBeInstanceOf(NoopSpanExporter::class)
        ->and(app(MetricExporterInterface::class))->toBeInstanceOf(NoopMetricExporter::class)
        ->and(app(LogRecordExporterInterface::class))->toBeInstanceOf(LogsNoopExporter::class);
});

it('leaves the SDK switched on and flushing per iteration', function () {
    // The SDK switch is read off the ENVIRONMENT, not the config key, and
    // upstream stamped that variable during its own register() — so this is
    // the end of that derivation nothing else can see.
    expect(Sdk::isDisabled())->toBeFalse()
        ->and(config('opentelemetry.worker_mode.flush_after_each_iteration'))->toBeTrue();
});

it('traces a request, which is the lane the waterfall hangs from', function () {
    // Not a config assertion: the root span only exists if the HTTP server
    // instrumentation was registered and its middleware ran.
    Route::get('/otel-trace-probe', fn () => Tracer::traceStarted() ? 'traced' : 'untraced');

    $this->get('/otel-trace-probe')->assertOk()->assertSee('traced');
});

it('instruments the query and redis lanes', function () {
    expect(otelListenersFor(QueryExecuted::class))
        ->toContain(QueryInstrumentation::class.'::recordQuery')
        ->and(otelListenersFor(CommandExecuted::class))
        ->toContain(RedisInstrumentation::class.'::recordCommand');
});

it('instruments no cache lane, because a span event is not a span', function () {
    // Upstream's cache instrumentation only calls `addEvent()` on the active
    // span. Prism has no column for a span event, so the cache lane stays the
    // capture engine's own `cache-event` record — and two half-lanes would be
    // worse than one whole one.
    $listeners = otelListenersFor(CacheHit::class);

    expect($listeners)->not->toContain(CacheInstrumentation::class.'::recordCacheHit')
        // ...while Prism's own cache capture is still there, so the absence
        // above is a decision rather than an empty event system.
        ->and($listeners)->not->toBe([]);
});

it('names the process from the identity prism ends up with, not the one it registered with', function () {
    // `prism.app` and `prism.replica` are set by the test case's
    // `defineEnvironment()`, which Testbench runs AFTER `RegisterProviders` —
    // the same shape as a host that names its process from its own boot(), or
    // Octane. Upstream reads all three of these while it boots, and it boots
    // before this package, so only the `booting` re-derivation can put the
    // final values in front of it.
    expect(config('opentelemetry.service_name'))->toBe('demo')
        ->and(config('opentelemetry.service_instance_id'))->toBe('web-1')
        // A wrong value that equals the right value's default is invisible:
        // upstream's own default for the instance id is the container hostname.
        ->and(config('opentelemetry.service_instance_id'))->not->toBe(gethostname())
        ->and(config('opentelemetry.resource_attributes'))
        ->toBe(['deployment.environment' => 'production']);
});
