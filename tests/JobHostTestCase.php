<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Tests;

/**
 * The same host as {@see NightwatchHostTestCase}, running as a **worker**.
 *
 * A directory of its own for the reason every other premise in this suite has
 * one: the thing under test is decided while the application is being built,
 * so it cannot be arranged from inside a test body. Nightwatch asks
 * `runningInConsole()` **once, at register time**, and wires one of two
 * disjoint sets of hooks from the answer — and everything a job's telemetry is
 * made of lives on the console side of it:
 *
 *   - the execution state is a `CommandState`, which `JobAttemptSensor`
 *     requires by type signature (handed a `RequestState` it is a `TypeError`,
 *     not a degraded record);
 *   - the job hooks themselves are registered by `CommandStartingListener` when
 *     a `queue:work`-shaped command starts, which is a listener the request
 *     side never registers at all.
 *
 * The rest of `tests/Host` forces request mode precisely so a lifecycle
 * assertion is not vacuous; this suite needs the opposite, so it says so
 * through {@see forcesRequestMode()} rather than by restating the provider list.
 */
abstract class JobHostTestCase extends NightwatchHostTestCase
{
    protected function forcesRequestMode(): bool
    {
        return false;
    }
}
