<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Env;
use Laravel\Nightwatch\Core;
use Misakstvanu\Prism\PrismServiceProvider;

/**
 * Prism owns `laravel/nightwatch`'s configuration, because a host installs one
 * package and should publish one config file.
 *
 * The provider is re-registered against a throwaway config repository and a
 * throwaway config PATH in each test rather than asserted on the booted app:
 * `register()` runs once per application, and what is under test is the
 * decision it makes, not the state the Testbench app was left in.
 *
 * @param  array<string, mixed>  $config
 * @param  array<string, string>  $environment  NIGHTWATCH_* variables the host set.
 * @param  bool  $published  Whether the host has published config/nightwatch.php.
 */
function registerPrismWith(array $config, array $environment = [], bool $published = false): Repository
{
    $repository = new Repository($config);

    $app = app();
    $originalConfig = $app['config'];
    $originalPath = $app->configPath();

    $directory = sys_get_temp_dir().'/prism-nightwatch-'.bin2hex(random_bytes(6));
    mkdir($directory);

    if ($published) {
        file_put_contents($directory.'/nightwatch.php', '<?php return [];');
    }

    $app->instance('config', $repository);
    $app->useConfigPath($directory);

    foreach ($environment as $key => $value) {
        Env::getRepository()->set($key, $value);
    }

    try {
        (new PrismServiceProvider($app))->register();
    } finally {
        foreach (array_keys($environment) as $key) {
            Env::getRepository()->clear($key);
        }

        $app->instance('config', $originalConfig);
        $app->useConfigPath($originalPath);

        @unlink($directory.'/nightwatch.php');
        @rmdir($directory);
    }

    return $repository;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function prismConfig(array $overrides = []): array
{
    return ['prism' => array_merge([
        'enabled' => true,
        'token' => 'prism_live_'.str_repeat('a', 40),
        'replica' => 'web-7',
    ], $overrides)];
}

it('derives nightwatch enabled, token and server from prism config', function () {
    $config = registerPrismWith(prismConfig());

    expect($config->get('nightwatch.enabled'))->toBeTrue()
        ->and($config->get('nightwatch.token'))->toBe('prism_live_'.str_repeat('a', 40))
        // The record's own `server` field must name the same replica the batch
        // envelope does, or one process reports under two names.
        ->and($config->get('nightwatch.server'))->toBe('web-7');
});

it('leaves nightwatch dormant when prism is disabled', function () {
    // Nightwatch defaults itself to enabled and arrives as a transitive
    // dependency, so without this the master switch would silence only half of
    // the install.
    $config = registerPrismWith(prismConfig(['enabled' => false, 'token' => null]));

    expect($config->get('nightwatch.enabled'))->toBeFalse();
});

it('keeps every value a host has already published in config/nightwatch.php', function () {
    // A published file is the host's outright. It cannot be recognised by
    // asking the config repository whether the key is set: Nightwatch registers
    // BEFORE this provider in any real host, so by now its own defaults have
    // populated every key and "unset" is never true.
    $config = registerPrismWith(
        prismConfig() + ['nightwatch' => ['token' => 'nightwatch-package-default']],
        published: true,
    );

    expect($config->get('nightwatch.token'))->toBe('nightwatch-package-default')
        ->and($config->has('nightwatch.server'))->toBeFalse();
});

it('leaves alone the one key a host answered through the environment', function () {
    $config = registerPrismWith(prismConfig(), ['NIGHTWATCH_SERVER' => 'host-server']);

    // Untouched, because the host said something about it...
    expect($config->has('nightwatch.server'))->toBeFalse()
        // ...while the two it said nothing about are still derived.
        ->and($config->get('nightwatch.token'))->toBe('prism_live_'.str_repeat('a', 40))
        ->and($config->get('nightwatch.enabled'))->toBeTrue();
});

it('leaves the agent ingest uri alone — nothing opens that socket', function () {
    // The transport is replaced wholesale by assigning over `Core::$ingest`, so
    // pointing this anywhere would be a distraction at best and a stray TCP
    // connection at worst.
    $config = registerPrismWith(prismConfig());

    expect($config->has('nightwatch.ingest'))->toBeFalse();
});

it('leaves exception source-code capture alone, so a frame keeps its context', function () {
    // `capture_exception_source_code` is upstream's, on by default, and the only
    // place the lines around a throw exist: the record's serialised trace is
    // what becomes `frames`, and the error detail screen has nothing else to
    // draw a fault in context from. Prism derives six keys and this is not one
    // of them — a later story adding a seventh must not quietly take it.
    $config = registerPrismWith(prismConfig());

    expect($config->has('nightwatch.capture_exception_source_code'))->toBeFalse()
        ->and(require dirname(__DIR__, 2).'/vendor/laravel/nightwatch/config/nightwatch.php')
        ->toHaveKey('capture_exception_source_code', true);
});

it('derives the redaction keys the engine\'s own request sensor reads from prism.scrub', function () {
    // One list governs every signal (US-011), so the two keys upstream reads
    // are derived rather than kept beside `prism.scrub` — and upstream's own
    // defaults survive underneath, because a host relying on `_token` being
    // blanked did not ask to stop by installing Prism.
    $config = registerPrismWith(prismConfig(['scrub' => ['password', 'x-tenant-secret']]));

    expect($config->get('nightwatch.redact_payload_fields'))
        ->toBe(['_token', 'password', 'password_confirmation', 'x-tenant-secret'])
        ->and($config->get('nightwatch.redact_headers'))
        ->toBe(['Authorization', 'Cookie', 'Proxy-Authorization', 'X-XSRF-TOKEN', 'password', 'x-tenant-secret']);
});

it('keeps request bodies off by default', function () {
    // Upstream's default, kept deliberately. Off, a body is recorded only for a
    // request that faulted — the privacy-preserving answer and a debugging
    // limitation, so the switch is exposed rather than buried.
    $config = registerPrismWith(prismConfig());

    expect($config->get('nightwatch.capture_request_payload'))->toBeFalse();

    $enabled = registerPrismWith(prismConfig(['request' => ['capture_payload' => true]]));

    expect($enabled->get('nightwatch.capture_request_payload'))->toBeTrue();
});

it('leaves the redaction keys a host answered through the environment alone', function () {
    $config = registerPrismWith(prismConfig(), [
        'NIGHTWATCH_REDACT_HEADERS' => 'X-Only-This',
        'NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD' => '1',
    ]);

    expect($config->has('nightwatch.redact_headers'))->toBeFalse()
        ->and($config->has('nightwatch.capture_request_payload'))->toBeFalse()
        // ...while the one it said nothing about is still derived.
        ->and($config->get('nightwatch.redact_payload_fields'))->toContain('password');
});

it('maps the three prism sample rates onto the engine\'s own sampling block', function () {
    // Sampling is per EXECUTION on both sides, so the rates map one-for-one.
    // Only the scheduled-task key is spelt differently — Prism says "schedules"
    // wherever it names that signal, upstream says "scheduled_tasks".
    $config = registerPrismWith(prismConfig([
        'sample' => ['requests' => 0.25, 'commands' => 0.5, 'schedules' => 0.75],
    ]));

    expect($config->get('nightwatch.sampling.requests'))->toBe(0.25)
        ->and($config->get('nightwatch.sampling.commands'))->toBe(0.5)
        ->and($config->get('nightwatch.sampling.scheduled_tasks'))->toBe(0.75);
});

it('pins the exception rate at 1.0, with no way to turn it down', function () {
    // The one derived key with no environment escape hatch, because an error
    // that never arrives is the failure mode sampling must not have: upstream
    // re-rolls a sampled-out execution against this rate the moment it throws.
    $config = registerPrismWith(prismConfig([
        'sample' => ['requests' => 0.01],
    ]), ['NIGHTWATCH_EXCEPTION_SAMPLE_RATE' => '0']);

    expect($config->get('nightwatch.sampling.exceptions'))->toBe(1.0)
        // ...and the rate that DOES have an escape hatch still has one, so the
        // pin above is a deliberate exception rather than the general rule.
        ->and($config->get('nightwatch.sampling.requests'))->toBe(0.01);
});

it('treats an unreadable rate as capture-everything, never capture-nothing', function () {
    // `(float) null` is 0.0, so a `prism.sample` block that went missing would
    // otherwise switch the whole install off silently and permanently. It is
    // reachable: the package/host config merge is SHALLOW, so a host answering
    // `sample` at all replaces the block outright — as does a `config/prism.php`
    // published before this key existed. An EMPTY block is that shape; simply
    // omitting the key is not, because `mergeConfigFrom` puts the package's own
    // defaults back underneath.
    $config = registerPrismWith(prismConfig(['sample' => []]));

    expect($config->get('nightwatch.sampling.requests'))->toBe(1.0)
        ->and($config->get('nightwatch.sampling.commands'))->toBe(1.0)
        ->and($config->get('nightwatch.sampling.scheduled_tasks'))->toBe(1.0);
});

it('clamps a rate outside the range the engine accepts', function () {
    // `Core::sample()` reads anything outside 0..1 as 0.0 — so an over-eager
    // "2.0" would capture nothing at all. Clamped, it means what it says.
    $config = registerPrismWith(prismConfig([
        'sample' => ['requests' => 2.0, 'commands' => -1.0],
    ]));

    expect($config->get('nightwatch.sampling.requests'))->toBe(1.0)
        ->and($config->get('nightwatch.sampling.commands'))->toBe(0.0);
});

it('leaves a sample rate a host answered through the environment alone', function () {
    $config = registerPrismWith(prismConfig([
        'sample' => ['requests' => 0.25, 'commands' => 0.5],
    ]), ['NIGHTWATCH_REQUEST_SAMPLE_RATE' => '0.9']);

    expect($config->has('nightwatch.sampling.requests'))->toBeFalse()
        ->and($config->get('nightwatch.sampling.commands'))->toBe(0.5);
});

it('reconciles a Core that nightwatch built before this provider registered', function () {
    // The real-install path: `laravel/nightwatch` sorts before
    // `misakstvanu/prism` in installed.json, so Nightwatch has already read its
    // own defaults into Core by the time Prism registers. Writing config alone
    // would be a silent no-op.
    $core = makeNightwatchCore(enabled: true, server: 'nightwatch-default');
    app()->instance(Core::class, $core);

    registerPrismWith(prismConfig());

    expect($core->executionState->server)->toBe('web-7')
        ->and($core->config['enabled'])->toBeTrue()
        ->and($core->paused())->toBeFalse();
});

it('pushes the sampling rates onto a Core nightwatch already built', function () {
    // `Core::$config` is a plain array snapshotted during Nightwatch's own
    // `register()`, and every execution reads its rate out of THAT — so the
    // config keys written a moment ago are read by nobody on the ordering a
    // real install has, and the rates would silently stay at upstream's 1.0.
    $core = makeNightwatchCore(enabled: true, server: 'nightwatch-default');
    app()->instance(Core::class, $core);

    registerPrismWith(prismConfig([
        'sample' => ['requests' => 0.25, 'commands' => 0.5, 'schedules' => 0.75],
    ]));

    expect($core->config['sampling'])->toBe([
        'requests' => 0.25,
        'commands' => 0.5,
        'exceptions' => 1.0,
        'scheduled_tasks' => 0.75,
    ]);
});

it('pauses an already-registered Core when prism is disabled', function () {
    // Hooks cannot be un-registered once Nightwatch has wired them, so the
    // master switch reaches for the runtime flag every sensor checks instead.
    $core = makeNightwatchCore(enabled: true, server: 'nightwatch-default');
    app()->instance(Core::class, $core);

    registerPrismWith(prismConfig(['enabled' => false, 'token' => null]));

    expect($core->config['enabled'])->toBeFalse()
        ->and($core->paused())->toBeTrue();
});

it('does not touch an already-registered Core when the host published its own config', function () {
    $core = makeNightwatchCore(enabled: true, server: 'nightwatch-default');
    app()->instance(Core::class, $core);

    registerPrismWith(prismConfig(['enabled' => false]), published: true);

    expect($core->executionState->server)->toBe('nightwatch-default')
        ->and($core->config['enabled'])->toBeTrue()
        ->and($core->paused())->toBeFalse();
});
