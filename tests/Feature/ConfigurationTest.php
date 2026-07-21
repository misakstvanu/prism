<?php

use Illuminate\Support\Facades\Log;
use Misakstvanu\Prism\PrismServiceProvider;

/**
 * Re-run the provider's boot() against the current app after config has been
 * changed for the test, clearing any capture flag a prior boot left so the
 * assertions reflect exactly this configuration. boot() is idempotent — the
 * only side effect that matters here is whether it wires capture.
 */
function bootPrism(): void
{
    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    (new PrismServiceProvider(app()))->boot();
}

it('requires only PRISM_TOKEN and PRISM_APP — everything else has a working default', function () {
    // Nothing but the two credentials is set in the Testbench environment, yet
    // every other setting resolves to a usable value.
    expect(config('prism.endpoint'))->toBe('https://prism.dev/api/ingest')
        ->and(config('prism.environment'))->toBeString()->not->toBeEmpty()
        ->and(config('prism.replica'))->toBeString()->not->toBeEmpty()
        ->and(config('prism.batch.size'))->toBe(1000)
        ->and(config('prism.batch.flush'))->toBe('terminate');
});

it('covers every documented config domain', function () {
    expect(config('prism'))
        ->toHaveKeys([
            'enabled', 'token', 'app', 'endpoint', 'environment', 'replica',
            'capture', 'sample_rates', 'batch', 'request', 'ignore', 'scrub',
        ]);

    expect(config('prism.capture'))->toHaveKeys([
        'requests', 'exceptions', 'logs', 'queries', 'traces', 'jobs', 'schedules', 'metrics',
    ]);
    expect(config('prism.metrics'))->toHaveKey('queue_interval');
    expect(config('prism.metrics.queue_interval'))->toBe(30);
    expect(config('prism.ignore'))->toHaveKeys(['paths', 'jobs']);
    expect(config('prism.scrub'))->toContain('password')->toContain('authorization');
});

it('exceptions are pinned to a 1.0 sample rate and never sampled out', function () {
    expect(config('prism.sample_rates.exceptions'))->toBe(1.0);
});

it('registers no capture and stays silent when disabled', function () {
    // With no listeners built yet (US-042+), the honest proxy for "no listeners
    // registered at all" is that registerCapture() — where every future
    // listener hangs — is never reached, so the ACTIVE flag stays unbound.
    Log::spy();
    config(['prism.enabled' => false]);

    bootPrism();

    expect(app()->bound(PrismServiceProvider::ACTIVE))->toBeFalse();
    Log::shouldNotHaveReceived('warning');
});

it('warns exactly once and no-ops when enabled without a token', function () {
    Log::spy();
    config(['prism.enabled' => true, 'prism.token' => null]);

    bootPrism();

    expect(app()->bound(PrismServiceProvider::ACTIVE))->toBeFalse();
    Log::shouldHaveReceived('warning')->once();
});

it('does not throw when enabled without a token', function () {
    config(['prism.enabled' => true, 'prism.token' => null]);

    expect(fn () => bootPrism())->not->toThrow(Throwable::class);
});

it('wires capture when enabled with a token', function () {
    Log::spy();
    config([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
    ]);

    bootPrism();

    expect(app()->bound(PrismServiceProvider::ACTIVE))->toBeTrue();
    Log::shouldNotHaveReceived('warning');
});
