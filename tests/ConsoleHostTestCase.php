<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Tests;

/**
 * The same host as {@see NightwatchHostTestCase}, running on the **console**
 * side — a worker, or a plain artisan command.
 *
 * A premise of its own for the reason every other one in this suite has one:
 * the thing under test is decided while the application is being built, so it
 * cannot be arranged from inside a test body. Nightwatch asks
 * `runningInConsole()` **once, at register time**, and wires one of two
 * disjoint sets of hooks from the answer. Two suites live on the far side of
 * it:
 *
 *   - `tests/Jobs` — the execution state is a `CommandState`, which
 *     `JobAttemptSensor` requires by type signature (handed a `RequestState` it
 *     is a `TypeError`, not a degraded record), and the job hooks themselves
 *     are registered by `CommandStartingListener` when a `queue:work`-shaped
 *     command starts, which is a listener the request side never registers.
 *   - `tests/Commands` — a command record exists at all only because that same
 *     listener registered `whenCommandLifecycleIsLongerThan` on the console
 *     kernel, and the kernel it checks for by type is the console one.
 *
 * The rest of `tests/Host` forces request mode precisely so a lifecycle
 * assertion is not vacuous; both of these need the opposite, so they say so
 * through {@see NightwatchHostTestCase::forcesRequestMode()} rather than by
 * restating the provider list.
 */
abstract class ConsoleHostTestCase extends NightwatchHostTestCase
{
    protected function forcesRequestMode(): bool
    {
        return false;
    }
}
