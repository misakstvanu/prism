<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Nightwatch;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Laravel\Nightwatch\Records\OutgoingRequest;
use Laravel\Nightwatch\Records\QueuedJob;
use Misakstvanu\Prism\Support\IgnoreList;
use Misakstvanu\Prism\Support\Recursion;

/**
 * `prism.ignore.*` said in the capture engine's vocabulary (US-010), in one file rather than five call
 * sites that each guessed. The lists are unchanged — a host still writes {@see IgnoreList} wildcards
 * under one `ignore` block — but `laravel/nightwatch` offers a different hook per dimension and no hook
 * at all for two of them. Every method is a pure predicate over already-resolved pattern lists, a
 * hot-path consult with no container lookup, the same stance as {@see IgnoreList} itself.
 *
 * When the host application *is* a Prism workspace, the whole ingest pipeline is work the host does on
 * Prism's behalf — the inbound batch a request, storing it a job, metering it a run of cache counters,
 * the write an HTTP call to ClickHouse — each captured, shipped and stored by the pipeline that
 * produced it. {@see Recursion} closes that loop for the package's *own* work, this closes it for the
 * host's, and the loop does not settle on its own. Five hooks, three shapes, not interchangeable:
 *
 *   - **Rejected outright** (`cache`, `http`, `jobs`): a Nightwatch reject callback per record type,
 *     never buffering what one refuses, so an ignored signal costs nothing — and losing the
 *     per-execution counter with it is the point, not a side effect.
 *   - **Sampled out** (`paths`, `commands`): no reject hook exists for an execution — a request or a
 *     command *is* the execution, not a record inside one — so `Core::dontSample()` discards the whole
 *     buffer at `finishExecution()` instead of shipping it, and every query, cache event and log line
 *     goes with the request record: strictly more than the old client dropped, and what an ignore list
 *     is asking for.
 *   - **Dropped at the seam** (`exceptions`): Nightwatch has no reject callback for one, so the
 *     earliest place Prism owns is {@see PrismIngest::write()}; the record is built either way, and
 *     this prevents it reaching the buffer, the envelope or the wire.
 */
final class RejectRules
{
    /**
     * @param  list<string>  $paths  Request URI patterns.
     * @param  list<string>  $jobs  Queued job class names, as resolved for display.
     * @param  list<string>  $commands  Artisan command names.
     * @param  list<string>  $http  Outgoing HTTP destinations.
     * @param  list<string>  $cache  Cache keys.
     * @param  list<string>  $exceptions  Exception classes, matched by `instanceof`.
     */
    public function __construct(
        private readonly array $paths = [],
        private readonly array $jobs = [],
        private readonly array $commands = [],
        private readonly array $http = [],
        private readonly array $cache = [],
        private readonly array $exceptions = [],
    ) {}

    /** Resolved once at boot, so no config is re-read or re-filtered per request. */
    public static function fromConfig(Repository $config): self
    {
        return new self(
            paths: self::requestPaths($config),
            jobs: IgnoreList::patterns($config->get('prism.ignore.jobs', [])),
            commands: IgnoreList::patterns($config->get('prism.ignore.commands', [])),
            http: IgnoreList::patterns($config->get('prism.ignore.http', [])),
            cache: IgnoreList::patterns($config->get('prism.ignore.cache', [])),
            exceptions: IgnoreList::patterns($config->get('prism.ignore.exceptions', [])),
        );
    }

    /**
     * `prism.ignore.paths`, plus the browser SDK's own reporting endpoint whatever the host configured
     * it to be. The shipped `ignore.paths` carries `_prism/*`, covering the default; this covers a
     * renamed one — {@see cacheKeyPatterns()}'s stance one dimension along, that a path existing
     * *because* Prism is installed is not the host's to remember to silence. Left captured, every
     * browser report becomes a request row plus the session read, the scrub pass and the queue dispatch
     * it made, on a route the page hits once per error. Only added when the endpoint is actually
     * registered: with `browser.enabled` off there is no such route, and silencing a path the host may
     * be serving something else on is not Prism's to do.
     *
     * @return list<string>
     */
    private static function requestPaths(Repository $config): array
    {
        $paths = IgnoreList::patterns($config->get('prism.ignore.paths', []));

        if (! $config->get('prism.browser.enabled', true)) {
            return $paths;
        }

        $browser = trim((string) $config->get('prism.browser.path', ''), '/');

        if ($browser === '' || in_array($browser, $paths, true)) {
            return $paths;
        }

        $paths[] = $browser;

        return $paths;
    }

