<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use Illuminate\Support\Str;
use Misakstvanu\Prism\Http\Middleware\RejectIgnoredRequests;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RejectRules;

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
 *   - `paths` — request URI patterns ({@see RejectIgnoredRequests})
 *   - `jobs` — queued job class names
 *   - `commands` — scheduled task and artisan commands
 *   - `http` — outgoing HTTP destinations
 *   - `cache` — cache keys
 *   - `exceptions` — exception classes, matched by `instanceof` rather than by
 *     pattern (a subclass of an ignored throwable is ignored too), so those are
 *     handled in {@see PrismIngest} and not here
 *
 * Since US-010 every one of them is said in the capture engine's own vocabulary
 * by {@see RejectRules} — three of the six as `reject*` callbacks, two as a
 * refused sampling decision and `exceptions` as a drop at Prism's ingest — but
 * the patterns and the matching are still this class's, so a list means the
 * same thing wherever it is consulted.
 *
 * Every dimension but `exceptions` matches with {@see Str::is}, so `*` is a
 * wildcard: `api/*`, `App\Jobs\*`, `prism:*`, `*.internal`. Matching is exact
 * when a pattern carries no wildcard.
 *
 * Both methods are static and allocate nothing beyond the match, so a caller can
 * consult one on a hot path without a container lookup — the same stance as
 * {@see Recursion} and {@see TraceContext}.
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

    /**
     * The same pattern as a PCRE, for a matcher that speaks regex rather than
     * {@see Str::is}.
     *
     * `laravel/nightwatch`'s cache-key rejection is regex-first: it runs
     * `@preg_match($pattern, $key)` and falls back to a literal string
     * comparison only when the pattern would not compile. A raw `prism:*`
     * compiles as nothing (no delimiters, so `preg_match` returns false) and is
     * then compared literally against the key — so it silently matches nothing
     * at all, which is exactly how a configured exclusion turns into a
     * self-monitoring loop nobody sees. Converting it here means the conversion
     * exists once, beside the rule it mirrors, rather than at each call site.
     *
     * The conversion is {@see Str::is}'s own: everything is quoted except `*`,
     * which becomes `.*`. A pattern that ends in a wildcard drops the tail
     * anchor with it, so `prism:*` becomes a prefix match — the shape
     * upstream's own default vendor cache keys are written in — and a pattern
     * with no wildcard is anchored at both ends and therefore matches exactly,
     * as it does everywhere else in this class. Everything but the wildcard is
     * quoted, so the dots and colons a cache key is full of stay literal.
     */
    public static function toRegex(string $pattern): string
    {
        $quoted = str_replace('\*', '.*', preg_quote($pattern, '#'));

        if (str_ends_with($quoted, '.*')) {
            return '#^'.substr($quoted, 0, -2).'#u';
        }

        return '#^'.$quoted.'\z#u';
    }
}
