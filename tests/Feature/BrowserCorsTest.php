<?php

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Testing\TestResponse;
use Misakstvanu\Prism\Http\Controllers\BrowserPreflightController;
use Misakstvanu\Prism\PrismServiceProvider;

/**
 * CORS for a split-origin frontend (US-010).
 *
 * The endpoint lives inside the host's own application, so the ordinary install
 * never meets any of this: the SDK's post is same-origin and a browser asks
 * nobody's permission. What `prism.browser.origins` buys is the other shape — a
 * frontend at `https://app.example.com` reporting to an API at
 * `https://api.example.com` — where without a header from the second saying so
 * the first is not allowed to talk to it at all.
 *
 * Every claim here is about a header, and headers are the one part of an HTTP
 * answer nothing else in this suite would notice going missing: the route
 * answers 204 with an empty body either way, so a broken CORS answer is a 204
 * the page is not permitted to read. That is also why the four cases are
 * asserted from both ends — a listed origin gets the headers, an unlisted one
 * gets none, and an install with no origins configured gets none on either verb.
 */
beforeEach(function () {
    // The named limiter counts in the cache. A per-test array store is what
    // stops one test's hits deciding another test's verdict.
    config(['cache.default' => 'array']);

    // The `web` group encrypts cookies, so the POST route needs a key. The
    // Testbench skeleton ships none.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('prism-key-32-byt', 2))]);

    // The HTTP kernel is what syncs the framework's middleware GROUPS onto the
    // router, and it is built lazily — without it `web` is a bare string and
    // every middleware claim below would be about nothing.
    app(HttpKernelContract::class);
});

it('answers a preflight from a listed origin with the whole allow set', function () {
    bootBrowserEndpoint(['prism.browser.origins' => ['https://app.example.com']]);

    $response = preflightBrowserReport('https://app.example.com');

    $response->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com')
        ->assertHeader('Access-Control-Allow-Methods', 'POST')
        ->assertHeader('Access-Control-Allow-Headers', 'Content-Type')
        // Never `*`: a browser refuses the pair of a wildcard origin and
        // credentials, and credentials are the point — the session cookie is
        // how the report learns who was signed in.
        ->assertHeader('Access-Control-Allow-Credentials', 'true')
        ->assertHeader('Vary', 'Origin');
});

it('answers a preflight from an unlisted origin with no allow headers at all', function () {
    bootBrowserEndpoint(['prism.browser.origins' => ['https://app.example.com']]);

    $response = preflightBrowserReport('https://attacker.example.net');

    // Answered, not refused: the status says nothing, and the absence of the
    // headers is the whole of the refusal a browser acts on.
    $response->assertNoContent();

    expect(corsHeaders($response))->toBe([]);
});

it('answers a preflight that names no origin with no allow headers either', function () {
    bootBrowserEndpoint(['prism.browser.origins' => ['https://app.example.com']]);

    expect(corsHeaders(preflightBrowserReport()))->toBe([]);
});

it('varies on Origin for every answer once any origin is configured', function () {
    // Written for the unlisted origin too, because from the moment an origin is
    // listed the answer depends on the request's Origin header — and a cache
    // that did not know so could hand one origin's answer to another. It is
    // deliberately not one of the Access-Control-* headers the assertions above
    // require to be absent.
    bootBrowserEndpoint(['prism.browser.origins' => ['https://app.example.com']]);

    preflightBrowserReport('https://attacker.example.net')->assertHeader('Vary', 'Origin');
    postBrowserReport(server: originHeader('https://attacker.example.net'))->assertHeader('Vary', 'Origin');
});

it('matches a listed origin whose case or trailing slash differs', function () {
    // A host copies an origin out of a browser's address bar, where it has a
    // trailing slash; a browser sends one lower case and without. Neither
    // difference can change which origin is meant, and neither is a difference
    // anybody would want to be the reason telemetry stopped arriving.
    bootBrowserEndpoint(['prism.browser.origins' => ['https://App.Example.com/']]);

    preflightBrowserReport('https://app.example.com')
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
});

it('matches a wildcard origin pattern the way every other list in the config does', function () {
    bootBrowserEndpoint(['prism.browser.origins' => ['https://*.example.com']]);

    preflightBrowserReport('https://preview-42.example.com')
        ->assertHeader('Access-Control-Allow-Origin', 'https://preview-42.example.com');

    expect(corsHeaders(preflightBrowserReport('https://example.net')))->toBe([]);
});

