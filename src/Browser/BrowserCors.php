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
 * The endpoint is registered inside the host's own application, so for the
 * ordinary install — a Laravel app serving its own frontend — the SDK's post is
 * same-origin and CORS never enters into it. A frontend deployed apart from the
 * backend it reports to is the case this exists for: the page is at
 * `https://app.example.com`, the API at `https://api.example.com`, and without a
 * header from the second saying so the browser refuses to let the first talk to
 * it at all. Listing the frontend's origin here is meant to be the whole of what
 * a host does about that — no CORS middleware to add, no package to install, and
 * in particular no `*` opened up across an application because one telemetry
 * route needed a header.
 *
 * Three decisions are worth stating, because each one is a place where the
 * obvious implementation is wrong:
 *
 *   - **The listed origin is echoed back, never `*`.** A response that carries
 *     `Access-Control-Allow-Credentials: true` may not answer `*` — the browser
 *     rejects the pair outright — and credentials are the point here, since the
 *     session cookie is how {@see BrowserForwarder} learns who was signed in
 *     (US-008). So a listed origin is answered with itself, and an unlisted one
 *     is answered with nothing at all rather than with a header naming somebody
 *     else's site.
 *   - **`Vary: Origin` is written whenever ANY origin is configured**, listed or
 *     not, because from that point on the answer depends on the request's
 *     `Origin` header and a cache that did not know so could serve one origin's
 *     answer to another. It is deliberately not one of the `Access-Control-*`
 *     headers: with `origins` empty nothing here writes anything, and that
 *     includes this.
 *   - **Matching is `Str::is`'s**, the same as every other pattern list in
 *     this config file, so `https://*.example.com` covers a fleet of preview
 *     deployments and a bare `*` means what a host writing it would expect. An
 *     origin that only fails to match because of its case or a trailing slash
 *     is a config typo with no symptom but a browser refusing the call, so both
 *     sides are normalised before they are compared.
 *
 * A note on what CORS is *not* doing here. It is not access control: a page can
 * already post to this endpoint cross-origin without any of these headers — the
 * request goes out, the browser simply refuses to show the page the answer — and
 * this route answers 204 with an empty body to everything, so there is nothing
 * an unlisted origin is being kept from reading. What the headers buy is the
 * `fetch` transport not treating a perfectly successful post as a network
 * failure and retrying it.
 *
 * Read per request rather than resolved once at boot, for the reason the rate
 * limiter is ({@see PrismServiceProvider::registerBrowserRateLimiter()}): in a
 * long-lived runtime a value captured at boot is whatever config said when the
 * process started.
 *
 * @see BrowserPreflightController for the `OPTIONS` half
 * @see BrowserReportController for the `POST` half
 */
final class BrowserCors
{
    /**
     * The verb the browser is being told it may use, and the one header the SDK
     * sets on its post.
     *
     * `Content-Type` is why a cross-origin report is preflighted at all: the
     * `fetch` transport sends `application/json`, which is not one of the three
     * content types a request is allowed to carry without asking first. The
     * beacon transport sends `text/plain` and is never preflighted, which is
     * what keeps the last report of a session — sent while the page is being
     * unloaded, with no time for two round trips — working either way.
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
     * Whether this install answers cross-origin reports at all.
     *
     * The provider asks before registering the preflight route: with no origins
     * configured there is nothing for an `OPTIONS` to answer, and registering a
     * route to say so would change what a same-origin install replies to a verb
     * it never receives.
     */
    public static function configured(Repository $config): bool
    {
        return self::origins($config) !== [];
    }

    /**
     * The configured origins, normalised, with anything unusable dropped.
     *
     * {@see IgnoreList::patterns()} is what makes a published config file that
     * holds the wrong shape harmless — a non-array value yields no origins, and
     * a non-string entry is dropped rather than coerced into a pattern nobody
     * wrote.
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
     * post alike, so the two cannot disagree about who is allowed.
     *
     * Empty for an install with no origins configured, which is the same-origin
     * default and needs none.
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
            // page's own origin at the other end, so a normalised copy of it is
            // a different string to anyone whose origin was not already lower
            // case.
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => self::ALLOW_METHODS,
            'Access-Control-Allow-Headers' => self::ALLOW_HEADERS,
            'Access-Control-Allow-Credentials' => 'true',
        ];
    }

    /**
     * The form both sides of the comparison are put in first.
     *
     * A browser sends an origin lower case and without a trailing slash; a host
     * copies one out of a browser's address bar, where it has both. Neither
     * difference is one anybody would want to be the reason telemetry stopped
     * arriving, and neither can change which origin is meant.
     */
    private static function normalise(string $origin): string
    {
        return rtrim(strtolower(trim($origin)), '/');
    }
}
