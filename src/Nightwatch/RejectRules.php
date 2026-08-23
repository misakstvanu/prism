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
 * `prism.ignore.*` said in the capture engine's vocabulary (US-010).
 *
 * The lists themselves are unchanged — a host still writes {@see IgnoreList}
 * wildcards under one `ignore` block — but the engine reading them is now
 * `laravel/nightwatch`, which offers a different hook per dimension and no hook
 * at all for two of them. This class is the one place that translation happens,
 * so a screen full of self-monitoring noise can be traced to one file rather
 * than to five call sites that each guessed.
 *
 * Why this matters more than tidiness: when the host application *is* a Prism
 * workspace, the whole ingest pipeline is work the host does on Prism's behalf.
 * The inbound batch is a request, storing it is a job, metering it is a run of
 * cache counters and the write itself is an HTTP call to ClickHouse — each of
 * which is captured, shipped, and stored by the very pipeline that produced it.
 * {@see Recursion} closes that loop for the package's *own* work; this closes it
 * for the host's, and the loop does not settle on its own.
 *
 * Five hooks, three shapes, and the shapes are not interchangeable:
 *
 *   - **Rejected outright** (`cache`, `http`, `jobs`). Nightwatch takes a reject
 *     callback per record type and never buffers what one refuses. An ignored
 *     signal should cost nothing, and losing the per-execution counter that goes
 *     with it is the point rather than a side effect.
 *   - **Sampled out** (`paths`, `commands`). There is no reject hook for an
 *     execution, because a request or a command *is* the execution rather than a
 *     record inside one. `Core::dontSample()` is the equivalent: at
 *     `finishExecution()` the whole buffer is discarded instead of shipped, so
 *     not only the request record but every query, cache event and log line it
 *     produced goes with it, which is what an ignore list is asking for.
 *   - **Dropped at the seam** (`exceptions`). Nightwatch has no reject callback
 *     for an exception, so the earliest place Prism owns is
 *     {@see PrismIngest::write()}. The record is built either way; what this
 *     prevents is it reaching the buffer, the envelope or the wire.
 *
 * Every method is a pure predicate over already-resolved pattern lists, so a
 * hook can consult one on a hot path without a container lookup — the same
 * stance as {@see IgnoreList} itself.
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

    /**
     * Resolve every list once, at boot, so nothing re-reads or re-filters config
     * while a request is being served.
     */
    public static function fromConfig(Repository $config): self
    {
        return new self(
            paths: IgnoreList::patterns($config->get('prism.ignore.paths', [])),
            jobs: IgnoreList::patterns($config->get('prism.ignore.jobs', [])),
            commands: IgnoreList::patterns($config->get('prism.ignore.commands', [])),
            http: IgnoreList::patterns($config->get('prism.ignore.http', [])),
            cache: IgnoreList::patterns($config->get('prism.ignore.cache', [])),
            exceptions: IgnoreList::patterns($config->get('prism.ignore.exceptions', [])),
        );
    }

    /**
     * `prism.ignore.cache` as the regex list `Nightwatch::rejectCacheKeys()`
     * expects.
     *
     * The conversion is the load-bearing part and it lives in one helper
     * ({@see IgnoreList::toRegex()}): upstream runs `@preg_match($pattern, $key)`
     * and only falls back to a literal comparison when the pattern will not
     * compile, so handing it a raw `prism:*` silences precisely nothing — and a
     * cache exclusion that silently does nothing is how a workspace hosting
     * itself ends up drowning in its own bookkeeping.
     *
     * @return list<string>
     */
    public function cacheKeyPatterns(): array
    {
        return [
            // The package's own bookkeeping, whatever the host configured — the
            // spool index and the metrics interval marks. The metrics
            // collectors run deliberately outside a {@see Recursion::suppress()}
            // scope, so this is the only thing that keeps their touches out of
            // the telemetry they exist to produce.
            IgnoreList::toRegex(Recursion::CACHE_PREFIX.'*'),
            ...array_map(IgnoreList::toRegex(...), $this->cache),
        ];
    }

    /**
     * Whether the package is doing its own work right now, in which case
     * nothing at all should be captured.
     *
     * {@see Recursion} is the package's oldest guard and every one of the old
     * client's listeners consulted it directly. The capture engine's sensors
     * do not, and cannot be made to — so the reject callbacks are where that
     * question now gets asked, once per signal type. Building an envelope,
     * spooling a batch and shipping it are all bracketed in a suppression
     * scope; without this, the very act of sending telemetry is telemetry.
     */
    public function rejectsCurrentWork(): bool
    {
        return Recursion::suppressed();
    }

    /**
     * Whether an outgoing call's destination is on `prism.ignore.http`.
     *
     * The destination is offered three ways — host, host and path, and the full
     * URL without its query string — so `clickhouse`, `clickhouse/*` and
     * `http://clickhouse:8123/*` all silence the same call. The query string is
     * excluded because it varies per call and may carry a credential.
     */
    public function rejectsOutgoingRequest(OutgoingRequest $record): bool
    {
        return $this->rejectsUrl($record->url);
    }

    /**
     * Whether a URL is on `prism.ignore.http`.
     *
     * Split out of {@see rejectsOutgoingRequest()} for the span lane (US-015):
     * an OpenTelemetry client span describes the same outgoing call the capture
     * engine's record does, so the two must answer the ignore list identically
     * or a dogfooded install silences one and keeps drawing the other.
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

        // A port that is the scheme's own is not part of how anyone writes the
        // destination down, and PSR-7 drops it for the same reason.
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
     * Whether a job class name is one never to capture.
     *
     * The package's own jobs are refused whatever the host configured: the flush
     * job exists only to ship telemetry, so capturing it produces the batch that
     * dispatches the next one. {@see Recursion::isInternalJob()} answers the same
     * question for an object; the capture engine reports a job by name, and one
     * of the two has to speak strings.
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
     * Whether a request is one never to capture — either because its path is on
     * `prism.ignore.paths`, or because it carries the internal marker.
     *
     * The marker is the important half. An inbound batch from another Prism
     * client is Prism's own traffic end to end, arriving on a path the receiving
     * application chose and cannot be assumed to know; the header is the only
     * signal that crosses that process boundary.
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
     * Whether an exception class is on `prism.ignore.exceptions`.
     *
     * Matched with `instanceof` semantics rather than by pattern, so a subclass
     * of an ignored throwable is ignored too — `is_a()` on the name because a
     * record carries the class it was, not the object it came from. A name that
     * cannot be autoloaded simply does not match, which is the safe direction:
     * an exception is reported rather than silently swallowed.
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
     * Whether a raw Nightwatch record describes an exception this rejects.
     *
     * The shape {@see PrismIngest} asks in, kept here so the one class that
     * knows Prism's ignore lists is also the one that knows which upstream
     * field carries the thrown class. A record of any other type is not this
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
