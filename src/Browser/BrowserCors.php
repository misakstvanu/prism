<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Browser;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Misakstvanu\Prism\Http\Controllers\BrowserPreflightController;
use Misakstvanu\Prism\Http\Controllers\BrowserReportController;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\IgnoreList;

/**
 * What `prism.browser.origins` means on the wire (US-010).
 *
 * The endpoint is registered inside the host's own application, so the ordinary
 * install — a Laravel app serving its own frontend — posts same-origin and CORS
 * never enters into it. This exists for a frontend deployed apart from the
 * backend it reports to (the page at `https://app.example.com`, the API at
 * `https://api.example.com`), where without a header from the second the browser
 * refuses to let the first talk to it at all. Listing the frontend's origin here
 * is the whole of what a host does about that: no CORS middleware, no package to
 * install, no `*` opened up across an application because one telemetry route
 * needed a header.
 *
 * Three decisions where the obvious implementation is wrong. **The listed origin
 * is echoed back, never `*`**: a response carrying
 * `Access-Control-Allow-Credentials: true` may not answer `*` — the browser
 * rejects the pair outright — and credentials are the point, the session cookie
 * being how {@see BrowserForwarder} learns who was signed in (US-008), so an
 * unlisted origin gets nothing at all rather than a header naming somebody
 * else's site. **`Vary: Origin` is written whenever ANY origin is configured**,
 * listed or not, since from that point the answer depends on the request's
 * `Origin` header and a cache that did not know could serve one origin's answer
 * to another; it is deliberately not one of the `Access-Control-*` headers, so
 * with `origins` empty nothing here writes anything, this included. **Matching
 * is `Str::is`'s**, as in every other pattern list in this config file, so
 * `https://*.example.com` covers a fleet of preview deployments and a bare `*`
 * means what a host writing it would expect — both sides normalised first, an
 * origin failing to match only on case or a trailing slash being a config typo
 * with no symptom but a browser refusing the call.
 *
 * CORS is *not* access control here: a page can already post cross-origin
 * without any of these headers (the request goes out, the browser only refuses
 * to show the page the answer) and this route answers 204 with an empty body to
 * everything, so nothing is kept from an unlisted origin. What the headers buy
 * is the `fetch` transport not treating a successful post as a network failure
 * and retrying it. They are read per request rather than resolved once at boot,
 * for the reason the rate limiter is
 * ({@see PrismServiceProvider::registerBrowserRateLimiter()}): in a long-lived
 * runtime a value captured at boot is whatever config said when the process
 * started.
 *
 * @see BrowserPreflightController for the `OPTIONS` half
 * @see BrowserReportController for the `POST` half
 */
final class BrowserCors
{
    /**
     * The verb the browser is told it may use, and the one header the SDK sets
     * on its post. `Content-Type` is why a cross-origin report is preflighted at
     * all: the `fetch` transport sends `application/json`, not one of the three
     * content types a request may carry without asking first. The beacon
     * transport sends `text/plain` and is never preflighted, which keeps the
     * last report of a session — sent while the page unloads, with no time for
     * two round trips — working either way.
     */
    public const ALLOW_METHODS = 'POST';

    public const ALLOW_HEADERS = 'Content-Type';

    /**
     * @param  list<string>  $origins  Already normalised, so a match is one comparison.
     */
    private function __construct(private readonly array $origins) {}

    public static function fromConfig(Repository $config): self
    {
        return new self(self::origins($config));
    }

    /**
     * Whether this install answers cross-origin reports at all. The provider
     * asks before registering the preflight route: with no origins configured
     * there is nothing for an `OPTIONS` to answer, and registering a route
     * anyway would change what a same-origin install replies to a verb it never
     * receives.
     */
    public static function configured(Repository $config): bool
    {
        return self::origins($config) !== [];
    }

    /**
     * The configured origins, normalised, with anything unusable dropped.
     * {@see IgnoreList::patterns()} makes a published config file of the wrong
     * shape harmless: a non-array value yields no origins, a non-string entry is
     * dropped rather than coerced into a pattern nobody wrote.
     *
     * @return list<string>
     */
    public static function origins(Repository $config): array
    {
        $origins = array_map(
            static fn (string $origin): string => self::normalise($origin),
            IgnoreList::patterns($config->get('prism.browser.origins', [])),
        );

        return array_values(array_filter($origins, static fn (string $origin): bool => $origin !== ''));
    }

    /**
     * The headers this request's answer carries — on the preflight and on the
     * post alike, so the two cannot disagree about who is allowed. Empty for an
     * install with no origins configured, the same-origin default, which needs
     * none.
     *
     * @return array<string, string>
     */
    public function headers(Request $request): array
    {
        if ($this->origins === []) {
            return [];
        }

        $vary = ['Vary' => 'Origin'];

        $origin = $request->headers->get('Origin');

        if ($origin === null || ! IgnoreList::matches($this->origins, self::normalise($origin))) {
            return $vary;
        }

        return [
            ...$vary,
            // Echoed as the browser sent it: the value is compared against the
            // page's own origin at the other end, so a normalised copy is a
            // different string to anyone whose origin was not already lower case.
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => self::ALLOW_METHODS,
            'Access-Control-Allow-Headers' => self::ALLOW_HEADERS,
            'Access-Control-Allow-Credentials' => 'true',
        ];
    }

    /**
     * The form both sides of the comparison are put in first: a browser sends an
     * origin lower case and without a trailing slash, a host copies one out of a
     * browser's address bar, where it has both. Neither difference can change
     * which origin is meant, and neither should be the reason telemetry stopped
     * arriving.
     */
    private static function normalise(string $origin): string
    {
        return rtrim(strtolower(trim($origin)), '/');
    }
}
