<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Capture;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\IgnoreList;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\SpanStack;
use Misakstvanu\Prism\Support\TraceContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns every HTTP request into a buffered `request` telemetry event (US-043).
 *
 * {@see PrismServiceProvider::registerRequestCapture()} appends this middleware
 * to the host's `web` and `api` groups without the user editing
 * `bootstrap/app.php`, and binds it as a container singleton so the one instance
 * that runs {@see handle()} is the same one the DB-query listener increments and
 * {@see terminate()} reads back — the per-request query count lives on it.
 *
 * Capture happens in {@see terminate()}, after the response has been sent, so it
 * sees the final status, the peak memory the request reached and the total query
 * count, and adds nothing to the request's critical path.
 *
 * The recorded payload mirrors the server's `requests` columns (US-006): method,
 * path, matched route, status, duration, peak memory, query count, client IP and
 * user agent. Request timing runs from `LARAVEL_START` where the host defines it
 * (the constant `public/index.php` sets before the framework boots), so the
 * measured duration includes framework boot rather than only the time inside the
 * middleware stack. The authenticated user id and current trace id ride the event
 * envelope so the console can attribute and correlate the request.
 *
 * Three things are never captured: a path matching the configurable ignore list
 * (`prism.ignore.paths`, defaulting to Prism's own routes, dev tooling and health
 * checks — otherwise the client would report on itself), any request whose work
 * is the package's own ({@see Recursion::suppressed()}), and any request carrying
 * the {@see Recursion::MARKER_HEADER} marker — an inbound ingest batch from
 * another Prism client, which a workspace monitoring itself would otherwise
 * capture as application traffic and answer with a batch of its own.
 *
 * A non-GET request's body is captured too, but only after being run through the
 * {@see Scrubber} so a secret is redacted at the source, then truncated to
 * `prism.request.max_body` bytes so a large upload cannot bloat the batch.
 *
 * When trace capture is enabled the request is also broken into waterfall spans
 * (US-050): a `mw` span for bootstrap and middleware, a `ctrl` span for the
 * controller phase, an aggregate `db` span for the queries it ran, and a `resp`
 * span for sending the response. The controller span is opened around `$next`
 * ({@see SpanStack}), so the cache and outgoing-HTTP spans (US-046) emitted while
 * the controller runs nest beneath it, and the `db` span is clamped to the
 * controller window so no child span extends beyond its parent.
 */
final class CaptureRequests
{
    /** The telemetry signal name (US-026); the server routes it to `requests`. */
    private const EVENT_TYPE = 'request';

    /**
     * Queries observed since the current request began. Reset in {@see handle()}
     * and bumped by {@see recordQuery()} from the provider's `QueryExecuted`
     * listener, so it counts exactly this request's queries even in a long-lived
     * worker that handles many requests on one instance.
     */
    private int $queryCount = 0;

    /** Total wall time the request's queries took, in milliseconds (US-050 `db` span). */
    private float $dbTimeMs = 0.0;

    /** Trace offset of the first query, so the aggregate `db` span starts there. */
    private ?float $firstQueryOffsetMs = null;

    /**
     * Wall-clock time the request entered the middleware, the fallback start for
     * the duration measurement when the host does not define `LARAVEL_START`.
     */
    private ?float $start = null;

    /** Whether waterfall spans are being assembled for the current request (US-050). */
    private bool $tracesEnabled = false;

    /** The controller-phase span id while it is open, else null. */
    private ?string $ctrlSpanId = null;

    /** The controller span's own parent id (top-level `''` for a normal request). */
    private string $ctrlParentId = '';

    /** The controller span's own nesting depth (0 at the top level). */
    private int $ctrlDepth = 0;

    /** Trace offset (ms from trace start) at which the controller phase began. */
    private float $ctrlOffsetMs = 0.0;

    /** Wall-clock instant the controller phase began, for its duration. */
    private ?float $ctrlStartMicros = null;

    /** Measured controller-phase duration, in milliseconds. */
    private float $ctrlDurationMs = 0.0;

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Scrubber $scrubber,
        private readonly Repository $config,
        private readonly Container $container,
        private readonly SpanRecorder $spans,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->queryCount = 0;
        $this->dbTimeMs = 0.0;
        $this->firstQueryOffsetMs = null;
        $this->start = microtime(true);
        $this->ctrlSpanId = null;

        // An inbound batch from another Prism client is Prism's own traffic end
        // to end — not only the request itself, but every query, cache read and
        // log line emitted while it is handled. Skipping just the request event
        // would still capture that collateral, some of it unmatchable by pattern
        // (a rate limiter hashes its cache key), and each captured event ships a
        // batch that produces the next one. Suppress capture for the request's
        // whole lifetime instead, exactly as the package brackets its own
        // outbound flush. This middleware runs in the host's `web`/`api` group,
        // so `$next` still covers the route middleware that authenticates and
        // rate-limits the batch.
        if (Recursion::isInternalRequest($request->headers)) {
            $this->tracesEnabled = false;

            return Recursion::suppress(static fn (): Response => $next($request));
        }

