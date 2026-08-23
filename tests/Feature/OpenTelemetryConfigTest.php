<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Env;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\CacheInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\ConsoleInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\EventInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpClientInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpServerInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\LivewireInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\QueryInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\QueueInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\RedisInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\ScoutInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\ViewInstrumentation;
use Keepsuit\LaravelOpenTelemetry\TailSampling\Rules\ErrorsRule;
use Keepsuit\LaravelOpenTelemetry\TailSampling\Rules\SlowTraceRule;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Misakstvanu\Prism\PrismServiceProvider;

/**
 * Prism owns `keepsuit/laravel-opentelemetry`'s configuration for the reason it
 * owns Nightwatch's: a host installs one package and should publish one config
 * file (US-014).
 *
 * The provider is re-registered against a throwaway config repository and a
 * throwaway config PATH in each test rather than asserted on the booted app —
 * `register()` runs once per application and what is under test is the decision
 * it makes, not the state the Testbench app was left in.
 *
 * Two of the environment variables involved are written by UPSTREAM during its
 * own `register()` (`OTEL_SDK_DISABLED` and `OTEL_SERVICE_NAME`), so this helper
 * snapshots and restores them around every call: they are global state that
 * would otherwise leak from one test into the next, and into the `tests/Otel`
 * suite that runs in the same process.
 *
 * @param  array<string, mixed>  $config
 * @param  array<string, string>  $environment  OTEL_* variables the host set.
 * @param  bool  $published  Whether the host has published config/opentelemetry.php.
 * @return array{config: Repository, environment: array<string, ?string>}
 */
function registerPrismForOtel(array $config, array $environment = [], bool $published = false): array
{
    $repository = new Repository($config);

    $app = app();
    $originalConfig = $app['config'];
    $originalPath = $app->configPath();

    // Written by the derivation itself, so their pre-existing values have to
    // come back or the next test inherits this one's answer. Read and restored
    // through the process environment rather than through Laravel's repository,
    // for the reason the provider writes them that way: the repository's
    // ImmutableWriter refuses to overwrite a variable it did not write itself,
    // silently.
    $stamped = ['OTEL_SDK_DISABLED', 'OTEL_SERVICE_NAME'];
    $originalEnvironment = [];

    foreach ($stamped as $key) {
        $value = getenv($key);
        $originalEnvironment[$key] = $value === false ? null : $value;
    }

    $directory = sys_get_temp_dir().'/prism-otel-'.bin2hex(random_bytes(6));
    mkdir($directory);

    if ($published) {
        file_put_contents($directory.'/opentelemetry.php', '<?php return [];');
    }

    $app->instance('config', $repository);
    $app->useConfigPath($directory);

    foreach ($environment as $key => $value) {
        Env::getRepository()->set($key, $value);
    }

    try {
        (new PrismServiceProvider($app))->register();

        $written = [];

        foreach ($stamped as $key) {
            $value = getenv($key);
            $written[$key] = $value === false ? null : $value;
        }
    } finally {
        foreach (array_keys($environment) as $key) {
            Env::getRepository()->clear($key);
        }

        foreach ($originalEnvironment as $key => $value) {
            $value === null ? forgetProcessEnvironment($key) : putProcessEnvironment($key, $value);
        }

        $app->instance('config', $originalConfig);
        $app->useConfigPath($originalPath);

        @unlink($directory.'/opentelemetry.php');
        @rmdir($directory);
    }

    return ['config' => $repository, 'environment' => $written];
}

/**
 * Write a variable into the process environment the way the provider does —
 * `putenv()` plus both superglobals — because Laravel's environment repository
 * silently refuses to overwrite anything it did not write itself.
 */
function putProcessEnvironment(string $name, string $value): void
{
    putenv("{$name}={$value}");

    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
}