    /**
     * `prism.ignore.cache` as the regex list `Nightwatch::rejectCacheKeys()` expects. The conversion is
     * load-bearing and lives in one helper ({@see IgnoreList::toRegex()}): upstream runs
     * `@preg_match($pattern, $key)` and falls back to a literal comparison only when the pattern will
     * not compile, so a raw `prism:*` silences precisely nothing — and a cache exclusion that silently
     * does nothing is how a workspace hosting itself drowns in its own bookkeeping.
     *
     * @return list<string>
     */
    public function cacheKeyPatterns(): array
    {
        return [
            // The package's own bookkeeping whatever the host configured — the spool index and the
            // metrics interval marks. The collectors run deliberately outside a
            // {@see Recursion::suppress()} scope, so this is the only thing keeping their touches
            // out of the telemetry they exist to produce.
            IgnoreList::toRegex(Recursion::CACHE_PREFIX.'*'),
            ...array_map(IgnoreList::toRegex(...), $this->cache),
        ];
    }

    /**
     * Whether the package is doing its own work right now, in which case nothing at all should be
     * captured. The old client's listeners consulted {@see Recursion} directly; the capture engine's
     * sensors do not and cannot be made to, so the reject callbacks ask once per signal type. Building
     * an envelope, spooling a batch and shipping it are all bracketed in a suppression scope; without
     * this, sending telemetry is itself telemetry.
     */
    public function rejectsCurrentWork(): bool
    {
        return Recursion::suppressed();
    }

    /**
     * Whether an outgoing call's destination is on `prism.ignore.http`. Offered three ways — host, host
     * and path, and the full URL without its query string — exactly as the old client offered it, so a
     * host that wrote `clickhouse`, `clickhouse/*` or `http://clickhouse:8123/*` keeps the answer it
     * had. The query string is excluded: it varies per call and may carry a credential.
     */
    public function rejectsOutgoingRequest(OutgoingRequest $record): bool
    {
        return $this->rejectsUrl($record->url);
    }

    /**
     * Whether a URL is on `prism.ignore.http`. Split out of {@see rejectsOutgoingRequest()} for the span
     * lane (US-015): an OpenTelemetry client span describes the same outgoing call the capture engine's
     * record does, so the two must answer identically or a dogfooded install silences one and keeps
     * drawing the other.
     */
    public function rejectsUrl(string $url): bool
    {
        if ($this->http === []) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';
        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '';
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : null;

        // A port that is the scheme's own is not how anyone writes the destination down; PSR-7
        // drops it for the same reason.
        if ($port !== null && $port === self::defaultPort($scheme)) {
            $port = null;
        }

        $authority = $port === null ? $host : $host.':'.$port;

        return IgnoreList::matches(
            $this->http,
            $host,
            $host.$path,
            $scheme.'://'.$authority.$path,
        );
    }

    /** Whether a dispatched job is on `prism.ignore.jobs` — or is the package's own. */
    public function rejectsQueuedJob(QueuedJob $record): bool
    {
        return $this->rejectsJob($record->name);
    }

    /**
     * Whether a job class name is one never to capture. The package's own jobs are refused whatever the
     * host configured: the flush job exists only to ship telemetry, so capturing it produces the batch
     * that dispatches the next one. {@see Recursion::isInternalJob()} answers the same question for an
     * object; the capture engine reports a job by name, so one of the two has to speak strings.
     */
    public function rejectsJob(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        return Recursion::isInternalClass($name)
            || IgnoreList::matches($this->jobs, $name);
    }

    /**
     * Whether a request is one never to capture — its path is on `prism.ignore.paths`, or it carries the
     * internal marker. The marker is the important half: an inbound batch from another Prism client is
     * Prism's own traffic end to end, arriving on a path the receiving application chose and cannot be
     * assumed to know, and the header is the only signal that crosses that process boundary.
     */
    public function rejectsRequest(Request $request): bool
    {
        if (Recursion::isInternalRequest($request->headers)) {
            return true;
        }

        return $this->paths !== [] && $request->is(...$this->paths);
    }

    /** Whether an Artisan command is on `prism.ignore.commands`. */
    public function rejectsCommand(string $command): bool
    {
        return IgnoreList::matches($this->commands, $command);
    }

    /**
     * Whether an exception class is on `prism.ignore.exceptions`. Matched with `instanceof` semantics
     * rather than by pattern, so a subclass of an ignored throwable is ignored too — `is_a()` on the
     * name, because a record carries the class it was, not the object it came from. A name that cannot
     * be autoloaded does not match, the safe direction: the exception is reported rather than silently
     * swallowed.
     */
    public function rejectsException(string $class): bool
    {
        if ($class === '') {
            return false;
        }

        foreach ($this->exceptions as $ignored) {
            if ($class === $ignored || is_a($class, $ignored, allow_string: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a raw Nightwatch record describes an exception this rejects — the shape
     * {@see PrismIngest} asks in, kept here so the class that knows Prism's ignore lists is also the one
     * that knows which upstream field carries the thrown class. Any other record type is not this
     * list's business and answers false.
     *
     * @param  array<string, mixed>  $record
     */
    public function rejectsRecord(array $record): bool
    {
        if (($record['t'] ?? null) !== 'exception') {
            return false;
        }

        $class = $record['class'] ?? null;

        return is_string($class) && $this->rejectsException($class);
    }

    /** The port a scheme implies, or null for one with no convention here. */
    private static function defaultPort(string $scheme): ?int
    {
        return match (strtolower($scheme)) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
