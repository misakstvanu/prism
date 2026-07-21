<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Capture;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Container\Container;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\Concerns\BuildsSpanEvents;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Captures outgoing calls made through Laravel's HTTP client as `span`
 * telemetry events (US-046).
 *
 * This is a Guzzle handler-stack middleware — {@see PrismServiceProvider::
 * registerHttpCapture()} registers it with {@see \Illuminate\Support\Facades\
 * Http::globalMiddleware()} so it wraps every request the host makes through the
 * HTTP client. Wrapping the send (rather than listening to the request/response
 * events) is what lets it time the call precisely and see the request and its
 * response together.
 *
 * The recorded span carries the method, host, path, status and measured
 * duration, and its `type` is `http` so it renders as an HTTP span on the trace
 * waterfall (US-050/US-063). The captured URL's query string is run through the
 * {@see Scrubber} so a credential passed as a query parameter (`?api_key=…`) is
 * redacted at the source and never leaves the process.
 *
 * Two things are never captured: the package's own outbound work — an ingest
 * POST carries {@see Recursion::MARKER_HEADER} and a flush runs under
 * {@see Recursion::suppressed()} (US-039) — so shipping a batch is never itself
 * recorded as an outgoing request. Recording is fully guarded, so a fault in the
 * capture can never corrupt the host's HTTP response.
 */
final class HttpCapture
{
    use BuildsSpanEvents;

    /** The span sub-type (US-006 `spans.type`); the waterfall tints it as an HTTP span. */
    private const SPAN_TYPE = 'http';

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Scrubber $scrubber,
        private readonly Container $container,
    ) {}

    /**
     * The Guzzle middleware: given the next handler, return a handler that times
     * the request and records a span once it resolves. Prism's own requests pass
     * straight through untimed.
     *
     * @param  callable(RequestInterface, array<string, mixed>): PromiseInterface  $handler
     * @return callable(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            if (Recursion::suppressed() || Recursion::isInternalRequest($request->getHeaders())) {
                return $handler($request, $options);
            }

            $start = microtime(true);
            $offsetMs = TraceContext::elapsedMs();

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($request, $start, $offsetMs): ResponseInterface {
                    $this->record($request, $response->getStatusCode(), $start, $offsetMs);

                    return $response;
                },
                function (mixed $reason) use ($request, $start, $offsetMs): PromiseInterface {
                    // A connection failure or timeout has no status; record the
                    // attempt as a 0-status span, then re-reject so the host sees
                    // the original failure unchanged.
                    $this->record($request, 0, $start, $offsetMs);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * Buffer a span for one completed outgoing call. Wrapped so a capture fault
     * can never propagate into the promise chain and turn a fulfilled response
     * into a rejection — telemetry must never break the host's HTTP call.
     */
    private function record(RequestInterface $request, int $status, float $start, float $offsetMs): void
    {
        try {
            $durationMs = (microtime(true) - $start) * 1000;
            $uri = $request->getUri();
            $method = $request->getMethod();
            $host = $uri->getHost();
            $path = $uri->getPath() === '' ? '/' : $uri->getPath();

            $this->buffer->add(self::SPAN_EVENT_TYPE, $this->spanEvent(
                name: trim($method.' '.$host.$path),
                type: self::SPAN_TYPE,
                offsetMs: $offsetMs,
                durationMs: $durationMs,
                userId: $this->resolveUserId($this->container),
                extra: [
                    'method' => $method,
                    'host' => $host,
                    'path' => $path,
                    'url' => $this->scrubbedUrl($uri),
                    'status' => $status,
                ],
            ));
        } catch (Throwable) {
            // Swallow — a telemetry failure must never surface to the host.
        }
    }

    /**
     * The call's host, path and query as one string, with any credential-shaped
     * query parameter redacted by the {@see Scrubber}. The scheme and any
     * userinfo are dropped: a host+path+query is enough to identify the call and
     * keeps embedded credentials out of the payload entirely.
     */
    private function scrubbedUrl(UriInterface $uri): string
    {
        $base = $uri->getHost().($uri->getPath() === '' ? '/' : $uri->getPath());
        $query = $uri->getQuery();

        if ($query === '') {
            return $base;
        }

        parse_str($query, $params);

        /** @var array<string, mixed> $scrubbed */
        $scrubbed = $this->scrubber->scrub($params);
        $rebuilt = http_build_query($scrubbed);

        return $rebuilt === '' ? $base : $base.'?'.$rebuilt;
    }
}
