<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Misakstvanu\Prism\PrismServiceProvider;

/**
 * A throwaway host with **both** providers registered, in the order a real
 * install produces them.
 *
 * Testbench does not auto-discover packages, so `getPackageProviders()` is the
 * whole provider list — which is what lets this case reproduce the ordering a
 * host cannot avoid: Laravel registers providers in `installed.json` order,
 * sorted by package name, and `laravel/nightwatch` sorts before
 * `misakstvanu/prism` in every host there will ever be. Everything the ingest
 * swap has to survive follows from that order, so a suite asserting the swap
 * must run under it rather than under a convenient one.
 *
 * The rest of the package's suite deliberately registers Prism alone (see
 * {@see TestCase}), proving the package stands up with no host and no
 * Nightwatch provider — that is a different claim and it keeps its own case.
 */
abstract class NightwatchHostTestCase extends TestCase
{
    protected function setUp(): void
    {
        // Nightwatch decides ONCE, at register time, whether this process is
        // serving a request or running a command — from `runningInConsole()`,
        // which is true under PHPUnit. Without this it wires the console hooks
        // and a test driving a request through the kernel would exercise
        // nothing at all, which is the quiet way for a lifecycle assertion to
        // become vacuous.
        if ($this->forcesRequestMode()) {
            Env::getRepository()->set('NIGHTWATCH_FORCE_REQUEST', '1');
        } else {
            Env::getRepository()->clear('NIGHTWATCH_FORCE_REQUEST');
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Env::getRepository()->clear('NIGHTWATCH_FORCE_REQUEST');
    }

    /**
     * Whether this case pretends the process is serving an HTTP request.
     *
     * True for every suite whose subject is a request. A suite whose subject is
     * a **worker** or an **artisan command** answers false, because the engine's
     * job-attempt hooks, its command hooks and its `CommandState` exist only on
     * the console side of that one register-time decision — see
     * {@see ConsoleHostTestCase}.
     */
    protected function forcesRequestMode(): bool
    {
        return true;
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            NightwatchServiceProvider::class,
            PrismServiceProvider::class,
        ];
    }

    /**
     * A fully configured Prism, so `registerCapture()` — and with it the ingest
     * swap — is reached during the app's own boot rather than only when a test
     * re-runs it.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('prism.enabled', true);
        $app['config']->set('prism.token', 'prism_live_'.str_repeat('a', 40));
        $app['config']->set('prism.app', 'demo');
        $app['config']->set('prism.environment', 'production');
        $app['config']->set('prism.replica', 'web-1');
        $app['config']->set('prism.batch.flush', 'terminate');
        $app['config']->set('prism.batch.queue_threshold', 0);
    }
}
