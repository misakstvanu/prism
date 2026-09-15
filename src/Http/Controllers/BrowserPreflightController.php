<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Misakstvanu\Prism\Browser\BrowserCors;
use Misakstvanu\Prism\PrismServiceProvider;

/**
 * The `OPTIONS` half of the browser endpoint, registered only for an install
 * that lists `prism.browser.origins` (US-010).
 *
 * A cross-origin report is preflighted because it is posted as
 * `application/json` — the browser asks before it sends anything — so a
 * split-origin frontend whose preflight goes unanswered never makes the post at
 * all. What it answers is {@see BrowserCors}'s to decide; this class is the two
 * decisions about *how*:
 *
 *   - **Outside the `web` group and outside the throttle.** A preflight carries
 *     no cookies and no body — no session to start, nothing to read — and a
 *     preflight that met a 429 would take the post it was asking about with it,
 *     the one way a rate limit meant to bound abuse could stop telemetry
 *     outright. The post itself is still both, see
 *     {@see PrismServiceProvider::registerBrowserEndpoint()}; the path is on
 *     `prism.ignore.paths` either way, so neither verb is captured.
 *   - **A class rather than a route closure.** A closure in the route table
 *     makes `php artisan route:cache` fail for the whole application, and a
 *     package that breaks a host's deploy step to save a file is a package
 *     nobody keeps.
 *
 * Like every other answer from this endpoint it carries no body: the browser
 * reads the headers and the status and nothing else, and the SDK never sees a
 * preflight at all.
 */
final class BrowserPreflightController
{
    public function __construct(private readonly Repository $config) {}

    public function __invoke(Request $request): Response
    {
        return new Response('', 204, BrowserCors::fromConfig($this->config)->headers($request));
    }
}
