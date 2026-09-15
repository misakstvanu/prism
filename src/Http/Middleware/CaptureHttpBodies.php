<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Misakstvanu\Prism\Http\BodyRecorder;
use Misakstvanu\Prism\Support\Recursion;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fills {@see BodyRecorder} with what this exchange carried.
 *
 * **Why a middleware rather than a sensor.** The capture engine reports a
 * request from `terminate()`, by which point the response object is out of reach
 * of anything but the kernel, and its record shape has no response body to fill
 * anyway. A middleware is the one place holding the request and the response at
 * once, and holds them after, because the record they belong to is written later.
 *
 * **It records on the way OUT, which covers the failure path too.** By the time
 * `$next` returns, an exception raised anywhere further in has already been
 * rendered into a response by `Illuminate\Routing\Pipeline`, so a 500's body —
 * the thing most worth reading — is an ordinary response here, not a special case.
 *
 * **PUSHED, not prepended**, like {@see RejectIgnoredRequests} and for a related
 * reason: everything the host does to the response in a global middleware
 * (compression, a wrapper, a header) should already have happened when this
 * reads it, so what is recorded is what the client received rather than what the
 * controller returned. Being last in also means an ignored path's `dontSample()`
 * has already been made, which does not stop the read: it costs a string copy
 * and the whole execution is discarded moments later.
 *
 * **The reset is at the front, not the back.** A long-lived runtime keeps the
 * recorder between requests, and a request that dies before returning would
 * otherwise leave its body to be reported as the *next* request's. Clearing on
 * the way in means the worst case is a request with no body recorded, never a
 * request with someone else's.
 *
 * Prism's own traffic is refused outright: a batch from another install carries
 * {@see Recursion::MARKER_HEADER}, and recording its body would put an entire
 * telemetry batch — every event in it — inside a column of one request row.
 */
final class CaptureHttpBodies
{
    public function __construct(private readonly BodyRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->recorder->reset();

        $response = $next($request);

        if (! Recursion::isInternalRequest($request->headers) && ! Recursion::suppressed()) {
            $this->recorder->record($request, $response);
        }

        return $response;
    }
}
