<?php

use Illuminate\Support\ServiceProvider;
use Misakstvanu\Prism\PrismServiceProvider;

it('registers and boots the service provider', function () {
    expect(app()->getProvider(PrismServiceProvider::class))
        ->toBeInstanceOf(PrismServiceProvider::class);
});

it('merges the package config under the prism key', function () {
    expect(config('prism.enabled'))->toBeTrue()
        ->and(config('prism.endpoint'))->toBeString();
});

it('registers the config under the prism-config publish tag', function () {
    // PHPUnit runs in the console, so boot() registers the publishable path.
    expect(ServiceProvider::pathsToPublish(PrismServiceProvider::class, 'prism-config'))
        ->not->toBeEmpty();
});
