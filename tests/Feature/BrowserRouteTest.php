<?php

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Http\Controllers\BrowserReportController;
use Misakstvanu\Prism\Nightwatch\RejectRules;
use Misakstvanu\Prism\PrismServiceProvider;

/**
 * The endpoint the browser SDK posts to (US-005).
 *
 * Everything here is a claim about a route a *page* will call, so the two
 * properties worth pinning are the ones a page cannot report on: that the route
 * exists at all (a 404 reads to the SDK as a routing mistake and it retries),
 * and what middleware it runs inside (CSRF verification the beacon cannot
 * satisfy, an auth gate an anonymous visitor cannot pass).
 *
 * `Illuminate\Foundation\Http\Middleware\PreventRequestForgery` is named here
 * rather than the `ValidateCsrfToken` the story's wording uses, because that is
 * the class the framework's own `web` group actually holds — the other two names
 * are deprecated subclasses of it, and the router's exclusion check walks up the
 * hierarchy rather than down. The control assertion below is what keeps that
 * claim honest: it reads the class out of a plain `web` route rather than
 * asserting an absence against a group that might not carry one.
 */
beforeEach(function () {
    // The named limiter counts in the cache. A per-test array store is what
    // stops one test's hits deciding another test's verdict.
    config(['cache.default' => 'array']);

    // The `web` group encrypts cookies, so a route inside it needs a key. The
    // Testbench skeleton ships none.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('prism-key-32-byt', 2))]);

    // The HTTP kernel is what syncs the framework's middleware GROUPS onto the
    // router, and it is built lazily — the first `$this->post()` of a test
    // builds it. Resolving it up front is what makes `web` mean the real group
    // in a test that never sends a request; without it the router has no groups
    // at all and every claim below would be about the bare string "web".
    app(HttpKernelContract::class);
});

it('registers the reporting endpoint when the client and the browser block are both enabled', function () {
    bootBrowserEndpoint(['prism.enabled' => true, 'prism.browser.enabled' => true]);

    $route = browserRoute();

    expect($route !== null)->toBeTrue('the browser reporting route was not registered')
        ->and($route?->uri())->toBe('_prism/browser')
        ->and($route?->methods())->toContain('POST')
        ->and($route?->getActionName())->toBe(BrowserReportController::class);
});

it('registers no endpoint when the client is switched off', function () {
    bootBrowserEndpoint(['prism.enabled' => false, 'prism.browser.enabled' => true]);

    expect(browserRoute() === null)->toBeTrue('a disabled client still registered the browser route');
});

it('registers no endpoint when the browser block is switched off', function () {
    bootBrowserEndpoint(['prism.enabled' => true, 'prism.browser.enabled' => false]);

    expect(browserRoute() === null)->toBeTrue('a disabled browser block still registered the route');
});

it('registers the endpoint wherever prism.browser.path names', function () {
    bootBrowserEndpoint(['prism.browser.path' => '/telemetry/from-the-browser/']);

    // Trimmed on both sides: a host writing a leading slash is writing the URL
    // it sees in a browser, and Laravel's route table has no leading slash.
    expect(browserRoute()?->uri())->toBe('telemetry/from-the-browser');
});

it('answers 204 and discards the body when no token is configured', function () {
    // The install this endpoint must not 404: enabled, wired, and missing the
    // one credential the pipeline behind it needs. A 404 is indistinguishable
    // from a routing mistake at the page's end, so the SDK would hold the
    // report and keep retrying an address that will never work.
    expect(config('prism.token'))->toBeNull()
        ->and(app()->bound(PrismServiceProvider::ACTIVE))->toBeFalse();

    // assertNoContent() is both halves: the 204 and an empty body.
    postBrowserReport()->assertNoContent();
});

it('answers 204 for a fully configured install too', function () {
    config(['prism.token' => 'prism_live_'.str_repeat('a', 40)]);
    bootBrowserEndpoint();

    postBrowserReport()->assertNoContent();
});

it('runs inside the web group but without CSRF verification', function () {
    bootBrowserEndpoint();

    Route::post('_control', fn () => 'ok')->middleware('web')->name('control');

    app('router')->getRoutes()->refreshNameLookups();

    $control = app('router')->getRoutes()->getByName('control');

    expect(browserMiddleware(browserRoute()))
        // The session is how US-008 learns who is signed in, so `web` is not
        // decoration — dropping it would leave every report anonymous.
        ->toContain(StartSession::class)
        ->not->toContain(PreventRequestForgery::class)
        // Non-vacuous: the group really does carry CSRF here, so the absence
        // above is the exclusion working rather than a group that never had one.
        ->and(browserMiddleware($control))->toContain(PreventRequestForgery::class);
});

it('carries no auth middleware, so an anonymous visitor can report', function () {
    bootBrowserEndpoint();

    expect(browserMiddleware(browserRoute()))->not->toContain(Authenticate::class);
});

it('throttles a client past the configured posts per minute', function () {
    config(['prism.browser.rate_limit' => 2]);

    postBrowserReport()->assertNoContent();
    postBrowserReport()->assertNoContent();
    postBrowserReport()->assertStatus(429);
});

it('never throttles when the rate limit is zero', function () {
    config(['prism.browser.rate_limit' => 0]);

    // Asserted twice over: the limiter itself resolves to Unlimited, and more
    // posts than any non-zero setting in this file would allow all land.
    $limiter = RateLimiter::limiter(PrismServiceProvider::BROWSER_LIMITER);

    expect($limiter(request()))->toBeInstanceOf(Unlimited::class);

    foreach (range(1, 6) as $ignored) {
        postBrowserReport()->assertNoContent();
    }
});

it('reads the rate limit per request rather than pinning it at boot', function () {
    // A limiter registered at boot outlives every request in a long-lived
    // runtime, so a limit captured once would be whatever config said when the
    // process started.
    config(['prism.browser.rate_limit' => 1]);

    postBrowserReport()->assertNoContent();
    postBrowserReport()->assertStatus(429);

    config(['prism.browser.rate_limit' => 0]);

    postBrowserReport()->assertNoContent();
});

it('ships _prism/* on the default ignore list', function () {
    expect(config('prism.ignore.paths'))->toContain('_prism/*');
});

it('excludes the reporting endpoint from capture wherever it is renamed to', function () {
    // The shipped list covers the default path; this covers a host that moved
    // it. A report is a request the page made *because* Prism is installed, so
    // capturing it is telemetry about telemetry — and nothing in a renamed
    // path is something a host should have to remember to silence.
    config([
        'prism.ignore.paths' => [],
        'prism.browser.path' => 'telemetry/from-the-browser',
    ]);

    $rules = RejectRules::fromConfig(app('config'));

    expect($rules->rejectsRequest(Request::create('/telemetry/from-the-browser', 'POST')))->toBeTrue()
        ->and($rules->rejectsRequest(Request::create('/orders', 'POST')))->toBeFalse();
});

it('leaves the path alone when the browser endpoint is switched off', function () {
    // With no route registered there is nothing of Prism's on that path, and
    // silencing whatever the host is serving there instead is not Prism's to do.
    config([
        'prism.ignore.paths' => [],
        'prism.browser.enabled' => false,
        'prism.browser.path' => 'telemetry/from-the-browser',
    ]);

    expect(RejectRules::fromConfig(app('config'))->rejectsRequest(
        Request::create('/telemetry/from-the-browser', 'POST'),
    ))->toBeFalse();
});
