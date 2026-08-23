<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Http\Middleware;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Misakstvanu\Prism\Nightwatch\RejectRules;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `prism.ignore.paths` applied to the capture engine (US-010).
 *
 * The engine has a reject callback for every record type it buffers *inside* an
 * execution, and none for the execution itself — a request is not a record in a
 * batch, it is the thing the batch belongs to. `Core::dontSample()` is the
 * equivalent, and it is the bigger hammer: at `finishExecution()` an unsampled
 * execution has its whole buffer discarded, so an ignored path costs nothing at
 * all rather than merely losing its request row. That is what an ignore list is
 * asking for — a health check polled every second is not more interesting for
 * the four cache reads it made.
 *
 * **Two requests, two reasons, and only one of them is configurable.** A path on
 * the list is noise the host chose to silence. A request carrying
 * {@see Recursion::MARKER_HEADER} is something else entirely: another Prism
 * client's batch arriving here, which is Prism's own traffic end to end, on a
 * path the receiving application did not choose and cannot be expected to name.
 * That one is also bracketed in {@see Recursion::suppress()} for the whole
 * lifetime of the request, which is what keeps the package's own listeners quiet
 * over a boundary the in-process flag cannot otherwise see (a fresh request, in
 * a fresh process).
 *
 * **ORDER IS THE TRAP.** Nightwatch *prepends* its own global middleware, whose
 * `handle()` calls `configureRequestSampling()` — that is to say, it calls
 * `sample()` and overwrites whatever the sampling flag was. So this middleware
 * is deliberately **pushed onto the end** of the global stack rather than
 * prepended: a `dontSample()` made before the engine's own middleware ran would
 * be silently undone, and the only symptom would be telemetry the host thought
 * it had switched off. Everything global
 * still runs inside it, and route middleware and the controller with them.
 *
 * One residue worth knowing: an *unhandled* exception makes upstream re-sample
 * (`Core::report()` re-rolls against `sampling.exceptions` when the execution is
 * not being kept), so a request ignored here that then throws is shipped after
 * all. That is upstream deciding an errored execution is always worth seeing,
 * and US-019's tail sampling is where that decision is revisited.
 */
final class RejectIgnoredRequests
{
    public function __construct(
        private readonly Container $container,
        private readonly RejectRules $rules,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Recursion::isInternalRequest($request->headers)) {
            $this->dontSample();

            return Recursion::suppress(static fn (): Response => $next($request));
        }

        if ($this->rules->rejectsRequest($request)) {
            $this->dontSample();
        }

        return $next($request);
    }

    /**
     * Tell the capture engine to discard this execution.
     *
     * Guarded and swallowed for the reason every other reach into
     * `laravel/nightwatch` is ({@see PrismServiceProvider::installPrismIngest()}):
     * an upstream shape change is a dependency problem, not something a host's
     * request should be taken down by. A `Core` that is not bound means the
     * package is installed without the engine it requires — odd, but not fatal.
     */
    private function dontSample(): void
    {
        if (! $this->container->bound(Core::class)) {
            return;
        }

        try {
            /** @var Core<RequestState|CommandState> $core */
            $core = $this->container->make(Core::class);

            $core->dontSample();
        } catch (Throwable) {
            //
        }
    }
}
