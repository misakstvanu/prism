<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use Illuminate\Support\Str;
use Misakstvanu\Prism\Capture\CacheCapture;
use Misakstvanu\Prism\Capture\CaptureRequests;
use Misakstvanu\Prism\Capture\ExceptionCapture;
use Misakstvanu\Prism\Capture\HttpCapture;
use Misakstvanu\Prism\Capture\JobCapture;
use Misakstvanu\Prism\Capture\ScheduleCapture;

/**
 * The one matcher behind every `prism.ignore.*` list.
 *
 * Some activity in a host application is not worth capturing — and some of it
 * must not be captured at all. The second case is the important one: when an
 * application talks to a service that is itself observed by Prism, capturing
 * that conversation feeds the very pipeline that produced it. The clearest
 * example is a Prism workspace monitoring itself, where the inbound ingest
 * request, the job that processes the batch and the datastore write it performs
 * each generate the events that trigger the next round. {@see Recursion} closes
 * that loop for the package's own work; this closes it for the host's.
 *
 * Six dimensions can be silenced, each a list of patterns in the client's
 * `prism.ignore` config block:
 *
 *   - `paths` — request URI patterns ({@see CaptureRequests})
 *   - `jobs` — queued job class names ({@see JobCapture})
 *   - `commands` — scheduled task commands ({@see ScheduleCapture})
 *   - `http` — outgoing HTTP destinations ({@see HttpCapture})
 *   - `cache` — cache keys ({@see CacheCapture})
 *   - `exceptions` — exception classes, matched by `instanceof` rather than by
 *     pattern (a subclass of an ignored throwable is ignored too), so those are
 *     handled in {@see ExceptionCapture} and not here
 *
 * Every dimension but `exceptions` matches with {@see Str::is}, so `*` is a
 * wildcard: `api/*`, `App\Jobs\*`, `prism:*`, `*.internal`. Matching is exact
 * when a pattern carries no wildcard.
 *
 * Both methods are static and allocate nothing beyond the match, so a capture
 * listener can consult one on a hot path without a container lookup — the same
 * stance as {@see Recursion}, {@see TraceContext} and {@see SpanStack}.
 */
final class IgnoreList
{
    /**
     * Normalise a raw config value into a pattern list.
     *
     * Config is untyped, so a published file can hold anything; a non-array
     * value yields no patterns and any non-string entry is dropped rather than
     * being coerced into a pattern nobody wrote. Callers resolve this once at
     * boot and hand the result to a capturer's constructor, so the hot path
     * never re-reads or re-filters config.
     *
     * @return list<string>
     */
    public static function patterns(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * Whether any of $values matches any of $patterns.
     *
     * Several candidates are accepted because a single subject is often
     * addressable more than one way and a user should not have to guess which
     * form to write — an outgoing HTTP call, for instance, is offered as its
     * host, its host and path, and its full URL, so `clickhouse`,
     * `clickhouse/*` and `http://clickhouse:8123/*` all work. An empty candidate
     * never matches, so a missing host or an unnamed job cannot be silenced by a
     * bare `*` pattern meant for something else.
     *
     * @param  list<string>  $patterns
     */
    public static function matches(array $patterns, string ...$values): bool
    {
        if ($patterns === []) {
            return false;
        }

        foreach ($values as $value) {
            if ($value !== '' && Str::is($patterns, $value)) {
                return true;
            }
        }

        return false;
    }
}
