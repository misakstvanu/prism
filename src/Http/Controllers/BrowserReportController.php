<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Misakstvanu\Prism\Browser\BrowserCors;
use Misakstvanu\Prism\Browser\BrowserForwarder;
use Misakstvanu\Prism\Browser\BrowserReport;
use Misakstvanu\Prism\PrismServiceProvider;

/**
 * The endpoint the browser SDK posts its reports to (US-005, US-006, US-007).
 *
 * `@misakstvanu/prism-browser` never talks to a Prism workspace directly: a
 * browser cannot be given an ingest token (anything the page can read, a reader
 * can read), and a workspace endpoint reached straight from the page would be
 * an unauthenticated write from an origin nobody controls. So the SDK posts to
 * the application it is part of, and the application — which already knows who
 * is signed in, which environment it is and what its scrub list says — forwards
 * the report through the pipeline every other signal already travels.
 *
 * This route exists the moment the package is installed, which is the point of
 * registering it from the provider rather than asking a host to add it: the
 * frontend half needs no backend code of anyone's. Two consequences follow, and
 * both are deliberate:
 *
 *   - **A configured install never answers the SDK a 404.** The route is
 *     registered before the token gate in {@see PrismServiceProvider::boot()},
 *     so an application with `PRISM_ENABLED=true` and no `PRISM_TOKEN` still
 *     accepts the post and answers 204. A 404 would be indistinguishable, from
 *     the page's side, from a routing mistake — and the SDK would keep the
 *     report and retry it.
 *   - **No answer carries a body.** The SDK uses `navigator.sendBeacon` where it
 *     can — the only transport that survives a page being closed, which is when
 *     the last report of a session is sent — and a beacon cannot read a response
 *     at all. The status is therefore the whole of what is said back, and a
 *     sentence explaining a refusal would be a sentence written for nobody.
 *
 * Every one of its answers carries the CORS headers {@see BrowserCors}
 * resolves for the request, which for the ordinary same-origin install is none
 * at all. They are on the refusals as well as on the 204 because a `fetch` that
 * cannot read a 413 reports it as a network failure, and the SDK's answer to a
 * network failure is to hold the report and send it again (US-010).
 *
 * The refusals are ordered by what they cost to detect, which is also the order
 * they are worth making in: an oversized post is refused on its declared length
 * before its body is read, an undecodable one before it is understood, and a
 * decodable one that is not a report before any of its events are looked at.
 * What is deliberately *not* a refusal is a bad event inside a good report —
 * {@see BrowserReport::filter()} drops and counts those, because a page in a bad
 * state is exactly the page worth hearing from and one malformed entry must not
 * cost the nineteen good ones beside it.
 *
 * An accepted report is handed to {@see BrowserForwarder}, which turns it into
 * ordinary Prism events and ships them through the pipeline every other signal
 * travels (US-007). That call is deliberately **not** wrapped in a `try`: the
 * forwarding is the only thing this request is for, so a failure in it is worth
 * a 500 and a reported exception rather than a 204 that says the report was
 * taken. See {@see BrowserForwarder} for why that exception is still captured
 * even though this execution is one Prism asked not to be sampled.
 */
final class BrowserReportController
{
    public function __construct(
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    public function __invoke(Request $request): Response
    {
        // Resolved before the first refusal, so a cross-origin caller can read
        // every answer this method gives rather than only the one it hoped for.
        // For the same-origin install this is an empty array and no header of
        // any kind is written (US-010).
        $cors = BrowserCors::fromConfig($this->config)->headers($request);

        $maxBytes = max(0, (int) $this->config->get('prism.browser.max_bytes', 262144));
        $maxEvents = max(0, (int) $this->config->get('prism.browser.max_events', 50));

        // Before the body is read at all: a page that says how much it is about
        // to send is taken at its word, so an oversized post costs the host a
        // header rather than a quarter of a megabyte of memory.
        if ($maxBytes > 0 && $this->declaredLength($request) > $maxBytes) {
            return $this->answer(413, $cors);
        }

        $body = (string) $request->getContent();

        // And after: the declared length is the page's claim, this is the fact.
        if ($maxBytes > 0 && strlen($body) > $maxBytes) {
            return $this->answer(413, $cors);
        }

        if (! BrowserReport::isDecodable($body)) {
            return $this->answer(400, $cors);
        }

        $report = BrowserReport::tryParse($body);

        if ($report === null) {
            return $this->answer(422, $cors);
        }

        // Counted on the events as posted, before any per-event work: the cap
        // exists to bound what one post can cost, so paying to validate 10,000
        // events in order to find out there were too many of them would be the
        // cost the cap is there to avoid.
        if ($maxEvents > 0 && $report->eventCount() > $maxEvents) {
            return $this->answer(413, $cors);
        }

        $this->forward($report->filter(), $request);

        return $this->answer(204, $cors);
    }

    /**
     * Ship what survived the filter, if there is a pipeline to ship it through.
     *
     * There is one exactly when {@see PrismServiceProvider::registerCapture()}
     * ran, i.e. when the install has a token. The route is registered one gate
     * earlier than that on purpose, so this is the ordinary state of an
     * enabled-but-uncredentialled install rather than an error: the report is
     * read, answered and dropped, and nothing is buffered.
     *
     * Asked of the container rather than injected, because a constructor
     * dependency would make the controller unbuildable in exactly that state —
     * and the endpoint's whole premise is that it answers.
     */
    private function forward(BrowserReport $report, Request $request): void
    {
        if (! $this->container->bound(BrowserForwarder::class)) {
            return;
        }

        $this->container->make(BrowserForwarder::class)->forward($report, $request);
    }

    /**
     * What the request says its body weighs, or 0 when it does not say.
     *
     * A `Content-Length` that is absent or not a number is not a refusal: a
     * chunked upload declares no length, and the body is measured below anyway.
     */
    private function declaredLength(Request $request): int
    {
        $declared = $request->headers->get('Content-Length');

        return is_numeric($declared) ? (int) $declared : 0;
    }

    /**
     * @param  array<string, string>  $headers  The CORS answer, empty for a same-origin install.
     */
    private function answer(int $status, array $headers = []): Response
    {
        return new Response('', $status, $headers);
    }
}
