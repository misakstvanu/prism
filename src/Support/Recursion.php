<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use Misakstvanu\Prism\Contracts\PrismInternal;
use Misakstvanu\Prism\Http\Middleware\RejectIgnoredRequests;
use Throwable;

/**
 * Recursion guard (US-039): the one place a capture listener (US-042+) asks "is this activity mine?".
 * Without it, shipping a batch produces the events the next batch ships and the buffer never empties.
 * All static and side-effect free beyond the counter, for a hot-path consult with no container lookup.
 *
 *   - In-process. {@see suppress()} raises a reentrant depth counter around the package's own work
 *     (building an envelope, flushing, sending); {@see suppressed()} is what a listener checks. Covers
 *     log capture (AC3) and package-internal exceptions thrown during a flush (AC4).
 *   - Out-of-band. An ingest POST and the queued flush job cross a boundary the counter cannot reach
 *     (fresh request, separate worker), so they carry a mark — {@see MARKER_HEADER} (AC1),
 *     {@see PrismInternal} (AC2) — read back by {@see isInternalRequest()} / {@see isInternalJob()}.
 */
final class Recursion
{
    /**
     * Stamped on every outbound Prism HTTP call so the client's own HTTP capture (US-046) skips
     * it — otherwise shipping a batch is recorded as an outgoing request and shipped in turn.
     */
    public const MARKER_HEADER = 'X-Prism-Internal';

    /** Root namespace of the package's own classes, the mark of its own work. */
    private const NAMESPACE_PREFIX = 'Misakstvanu\\Prism\\';

    /**
     * Cache namespace of the package's own bookkeeping — the spool index and its segments, the metrics
     * interval marks. Those touches are the package working, so capturing them is capturing capture.
     */
    public const CACHE_PREFIX = 'prism:';

    /** Depth of nested {@see suppress()} scopes; greater than zero means suppressed. */
    private static int $depth = 0;

    /**
     * Run $callback with capture suppressed, restoring the previous depth even if it throws. Nests safely:
     * an inner scope leaves an outer one suppressed on return, the flag clearing only at the outermost exit.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function suppress(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            // max() guards underflow if reset() was called mid-scope, which would leave the counter
            // negative and let a later suppress() read as un-suppressed.
            self::$depth = max(0, self::$depth - 1);
        }
    }

    /**
     * True while inside a {@see suppress()} scope. A capture listener returns early on this so the
     * package's own activity is never recorded.
     */
    public static function suppressed(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Force suppression off — only for a long-lived runtime (Octane, a queue worker) resetting between
     * requests or jobs, so a scope left unbalanced by a fatal error cannot wedge capture off for the
     * life of the process.
     */
    public static function reset(): void
    {
        self::$depth = 0;
    }

    /**
     * Whether request headers carry the internal marker, so the HTTP capture listener skips the request
     * and an ingest POST is never captured as application traffic (AC1). Case-insensitive: a client or
     * proxy may normalise header casing.
     *
     * @param  iterable<string, mixed>  $headers  Header name => value(s).
     */
    public static function isInternalRequest(iterable $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (is_string($name) && strcasecmp($name, self::MARKER_HEADER) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a job is the package's own — the flush job (US-038) or any other it dispatches. The job
     * capture listener skips these, so the flush job is never itself traced (AC2). Recognised by the
     * {@see PrismInternal} marker or by living in the package namespace, so a future internal job is
     * covered even before it declares the interface.
     */
    public static function isInternalJob(object $job): bool
    {
        return $job instanceof PrismInternal
            || self::isInternalClass($job::class);
    }

    /**
     * Whether a class name is the package's own — the name rather than the object, because the capture
     * engine reports some of its work as a string and nothing else: a queued-job record carries the job's
     * display name, and by the time a job attempt is reported the object is behind a queue driver's
     * wrapper. Both need the same answer as {@see isInternalJob()}, and one prefix written twice drifts.
     */
    public static function isInternalClass(string $class): bool
    {
        return str_starts_with($class, self::NAMESPACE_PREFIX);
    }

    /**
     * Whether a cache key is one the package writes for itself. The metrics collectors run deliberately
     * *outside* a {@see suppress()} scope — their own guards would otherwise read as tripped — so their
     * interval marks are ordinary cache traffic to anything watching, and the spool's index is written
     * from a flush a long-lived worker may have left the scope of: both the package working, which a
     * capture engine recording them would be capturing.
     */
    public static function isInternalCacheKey(string $key): bool
    {
        return str_starts_with($key, self::CACHE_PREFIX);
    }

    /**
     * Whether an exception originated inside package code, so it is never reported (US-042 AC4) — a
     * fault in the client must not travel through the client. True when thrown during suppressed work,
     * when it is a {@see PrismInternal} or package exception class, or when *thrown from* package
     * source: the exception's own origin ({@see Throwable::getFile()}), deliberately NOT any frame in
     * its trace, since the package puts middleware in every request's call stack
     * ({@see RejectIgnoredRequests}) and a whole-trace scan would flag every genuine application
     * exception raised inside a request as internal and silently drop it. The origin identifies the
     * package as the thrower; its own outbound work (flush, send) is already bracketed by
     * {@see suppress()}, so an exception it raises is caught above wherever it surfaces.
     */
    public static function isInternalException(Throwable $e): bool
    {
        if (self::suppressed()) {
            return true;
        }

        if ($e instanceof PrismInternal || self::isInternalClass($e::class)) {
            return true;
        }

        return self::pathInPackage($e->getFile(), self::packageSource());
    }

    /** Absolute path of the package's src/ directory. */
    private static function packageSource(): string
    {
        return dirname(__DIR__);
    }

    /** Whether $path lives under the package's src/ directory. */
    private static function pathInPackage(string $path, string $src): bool
    {
        return str_starts_with($path, $src.DIRECTORY_SEPARATOR);
    }
}