it('echoes the caller back rather than a wildcard when the list is a bare star', function () {
    // `Access-Control-Allow-Origin: *` and `Access-Control-Allow-Credentials:
    // true` are a pair a browser rejects outright, so the only spec-valid way to
    // say "any origin" is to answer each one with itself.
    bootBrowserEndpoint(['prism.browser.origins' => ['*']]);

    preflightBrowserReport('https://anything.example.net')
        ->assertHeader('Access-Control-Allow-Origin', 'https://anything.example.net')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('carries the allow headers on the post itself, not only on the preflight', function () {
    bootBrowserEndpoint(['prism.browser.origins' => ['https://app.example.com']]);

    postBrowserReport(server: originHeader('https://app.example.com'))
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com')
        ->assertHeader('Access-Control-Allow-Methods', 'POST')
        ->assertHeader('Access-Control-Allow-Headers', 'Content-Type')
        ->assertHeader('Access-Control-Allow-Credentials', 'true')
        ->assertHeader('Vary', 'Origin');
});

it('carries the allow headers on a refusal too', function () {
    // A `fetch` that is not permitted to read a 413 reports it as a network
    // failure, and the SDK's answer to a network failure is to hold the report
    // and send it again — so a refusal the caller cannot read is a refusal that
    // comes back.
    bootBrowserEndpoint(['prism.browser.origins' => ['https://app.example.com']]);

    postBrowserReport('not json at all', server: originHeader('https://app.example.com'))
        ->assertStatus(400)
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
});

it('writes no header of any kind for an unlisted origin posting', function () {
    bootBrowserEndpoint(['prism.browser.origins' => ['https://app.example.com']]);

    $response = postBrowserReport(server: originHeader('https://attacker.example.net'));

    $response->assertNoContent();

    expect(corsHeaders($response))->toBe([]);
});

it('reads the origins per request rather than pinning them at boot', function () {
    // The rate limiter's rule, one route over: in a long-lived runtime a value
    // resolved once at boot is whatever config said when the process started.
    bootBrowserEndpoint(['prism.browser.origins' => ['https://app.example.com']]);

    config(['prism.browser.origins' => ['https://elsewhere.example.com']]);

    expect(corsHeaders(preflightBrowserReport('https://app.example.com')))->toBe([]);

    preflightBrowserReport('https://elsewhere.example.com')
        ->assertHeader('Access-Control-Allow-Origin', 'https://elsewhere.example.com');
});

it('registers no preflight route and writes no header when no origin is listed', function () {
    // The same-origin install, which is every host that serves its own
    // frontend: it needs none of this and gets none of it.
    bootBrowserEndpoint(['prism.browser.origins' => []]);

    expect(browserRoute(PrismServiceProvider::BROWSER_PREFLIGHT_ROUTE) === null)
        ->toBeTrue('a same-origin install registered a preflight route');

    $post = postBrowserReport(server: originHeader('https://app.example.com'));

    expect(corsHeaders($post))->toBe([])
        ->and(corsHeaders(preflightBrowserReport('https://app.example.com')))->toBe([])
        // Not even the `Vary`, which every other answer on this route carries:
        // an install with no origins listed does not vary by Origin, because
        // there is no origin it would answer differently.
        ->and($post->headers->has('Vary'))->toBeFalse();
});

it('registers the preflight beside the post wherever the path is', function () {
    bootBrowserEndpoint([
        'prism.browser.path' => 'telemetry/from-the-browser',
        'prism.browser.origins' => ['https://app.example.com'],
    ]);

    $preflight = browserRoute(PrismServiceProvider::BROWSER_PREFLIGHT_ROUTE);

    expect($preflight !== null)->toBeTrue('no preflight route was registered')
        ->and($preflight?->uri())->toBe('telemetry/from-the-browser')
        ->and($preflight?->methods())->toContain('OPTIONS')
        // A class rather than a route closure: a closure in the route table
        // makes `php artisan route:cache` fail for the whole application.
        ->and($preflight?->getActionName())->toBe(BrowserPreflightController::class);
});

it('registers no preflight when the browser endpoint is switched off entirely', function () {
    bootBrowserEndpoint([
        'prism.browser.enabled' => false,
        'prism.browser.origins' => ['https://app.example.com'],
    ]);

    expect(browserRoute(PrismServiceProvider::BROWSER_PREFLIGHT_ROUTE) === null)
        ->toBeTrue('a disabled browser block still registered a preflight route');
});

it('runs the preflight outside the web group and outside the throttle', function () {
    bootBrowserEndpoint([
        'prism.browser.origins' => ['https://app.example.com'],
        'prism.browser.rate_limit' => 1,
    ]);

    // A preflight carries no cookies, so there is no session to start...
    expect(browserMiddleware(browserRoute(PrismServiceProvider::BROWSER_PREFLIGHT_ROUTE)))
        ->not->toContain(StartSession::class)
        // ...and the post it asks about really is inside both, so the absence
        // above is this route's stack rather than a router with no groups.
        ->and(browserMiddleware(browserRoute()))->toContain(StartSession::class);

    // ...and a preflight that met a 429 would take the post it was asking about
    // with it, which is the one way a limit meant to bound abuse could stop
    // telemetry outright. The limit is 1 and already spent.
    postBrowserReport(server: originHeader('https://app.example.com'))->assertNoContent();
    postBrowserReport(server: originHeader('https://app.example.com'))->assertStatus(429);

    foreach (range(1, 4) as $ignored) {
        preflightBrowserReport('https://app.example.com')->assertNoContent();
    }
});

/**
 * A preflight, the way a browser sends one: the verb, the origin and nothing
 * else — no body, no cookies, no content type.
 */
function preflightBrowserReport(?string $origin = null, string $uri = '/_prism/browser'): TestResponse
{
    return test()->call('OPTIONS', $uri, server: $origin === null ? [] : originHeader($origin));
}

/**
 * @return array<string, string>
 */
function originHeader(string $origin): array
{
    return ['HTTP_ORIGIN' => $origin];
}

/**
 * The `Access-Control-*` headers a response carries, which is the whole of what
 * a browser reads off this endpoint.
 *
 * Asserted as an exact array rather than header by header, because the claim
 * that matters for an unlisted origin is that there is no such header at all —
 * and a per-header absence check passes just as well against a typo in the one
 * header the test forgot to name.
 *
 * @return array<string, list<string|null>>
 */
function corsHeaders(TestResponse $response): array
{
    return array_filter(
        $response->headers->all(),
        static fn (string $name): bool => str_starts_with($name, 'access-control-'),
        ARRAY_FILTER_USE_KEY,
    );
}
