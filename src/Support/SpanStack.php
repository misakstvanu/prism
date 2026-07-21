<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use Misakstvanu\Prism\Capture\SpanRecorder;

/**
 * The stack of currently-open trace spans (US-050).
 *
 * Span assembly needs to know, at the moment any span is emitted, which span is
 * its parent and how deeply it is nested. This is the one place that lineage
 * lives: whenever a span opens it pushes its id here, and whenever a span or a
 * point-in-time event (a cache lookup, an outgoing HTTP call) is buffered it
 * reads {@see currentId()} for its `parent_span_id` and {@see currentDepth()}
 * for its nesting depth. A span opened inside another is therefore that span's
 * child by construction — and, because a child's whole lifetime falls inside its
 * parent's, no child span can extend beyond its parent.
 *
 * Every method is static and side-effect free (beyond the stored stack) so a
 * capture listener on a hot path can read the current parent with no container
 * lookup — the same stance as {@see TraceContext} and {@see Recursion}. A
 * long-lived runtime (Octane, a queue worker) resets it between requests or jobs
 * so one execution's open spans can never leak into the next; the recorder that
 * buffers spans ({@see SpanRecorder}) drives the push/pop.
 */
final class SpanStack
{
    /**
     * The ids of the currently-open spans, outermost first and the innermost
     * (current parent) last.
     *
     * @var list<string>
     */
    private static array $ids = [];

    /** Push a newly-opened span's id, making it the current parent. */
    public static function open(string $id): void
    {
        self::$ids[] = $id;
    }

    /**
     * Pop a closing span. Normally it is the innermost open span, but an
     * out-of-order close removes it by id rather than blindly popping the top,
     * so an unbalanced close can never wedge the stack against later spans.
     */
    public static function close(string $id): void
    {
        $index = array_search($id, self::$ids, true);

        if ($index !== false) {
            array_splice(self::$ids, $index, 1);
        }
    }

    /**
     * The current parent span id — the innermost open span — or an empty string
     * when nothing is open (a top-level span).
     */
    public static function currentId(): string
    {
        return self::$ids === [] ? '' : self::$ids[count(self::$ids) - 1];
    }

    /**
     * The nesting depth a span opening now would take: the number of currently
     * open ancestor spans (0 at the top level, 1 under one open span, …).
     */
    public static function currentDepth(): int
    {
        return count(self::$ids);
    }

    /**
     * Clear every open span. Only for a long-lived runtime resetting between
     * requests or jobs, so one execution's open spans never leak into the next.
     */
    public static function reset(): void
    {
        self::$ids = [];
    }
}
