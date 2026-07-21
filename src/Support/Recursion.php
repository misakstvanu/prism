<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use Misakstvanu\Prism\Contracts\PrismInternal;
use Misakstvanu\Prism\Http\Middleware\TraceRequests;
use Throwable;

/**
 * Recursion guard (US-039).
 *
 * A telemetry client that reported its own outbound traffic, its own flush job,
 * its own log lines and its own exceptions would feed ingest with the very act
 * of shipping to ingest — one flush produces events the next flush ships, and
 * the buffer never empties. This guard is the single place every capture
 * listener (US-042+) asks "is this activity mine?" so it can be skipped.
 *
 * Two kinds of signal are distinguished:
 *
 *   - In-process suppression. While the package does its own work — building an
 *     envelope, flushing, sending — a reentrant depth counter is raised.
 *     Anything a capture listener sees during that window (a debug log, an
 *     exception that escapes) is the package's own and is skipped. {@see
 *     suppress()} brackets that work; {@see suppressed()} is what a listener
 *     checks. This covers log capture (AC3) and package-internal exceptions
 *     thrown during a flush (AC4).
 *
 *   - Out-of-band markers. An outbound ingest POST and the queued flush job
 *     cross a boundary the in-process flag cannot reach (a fresh request on the
 *     server side, a separate worker process), so they carry an explicit mark:
 *     the request an {@see MARKER_HEADER} header (AC1), the job the {@see
 *     PrismInternal} interface (AC2). {@see isInternalRequest()} and {@see
 *     isInternalJob()} read those marks back.
 *
 * Every method is static and side-effect free (beyond the depth counter) so a
 * listener can consult it on a hot path without a container lookup.
 */
final class Recursion
{
    /**
     * Header stamped on every outbound Prism HTTP call so the client's own HTTP
     * capture (US-046) can recognise and skip it — otherwise shipping a batch
     * would itself be recorded as an outgoing request and shipped in turn.
     */
    public const MARKER_HEADER = 'X-Prism-Internal';

    /** Depth of nested {@see suppress()} scopes; greater than zero means suppressed. */
    private static int $depth = 0;

    /**
     * Run $callback with capture suppressed, restoring the previous depth even
     * if it throws. Nests safely: an inner scope leaves an outer one suppressed
     * on return, and the flag only clears once the outermost scope exits.
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
            // max() guards against underflow if reset() was called mid-scope,
            // which would otherwise leave the counter negative and let a later
            // suppress() read as un-suppressed.
            self::$depth = max(0, self::$depth - 1);
        }
    }

    /**
     * True while inside a {@see suppress()} scope. A capture listener returns
     * early on this so the package's own activity is never recorded.
     */
    public static function suppressed(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Force suppression off. Only for a long-lived runtime (Octane, a queue
     * worker) resetting between requests or jobs, so a scope left unbalanced by
     * a fatal error cannot wedge capture off for the life of the process.
     */
    public static function reset(): void
    {
        self::$depth = 0;
    }

    /**
     * Whether a set of request headers carries the internal marker. The HTTP
     * capture listener skips a request for which this is true so an ingest POST
     * is never captured as application traffic (AC1). Matched case-insensitively
     * because a client or proxy may normalise header casing.
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
     * Whether a job is the package's own — the flush job (US-038) or any other
     * job the package dispatches. The job capture listener skips these so the
     * flush job is never itself traced (AC2). Recognised by the {@see
     * PrismInternal} marker or by living in the package namespace, so a future
     * internal job is covered even before it declares the interface.
     */
    public static function isInternalJob(object $job): bool
    {
        return $job instanceof PrismInternal
            || str_starts_with($job::class, 'Misakstvanu\\Prism\\');
    }

    /**
     * Whether an exception originated inside package code, so the exception
     * capture listener (US-042) skips it — a fault in the client must never be
     * reported through the client (AC4). True when it is thrown during
     * suppressed work, is a {@see PrismInternal} or package exception class, or
     * was *thrown from* package source.
     *
     * "Thrown from" is the exception's own origin ({@see Throwable::getFile()}),
     * deliberately NOT any frame in its trace. The package installs a passthrough
     * middleware ({@see TraceRequests}, US-041)
     * that sits in the call stack of every request, so a whole-trace scan would
     * flag every genuine application exception raised inside a request as
     * internal and silently drop it. The origin is what identifies the package
     * as the thrower; the package's own outbound work (flush, send) is already
     * bracketed by {@see suppress()}, so an exception it raises is caught by the
     * suppression check above regardless of where it surfaces.
     */
    public static function isInternalException(Throwable $e): bool
    {
        if (self::suppressed()) {
            return true;
        }

        if ($e instanceof PrismInternal || str_starts_with($e::class, 'Misakstvanu\\Prism\\')) {
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
