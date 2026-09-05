<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Tests;

use Illuminate\Foundation\Application;

/**
 * The same host as {@see NightwatchHostTestCase}, credentialled the way a
 * developer's laptop is: `PRISM_TOKEN` blank, `APP_ENV=local` (US-003).
 *
 * A fourth premise rather than a case inside `tests/Host` because the whole
 * claim is about what the provider does *while it boots* — a test that blanked
 * the token after the application had been created would be asserting against
 * capture that was already wired, and would pass with the gate deleted. The
 * token and the environment therefore have to be what they are before the
 * provider's `boot()` runs, which is what `defineEnvironment()` is for, and a
 * Pest file cannot override that on a case bound to another directory.
 *
 * `$app['env']` is rebound as well as `app.env`: `Application::environment()`
 * answers from the container binding, which the bootstrappers resolved from
 * `APP_ENV=testing` (phpunit.xml) long before this method runs.
 */
abstract class LocalIngestTestCase extends NightwatchHostTestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('prism.token', null);
        $app['config']->set('app.env', 'local');
        $app->instance('env', 'local');
    }
}
