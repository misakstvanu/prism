<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Tests;

use Illuminate\Foundation\Application;
use Keepsuit\LaravelOpenTelemetry\LaravelOpenTelemetryServiceProvider;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Misakstvanu\Prism\PrismServiceProvider;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery;

/**
 * A throwaway host with all THREE providers registered, in the order a real
 * install produces them (US-014).
 *
 * Laravel discovers providers in `installed.json` order, sorted by package
 * name, so `keepsuit/laravel-opentelemetry` sorts before `laravel/nightwatch`,
 * which sorts before `misakstvanu/prism`. That ordering is the whole difficulty
 * of the span lane's configuration: upstream reads every key Prism derives
 * while it BOOTS, and it boots first — which is why the derivation runs again
 * from an `app->booting()` callback rather than only at register time.
 * Asserting any of that under a convenient ordering would assert nothing.
 *
 * Every case in this suite runs with a recording PSR-18 client discoverer
 * installed, so "nothing opened an OTLP connection" is observable rather than
 * assumed — see {@see RecordingClientDiscovery}.
 */
abstract class OpenTelemetryHostTestCase extends NightwatchHostTestCase
{
    /**
     * Variables the two upstream packages write into the environment
     * themselves. They are process-global and would otherwise arrive carrying
     * whatever the last test in `tests/Feature` left behind.
     *
     * @var list<string>
     */
    private const VOLATILE = [
        'OTEL_SDK_DISABLED',
        'OTEL_SERVICE_NAME',
        'OTEL_TRACES_EXPORTER',
        'OTEL_METRICS_EXPORTER',
        'OTEL_LOGS_EXPORTER',
        'OTEL_SERVICE_INSTANCE_ID',
    ];

    protected function setUp(): void
    {
        foreach (self::VOLATILE as $variable) {
            self::forgetEnvironment($variable);
        }

        // Installed BEFORE the app is created: the exporters are built during
        // upstream's own boot, so a recorder installed afterwards would watch
        // the one moment that matters go by.
        RecordingClientDiscovery::reset();
        Discovery::setDiscoverers([new RecordingClientDiscovery]);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Discovery::reset();
        RecordingClientDiscovery::reset();

        foreach (self::VOLATILE as $variable) {
            self::forgetEnvironment($variable);
        }
    }

    /**
     * Unset a variable in the process environment.
     *
     * Not `Env::getRepository()->clear()`: that repository wraps phpdotenv's
     * `ImmutableWriter`, which refuses — silently — to touch anything it did
     * not write itself, and these six are written by the two upstream packages
     * and by Prism's own provider. The provider writes them the same way, for
     * the same reason.
     */
    private static function forgetEnvironment(string $name): void
    {
        putenv($name);

        unset($_SERVER[$name], $_ENV[$name]);
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelOpenTelemetryServiceProvider::class,
            NightwatchServiceProvider::class,
            PrismServiceProvider::class,
        ];
    }
}
