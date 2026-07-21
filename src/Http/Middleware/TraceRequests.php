<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\TraceContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts a trace at the very front of the request (US-041).
 *
 * Prepended to the global middleware stack by
 * {@see PrismServiceProvider::registerCapture()} so it runs
 * before anything else, ensuring every event the request produces (US-042+)
 * shares one trace id. An incoming `X-Prism-Trace-Id` header continues a trace
 * begun by an upstream service, so a single trace can span services (AC2);
 * absent that header a fresh trace is started.
 */
final class TraceRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        TraceContext::start($request->header(TraceContext::HEADER));

        return $next($request);
    }
}
