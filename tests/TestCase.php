<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Tests;

use Illuminate\Foundation\Application;
use Misakstvanu\Prism\PrismServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for the package's own suite. Testbench boots a throwaway
 * Laravel application with only the package's provider registered, so the
 * suite runs with no host app present — proving the package stands alone.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
        ];
    }
}
