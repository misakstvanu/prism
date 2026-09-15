<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Browser;

use Misakstvanu\Prism\Nightwatch\RedactRules;
use Misakstvanu\Prism\Support\Scrubber;

/**
 * `prism.scrub` said in the browser's vocabulary (US-009).
 *
 * Holds **no rule of its own**: a sensitive key is {@see Scrubber}'s answer, a
 * `name = value` pair or a query string {@see RedactRules}' — the objects every
 * PHP signal meets — and adds only *where* they apply. **It dispatches on the
 * KEY, not the event type**, since `url` names an address in four places across
 * the three signals and a per-type rule would name all four and silently miss
 * the fifth; the three shapes a payload is made of (keyed values, free text, an
 * address) are answered wherever they appear, covering a signal added to
 * {@see BrowserEvent::TYPES} tomorrow. The {@see Scrubber} pass therefore covers
 * the **whole payload**, not the two bags a page composes by hand —
 * `user_agent`, `release` and `session_id` are context keys on one signal and
 * columns on another, one name being owed one answer rather than one depending
 * on which table it landed in — recursing to find a secret three objects down
 * inside a `setContext()` value.
 *
 * Three orderings carry the correctness: {@see Scrubber} first and the URL
 * rewrite second, so `url` on the scrub list gets the stronger `[REDACTED]`
 * asked for and the rewrite over it is a no-op (a redacted value has no query
 * string); the whole of it LAST, after {@see BrowserForwarder} wrote the
 * request's own facts over the page's, since `prism.scrub` governs what leaves
 * the process rather than what a page said and the server's own `ip` and
 * `user_agent` must meet the list exactly as the page's copies would; and only
 * the keyed pass reaching an exception's `frames`, left as they arrived under
 * any ordinary list, since the server hashes them into a browser error's
 * fingerprint (US-004) and rewriting a bundle path — an address, not a
 * credential — would move every group it touched for nothing. A host that puts
 * `file` on the scrub list moves them deliberately.
 *
 * The message *is* redacted, unlike the PHP side where
 * {@see RedactRules::redactException()} may rewrite one because nothing groups
 * on it: a browser fingerprint hashes the message template. Still right, the
 * rewrite being deterministic and preceding the event leaving the backend, so
 * one fault's occurrences redact and group identically and the console never
 * sees the original — at the cost that *adding* a key to `prism.scrub` splits
 * the group of any browser error whose message carried that pair, a one-off
 * cheaper than shipping the secret.
 */
final class BrowserScrubber
{
    /**
     * The keys whose value is an address, wherever a bag of values is redacted:
     * `url`/`referrer` (the SDK's names on a page view, in an error's context,
     * in a failed call's) and `from`/`to` (a navigation breadcrumb's). One list,
     * not one per place, so one rule rewrites a query string whichever it
     * arrived under.
     *
     * @var list<string>
     */
    private const URL_KEYS = ['url', 'referrer', 'from', 'to'];

    public function __construct(
        private readonly Scrubber $scrubber,
        private readonly RedactRules $rules,
    ) {}

    /**
     * One browser event's payload, with every value the host asked to be rid of
     * gone from it.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function scrub(array $payload): array
    {
        // By key, everywhere, to any depth — as a request body is answered, and
        // by the same object. Below is what a keyed rule cannot do: a token in a
        // query string is not a key of the bag, it is a fragment of one value.
        $payload = $this->scrubber->scrub($payload);

        // An error's or a log line's message: free text holding `name = value`
        // pairs is what `redactText()` is.
        if (is_string($payload['message'] ?? null)) {
            $payload['message'] = $this->rules->redactText($payload['message']);
        }

        // A page view's own address and referrer; an error's and a failed call's
        // target are in their context — the same list one level down.
        $payload = $this->addresses($payload);

        if (is_array($payload['context'] ?? null)) {
            $payload['context'] = $this->addresses($payload['context']);
        }

        if (is_array($payload['breadcrumbs'] ?? null)) {
            $payload['breadcrumbs'] = array_map(
                fn (mixed $crumb): mixed => $this->crumb($crumb),
                $payload['breadcrumbs'],
            );
        }

        return $payload;
    }

    /**
     * Rewrite the query string of every value whose key names an address.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function addresses(array $values): array
    {
        foreach (self::URL_KEYS as $key) {
            if (is_string($values[$key] ?? null)) {
                $values[$key] = $this->rules->redactUrl($values[$key]);
            }
        }

        return $values;
    }

    /**
     * One breadcrumb: `{timestamp, category, message, data?}`.
     *
     * Beyond the keyed pass above, which already walked the whole list, both
     * halves that can carry a value are answered: `data` holds addresses (an
     * `http` crumb's url, a `navigation` crumb's `from` and `to`), `message` is
     * free text that often *contains* one rather than naming it (`navigation`
     * renders as `from → to`). Anything not an object is returned as it arrived
     * — a page in a bad state is the page most worth hearing from, so one
     * malformed crumb must not cost the nineteen good ones beside it, the stance
     * {@see BrowserEvent} takes throughout.
     */
    private function crumb(mixed $crumb): mixed
    {
        if (! is_array($crumb)) {
            return $crumb;
        }

        if (is_string($crumb['message'] ?? null)) {
            $crumb['message'] = $this->rules->redactText($crumb['message']);
        }

        if (is_array($crumb['data'] ?? null)) {
            $crumb['data'] = $this->addresses($crumb['data']);
        }

        return $crumb;
    }
}