        // Assemble waterfall spans only when trace capture is on and the request
        // is not one we ignore — an ignored request buffers nothing, so opening a
        // controller span for it would orphan the cache/HTTP spans that nest in it.
        $this->tracesEnabled = (bool) $this->config->get('prism.capture.traces', true)
            && ! $this->isIgnored($request);

        if ($this->tracesEnabled) {
            // Open the controller-phase span around $next so the cache, HTTP and
            // query spans emitted while the controller runs nest beneath it. Its
            // own parent/depth are captured before it is pushed; it is buffered
            // later in terminate(), once its name (the matched controller) is known.
            $this->ctrlParentId = SpanStack::currentId();
            $this->ctrlDepth = SpanStack::currentDepth();
            $this->ctrlOffsetMs = TraceContext::elapsedMs();
            $this->ctrlStartMicros = microtime(true);
            $this->ctrlSpanId = $this->spans->allocateId();
            SpanStack::open($this->ctrlSpanId);
        }

        try {
            return $next($request);
        } finally {
            // Close the controller frame even if the controller threw, so a stack
            // frame can never leak into the next request on a long-lived worker.
            if ($this->ctrlSpanId !== null) {
                $this->ctrlDurationMs = (microtime(true) - ($this->ctrlStartMicros ?? microtime(true))) * 1000;
                SpanStack::close($this->ctrlSpanId);
            }
        }
    }

    /**
     * Count one query toward the current request, and (when assembling spans)
     * accumulate its duration for the aggregate `db` span. Called from the
     * provider's `QueryExecuted` listener, which shares this singleton instance;
     * $timeMs is the query's duration in milliseconds.
     */
    public function recordQuery(float $timeMs = 0.0): void
    {
        $this->queryCount++;

        if (! $this->tracesEnabled) {
            return;
        }

        $timeMs = max(0.0, $timeMs);

        if ($this->firstQueryOffsetMs === null) {
            // The event fires once the query has run, so approximate its start as
            // now minus its duration — but never before the controller phase began.
            $this->firstQueryOffsetMs = max($this->ctrlOffsetMs, TraceContext::elapsedMs() - $timeMs);
        }

        $this->dbTimeMs += $timeMs;
    }

    /**
     * Record the finished request into the buffer, unless its path is ignored or
     * the request is the package's own work. Runs after the response is sent.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (Recursion::suppressed() || $this->isIgnored($request)) {
            return;
        }

        $this->buffer->add(self::EVENT_TYPE, $this->buildEvent($request, $response));

        if ($this->tracesEnabled && $this->ctrlSpanId !== null) {
            $this->emitPhaseSpans($request);
        }
    }

    /**
     * Buffer the request's lifecycle spans (US-050): a `mw` span for everything
     * before the controller, the `ctrl` span itself, an aggregate `db` span for
     * the queries it ran (nested under `ctrl` and clamped to its window so no
     * child extends beyond it), and a `resp` span for sending the response. The
     * `mw`, `ctrl` and `resp` spans are top-level siblings that partition the
     * request, matching the prototype's waterfall.
     */
    private function emitPhaseSpans(Request $request): void
    {
        $ctrlId = $this->ctrlSpanId;

        if ($ctrlId === null) {
            return;
        }

        $ctrlEnd = $this->ctrlOffsetMs + $this->ctrlDurationMs;

        // mw: bootstrap and middleware, from the front of the trace to where the
        // controller phase began. Omitted when there is nothing before it.
        if ($this->ctrlOffsetMs > 0.0) {
            $this->spans->emit('bootstrap+middleware', 'mw', 0.0, $this->ctrlOffsetMs, '', 0);
        }

        // ctrl: the controller phase, a top-level sibling. Emitted under the id
        // allocated in handle() so the cache/HTTP/db children that captured it as
        // their parent point at this span.
        $this->spans->emit(
            $this->controllerName($request),
            'ctrl',
            $this->ctrlOffsetMs,
            $this->ctrlDurationMs,
            $this->ctrlParentId,
            $this->ctrlDepth,
            [],
            $ctrlId,
        );

        // db: an aggregate of the queries the controller ran, nested under ctrl.
        // Clamped so the span can never extend beyond the controller phase.
        if ($this->queryCount > 0 && $this->firstQueryOffsetMs !== null) {
            $dbOffset = max($this->ctrlOffsetMs, $this->firstQueryOffsetMs);
            $dbDuration = max(0.0, min($this->dbTimeMs, $ctrlEnd - $dbOffset));

            $this->spans->emit(
                sprintf('db · %d %s', $this->queryCount, $this->queryCount === 1 ? 'query' : 'queries'),
                'db',
                $dbOffset,
                $dbDuration,
                $ctrlId,
                $this->ctrlDepth + 1,
                ['query_count' => $this->queryCount],
            );
        }

        // resp: response preparation and send, from the end of the controller
        // phase to now (terminate runs after the response is sent).
        $respOffset = $ctrlEnd;
        $respDuration = max(0.0, TraceContext::elapsedMs() - $respOffset);
        $this->spans->emit('response', 'resp', $respOffset, $respDuration, '', 0);
    }

    /**
     * The controller span's name: the matched route's controller action
     * (`App\Http\Controllers\OrderController@store`), falling back to the matched
     * URI for a closure route, and to a generic label when nothing matched.
     */
    private function controllerName(Request $request): string
    {
        $route = $request->route();

        if ($route instanceof Route) {
            $action = $route->getActionName();

            if ($action !== '' && $action !== 'Closure') {
                return $action;
            }

            if ($route->uri() !== '') {
                return $route->uri();
            }
        }

        return 'controller';
    }

    /**
     * Whether the request path matches any configured ignore pattern
     * ({@see Str::is} wildcards), which keeps Prism's own routes and health
     * checks out of the telemetry.
     */
    private function isIgnored(Request $request): bool
    {
        // A request carrying the internal marker is another Prism client's
        // outbound batch arriving here (US-039). The in-process suppression flag
        // cannot see across that boundary — this is a fresh request, in a fresh
        // process — so the header is the only signal available, and it is exactly
        // what the header exists for. Without this a Prism workspace monitoring
        // itself captures every inbound ingest POST as application traffic, and
        // each captured request ships a batch that produces the next one.
        if (Recursion::isInternalRequest($request->headers)) {
            return true;
        }

        $patterns = IgnoreList::patterns($this->config->get('prism.ignore.paths', []));

        return $patterns !== [] && $request->is(...$patterns);
    }

    /**
     * Build the event: the request's performance fields as a scrubbed payload,
     * plus the correlating envelope keys (trace id, request id, user id,
     * timestamp) the server reads off the top of the event (US-027).
     *
     * @return array<string, mixed>
     */
    private function buildEvent(Request $request, Response $response): array
    {
        $payload = [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'route' => $this->matchedRoute($request),
            'status' => $response->getStatusCode(),
            'duration_ms' => $this->durationMs(),
            'memory_mb' => $this->peakMemoryMb(),
            'query_count' => $this->queryCount,
            'ip' => (string) $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ];

        $body = $this->body($request);

        if ($body !== null) {
            $payload['body'] = $body;
        }

        return [
            'timestamp' => now()->toIso8601String(),
            'trace_id' => TraceContext::traceId(),
            'request_id' => (string) Str::uuid(),
            'user_id' => $this->currentUserId(),
            'payload' => $this->scrubber->scrub($payload),
        ];
    }

    /**
     * The matched route's URI pattern (e.g. `orders/{id}`), or empty when no
     * route matched — a 404, or a request that never reached routing.
     */
    private function matchedRoute(Request $request): string
    {
        $route = $request->route();

        return $route instanceof Route ? $route->uri() : '';
    }

    /**
     * Request duration in milliseconds, measured from `LARAVEL_START` when the
     * host defines it — so framework boot is included — and otherwise from the
     * moment this middleware ran.
     */
    private function durationMs(): float
    {
        return round((microtime(true) - $this->startTime()) * 1000, 3);
    }

    /** The request's start time: `LARAVEL_START` if defined, else the middleware entry. */
    private function startTime(): float
    {
        if (defined('LARAVEL_START')) {
            return (float) constant('LARAVEL_START');
        }

        return $this->start ?? microtime(true);
    }

    /** Peak memory the request reached, in megabytes. */
    private function peakMemoryMb(): float
    {
        return round(memory_get_peak_usage(true) / 1048576, 3);
    }

    /**
     * The captured request body for a non-GET request: the request input,
     * scrubbed of sensitive keys, JSON-encoded, then truncated to the configured
     * byte cap. Null for GET/HEAD (no meaningful body) or when the input cannot
     * be encoded. Scrubbing happens before truncation so a secret is redacted by
     * key rather than being sliced mid-value where the by-key match would miss it.
     */
    private function body(Request $request): ?string
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return null;
        }

        $encoded = json_encode($this->scrubber->scrub($request->all()));

        if (! is_string($encoded)) {
            return null;
        }

        $max = (int) $this->config->get('prism.request.max_body', 65536);

        if ($max > 0 && strlen($encoded) > $max) {
            return substr($encoded, 0, $max);
        }

        return $encoded;
    }

    /** The authenticated user id, or null with no bound guard or no user. */
    private function currentUserId(): ?string
    {
        if (! $this->container->bound('auth')) {
            return null;
        }

        $auth = $this->container->make('auth');

        if (! is_object($auth) || ! method_exists($auth, 'id')) {
            return null;
        }

        /** @var mixed $id */
        $id = $auth->id();

        return is_scalar($id) ? (string) $id : null;
    }
}