/** The other half, for the same reason: `clear()` is refused just as `set()` is. */
function forgetProcessEnvironment(string $name): void
{
    putenv($name);

    unset($_SERVER[$name], $_ENV[$name]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function prismOtelConfig(array $overrides = []): array
{
    return ['prism' => array_merge([
        'enabled' => true,
        'token' => 'prism_live_'.str_repeat('a', 40),
        'app' => 'checkout',
        'environment' => 'staging',
        'replica' => 'web-7',
        'otel' => ['enabled' => true],
    ], $overrides)];
}

it('pins all three exporters to null, so nothing can open an OTLP connection', function () {
    // Spans are consumed in process and ship in Prism's own batch. "null" is
    // upstream's own name for its Noop exporters, which is what keeps a PSR-18
    // client from ever being discovered — see the tests/Otel suite, which
    // asserts that end of it.
    $config = registerPrismForOtel(prismOtelConfig())['config'];

    expect($config->get('opentelemetry.traces.exporter'))->toBe('null')
        ->and($config->get('opentelemetry.metrics.exporter'))->toBe('null')
        ->and($config->get('opentelemetry.logs.exporter'))->toBe('null');
});

it('names the process from prism identity, so a span and its batch agree', function () {
    $config = registerPrismForOtel(prismOtelConfig())['config'];

    expect($config->get('opentelemetry.service_name'))->toBe('checkout')
        ->and($config->get('opentelemetry.service_instance_id'))->toBe('web-7')
        ->and($config->get('opentelemetry.resource_attributes'))
        ->toBe(['deployment.environment' => 'staging']);
});

it('stamps the service name into the environment as well, because upstream already read the variable', function () {
    // `configureEnvironmentVariables()` copies the config value into
    // OTEL_SERVICE_NAME during upstream's own register(), which happens BEFORE
    // this provider in any real install — so the variable holds a value derived
    // from APP_NAME unless it is written again here.
    $written = registerPrismForOtel(prismOtelConfig())['environment'];

    expect($written['OTEL_SERVICE_NAME'])->toBe('checkout');
});

it('leaves the service name alone for an install that has not been configured yet', function () {
    // Blank is an unconfigured install, and an empty service name is worse than
    // upstream's own guess.
    $config = registerPrismForOtel(prismOtelConfig(['app' => null]))['config'];

    expect($config->has('opentelemetry.service_name'))->toBeFalse();
});

it('enables the five instrumentations the waterfall is drawn from', function () {
    $config = registerPrismForOtel(prismOtelConfig())['config'];

    foreach ([
        HttpServerInstrumentation::class,
        HttpClientInstrumentation::class,
        QueryInstrumentation::class,
        QueueInstrumentation::class,
        RedisInstrumentation::class,
    ] as $class) {
        $value = $config->get('opentelemetry.instrumentation.'.$class);

        expect(is_array($value) ? $value['enabled'] : $value)->toBeTrue($class);
    }
});

it('disables the six that would double a signal or arrive with nowhere to go', function () {
    // `cache` only calls addEvent() on the active span — span events are not
    // spans and Prism has no column for them, so the cache lane stays the
    // capture engine's record. `console` would double the engine's own command
    // sensor. The other four have no Prism counterpart at all.
    $config = registerPrismForOtel(prismOtelConfig())['config'];

    foreach ([
        CacheInstrumentation::class,
        ConsoleInstrumentation::class,
        EventInstrumentation::class,
        ViewInstrumentation::class,
        LivewireInstrumentation::class,
        ScoutInstrumentation::class,
    ] as $class) {
        $value = $config->get('opentelemetry.instrumentation.'.$class);

        expect(is_array($value) ? $value['enabled'] : $value)->toBeFalse($class);
    }
});

it('keeps a host own instrumentation options and writes only the verdict', function () {
    $config = registerPrismForOtel(prismOtelConfig() + [
        'opentelemetry' => [
            'instrumentation' => [
                HttpServerInstrumentation::class => [
                    'enabled' => false,
                    'excluded_paths' => ['health'],
                ],
            ],
        ],
    ])['config'];

    expect($config->get('opentelemetry.instrumentation.'.HttpServerInstrumentation::class))
        ->toBe(['enabled' => true, 'excluded_paths' => ['health']]);
});

it('flushes a worker spans per iteration', function () {
    // Upstream defaults this to FALSE, which under a queue worker means one
    // job's spans are still in the batch processor when the next job starts and
    // ship inside its batch — landing under the wrong execution, silently.
    $config = registerPrismForOtel(prismOtelConfig())['config'];

    expect($config->get('opentelemetry.worker_mode.flush_after_each_iteration'))->toBeTrue();
});

it('leaves the SDK switched on for a configured install', function () {
    $result = registerPrismForOtel(prismOtelConfig());

    expect($result['config']->get('opentelemetry.disabled'))->toBeFalse()
        ->and($result['environment']['OTEL_SDK_DISABLED'])->toBe('false');
});

it('switches the SDK off when the span lane is turned off', function () {
    $result = registerPrismForOtel(prismOtelConfig(['otel' => ['enabled' => false]]));

    expect($result['config']->get('opentelemetry.disabled'))->toBeTrue()
        // The config key alone is read by nobody: Sdk::isDisabled() reads the
        // environment variable, which upstream stamped during its own
        // register() from a value that predates this decision.
        ->and($result['environment']['OTEL_SDK_DISABLED'])->toBe('true');
});

it('switches the SDK off with the master switch, like everything else', function () {
    $result = registerPrismForOtel(prismOtelConfig(['enabled' => false, 'token' => null]));

    expect($result['config']->get('opentelemetry.disabled'))->toBeTrue()
        ->and($result['environment']['OTEL_SDK_DISABLED'])->toBe('true');
});

it('never switches the SDK back on when the host had already disabled it', function () {
    $result = registerPrismForOtel(prismOtelConfig() + [
        'opentelemetry' => ['disabled' => true],
    ]);

    // Nothing is written at all on this path — not the key and not the
    // variable — which is what makes the decision survive being made twice.
    expect($result['config']->get('opentelemetry.disabled'))->toBeTrue();
});

it('decides the SDK switch once, so a late-arriving prism config cannot pin it off', function () {
    // The derivation runs twice (register, then again from a `booting`
    // callback, because the identity is not final at register time). By the
    // second pass `opentelemetry.disabled` holds Prism's own answer, so
    // re-reading it as "the host's" would let a first pass made while the
    // client still looked disabled keep the SDK off for good.
    $repository = new Repository(prismOtelConfig(['enabled' => false]));

    $app = app();
    $originalConfig = $app['config'];
    $originalPath = $app->configPath();
    $original = getenv('OTEL_SDK_DISABLED');

    $directory = sys_get_temp_dir().'/prism-otel-'.bin2hex(random_bytes(6));
    mkdir($directory);

    $app->instance('config', $repository);
    $app->useConfigPath($directory);

    try {
        $provider = new PrismServiceProvider($app);
        $provider->register();

        expect($repository->get('opentelemetry.disabled'))->toBeTrue();

        // The second pass is what the `booting` callback runs: the SAME method
        // on the SAME provider, against a repository the host has since
        // answered properly. Reached by reflection because a callback queued
        // on an application that is already booted never fires.
        $repository->set('prism.enabled', true);

        (new ReflectionMethod($provider, 'applyOpenTelemetryConfig'))->invoke($provider);

        expect($repository->get('opentelemetry.disabled'))->toBeFalse();
    } finally {
        $original === false
            ? forgetProcessEnvironment('OTEL_SDK_DISABLED')
            : putProcessEnvironment('OTEL_SDK_DISABLED', $original);

        $app->instance('config', $originalConfig);
        $app->useConfigPath($originalPath);

        @rmdir($directory);
    }
});

it('keeps every value a host has already published in config/opentelemetry.php', function () {
    // A published file means the host is running the SDK on its own account —
    // exporting to its own collector, most likely — so Prism does not argue
    // with any of it.
    $config = registerPrismForOtel(
        prismOtelConfig() + ['opentelemetry' => ['traces' => ['exporter' => 'otlp']]],
        published: true,
    )['config'];

    expect($config->get('opentelemetry.traces.exporter'))->toBe('otlp')
        ->and($config->has('opentelemetry.service_instance_id'))->toBeFalse()
        ->and($config->has('opentelemetry.worker_mode.flush_after_each_iteration'))->toBeFalse();
});

it('registers the span processor in upstream own slot, rather than replacing the tracer', function () {
    // Through the slot because the TracerProvider keepsuit builds carries the
    // resource naming this application, the sampler and the propagators —
    // owning a second one would mean owning all of that, and quietly taking the
    // tracer away from a host that later wants its own (US-015).
    $config = registerPrismForOtel(prismOtelConfig())['config'];

    expect($config->get('opentelemetry.traces.processors'))->toBe([PrismSpanProcessor::class]);
});

it('adds the span processor beside a host own, never instead of it', function () {
    $config = registerPrismForOtel(
        prismOtelConfig() + ['opentelemetry' => ['traces' => ['processors' => ['App\\Otel\\HostProcessor']]]],
    )['config'];

    expect($config->get('opentelemetry.traces.processors'))
        ->toBe(['App\\Otel\\HostProcessor', PrismSpanProcessor::class]);
});

it('registers the span processor only once, because the derivation runs twice', function () {
    // `configureOpenTelemetry()` applies everything at register time AND again
    // from an `app->booting()` callback, because prism.app / prism.replica are
    // not final at register time. A list that grew on the second pass would
    // buffer every span twice, so the state under test is the one the first
    // pass leaves behind.
    $config = registerPrismForOtel(
        prismOtelConfig() + ['opentelemetry' => ['traces' => ['processors' => [PrismSpanProcessor::class]]]],
    )['config'];

    expect($config->get('opentelemetry.traces.processors'))->toBe([PrismSpanProcessor::class]);
});

it('registers no span processor for an install nothing would ship from', function (array $overrides) {
    // An enabled install with no token never reaches registerCapture(), so
    // nothing binds the buffer the processor writes into and nothing ships what
    // it collects — a processor there would fill a buffer no flush drains.
    $config = registerPrismForOtel(prismOtelConfig($overrides))['config'];

    expect($config->get('opentelemetry.traces.processors', []))->toBe([]);
})->with([
    'the master switch is off' => [['enabled' => false]],
    'the span lane is off' => [['otel' => ['enabled' => false]]],
    'there is no token' => [['token' => null]],
]);

it('leaves alone the one key a host answered through the environment', function () {
    $config = registerPrismForOtel(prismOtelConfig(), ['OTEL_TRACES_EXPORTER' => 'otlp'])['config'];

    // Untouched, because the host said something about it...
    expect($config->has('opentelemetry.traces.exporter'))->toBeFalse()
        // ...while the ones it said nothing about are still derived.
        ->and($config->get('opentelemetry.metrics.exporter'))->toBe('null')
        ->and($config->get('opentelemetry.service_instance_id'))->toBe('web-7');
});

it('keeps a host own resource attributes alongside prism one', function () {
    // Resource attributes are additive, so whatever OTEL_RESOURCE_ATTRIBUTES
    // parsed into survives beside the dimension Prism has to add.
    $config = registerPrismForOtel(prismOtelConfig() + [
        'opentelemetry' => ['resource_attributes' => ['team' => 'payments']],
    ])['config'];

    expect($config->get('opentelemetry.resource_attributes'))->toBe([
        'team' => 'payments',
        'deployment.environment' => 'staging',
    ]);
});

it('switches the SDK off even when the environment repository refuses the write', function () {
    // Upstream stamps OTEL_SDK_DISABLED into the environment during its own
    // register(), and Laravel's repository wraps phpdotenv's ImmutableWriter,
    // which then refuses every later write to it — returning false, throwing
    // nothing. A provider that wrote through the repository would leave a
    // config key saying the SDK is off and an SDK that is on.
    $original = getenv('OTEL_SDK_DISABLED');

    // Defined outside the repository, which is exactly the state that makes it
    // refuse: `set()` below returns false and changes nothing.
    putProcessEnvironment('OTEL_SDK_DISABLED', 'false');

    expect(Env::getRepository()->set('OTEL_SDK_DISABLED', 'true'))->toBeFalse()
        ->and(getenv('OTEL_SDK_DISABLED'))->toBe('false');

    try {
        $result = registerPrismForOtel(prismOtelConfig(['otel' => ['enabled' => false]]));

        expect($result['environment']['OTEL_SDK_DISABLED'])->toBe('true');
    } finally {
        $original === false
            ? forgetProcessEnvironment('OTEL_SDK_DISABLED')
            : putProcessEnvironment('OTEL_SDK_DISABLED', $original);
    }
});

it('turns tail sampling on with both rules, so a slow or errored trace is kept whole', function () {
    // The client-side half of a rule the Prism server already enforces (a slow
    // query pulls its whole trace through). They are belt and braces rather
    // than duplicates: the server's keeps a trace once it has arrived, this one
    // keeps it from being dropped before it is sent at all (US-019).
    $config = registerPrismForOtel(prismOtelConfig())['config'];

    expect($config->get('opentelemetry.traces.sampler.tail_sampling.enabled'))->toBeTrue()
        ->and($config->get('opentelemetry.traces.sampler.tail_sampling.rules'))->toBe([
            ErrorsRule::class => true,
            SlowTraceRule::class => ['enabled' => true, 'threshold_ms' => 2000],
        ]);
});

it('drives the slow-trace threshold from PRISM_SLOW_TRACE_MS', function () {
    // `prism.otel.slow_trace_ms` is that variable's config key; a host answers
    // one number and both engines' idea of "slow" comes from it.
    $config = registerPrismForOtel(
        prismOtelConfig(['otel' => ['enabled' => true, 'slow_trace_ms' => 750]]),
    )['config'];

    $rules = $config->get('opentelemetry.traces.sampler.tail_sampling.rules');

    expect($rules[SlowTraceRule::class])->toBe(['enabled' => true, 'threshold_ms' => 750]);
});

it('falls back to the documented threshold rather than to zero', function (mixed $value) {
    // `(int) null` is 0 and a threshold of zero reads as "every trace is slow",
    // which turns tail sampling into "keep everything" with nothing anywhere
    // saying so — the same shape as the sample-rate trap one config block over.
    $config = registerPrismForOtel(
        prismOtelConfig(['otel' => ['enabled' => true, 'slow_trace_ms' => $value]]),
    )['config'];

    $rules = $config->get('opentelemetry.traces.sampler.tail_sampling.rules');

    expect($rules[SlowTraceRule::class]['threshold_ms'])->toBe(2000);
})->with([
    'the key is missing' => [null],
    'the key is not a number' => ['soon'],
    'the key is zero' => [0],
    'the key is negative' => [-1],
]);

it('leaves each tail-sampling answer a host gave through the environment', function () {
    $config = registerPrismForOtel(prismOtelConfig(), [
        'OTEL_TRACES_TAIL_SAMPLING_ENABLED' => 'false',
        'OTEL_TRACES_TAIL_SAMPLING_RULE_SLOW_TRACES' => 'false',
    ])['config'];

    // Untouched, because the host said something about them...
    expect($config->has('opentelemetry.traces.sampler.tail_sampling.enabled'))->toBeFalse()
        ->and($config->get('opentelemetry.traces.sampler.tail_sampling.rules'))
        // ...while the rule it said nothing about is still derived.
        ->toBe([ErrorsRule::class => true]);
});

it('takes the slow threshold from upstream own variable when a host answered that instead', function () {
    $config = registerPrismForOtel(
        prismOtelConfig(['otel' => ['enabled' => true, 'slow_trace_ms' => 750]]),
        ['OTEL_TRACES_TAIL_SAMPLING_SLOW_TRACES_THRESHOLD_MS' => '4000'],
    )['config'];

    $rules = $config->get('opentelemetry.traces.sampler.tail_sampling.rules');

    expect($rules[SlowTraceRule::class]['threshold_ms'])->toBe(4000);
});

it('merges a host own tail-sampling rule rather than replacing it', function () {
    $config = registerPrismForOtel(prismOtelConfig() + [
        'opentelemetry' => ['traces' => ['sampler' => ['tail_sampling' => ['rules' => [
            'App\\Otel\\KeepCheckoutRule' => true,
        ]]]]],
    ])['config'];

    expect($config->get('opentelemetry.traces.sampler.tail_sampling.rules'))->toBe([
        'App\\Otel\\KeepCheckoutRule' => true,
        ErrorsRule::class => true,
        SlowTraceRule::class => ['enabled' => true, 'threshold_ms' => 2000],
    ]);
});

it('writes no tail-sampling key at all for a host that published its own otel config', function () {
    $config = registerPrismForOtel(prismOtelConfig(), published: true)['config'];

    expect($config->has('opentelemetry.traces.sampler.tail_sampling.enabled'))->toBeFalse()
        ->and($config->has('opentelemetry.traces.sampler.tail_sampling.rules'))->toBeFalse();
});
