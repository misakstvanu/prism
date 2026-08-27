<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Browser;

use Misakstvanu\Prism\Nightwatch\RedactRules;
use Misakstvanu\Prism\Support\Scrubber;

/**
 * `prism.scrub` said in the browser's vocabulary (US-009).
 *
 * A host writes one list of sensitive field names and expects it to mean the
 * same thing wherever a value comes from. This class is what makes that true of
 * a browser report, and it deliberately holds **no rule of its own**: what a
 * sensitive key is stays {@see Scrubber}'s answer, and what a `name = value`
 * pair or a query string looks like stays {@see RedactRules}' — the same two
 * implementations every PHP signal already meets. All that is new here is
 * *where* they apply, because a browser payload is a shape neither of them has
 * seen: an error carries a bag of page context and a list of breadcrumbs, a log
 * line carries the context of the HTTP call that failed, and a page view is an
 * address and nothing else.
 *
 * **It dispatches on the KEY, not on the event type.** There is no per-signal
 * table below, and that is on purpose: `url` means an address whether it is a
 * page view's own, the address an error happened at, the target of a failed
 * `fetch` or where a navigation crumb went, and a rule written per type would
 * have to name all four and would silently miss the fifth. So the three shapes
 * a browser payload is made of — a bag of keyed values, free text, an address —
 * are answered wherever they appear, and a signal added to
 * {@see BrowserEvent::TYPES} tomorrow is covered by whichever of them it uses.
 *
 * The {@see Scrubber} pass is therefore over the **whole payload**, not over the
 * two bags a page composes by hand. `user_agent` is a key in an error's context
 * and a column of its own on a page view; `release` and `session_id` are columns
 * on one signal and context keys on another — and a host that wrote one name on
 * one list is owed one answer, not an answer that depends on which table the
 * value happened to land in. It recurses, so a secret three objects down inside
 * a `setContext()` value is found wherever that value is hung.
 *
 * Three orderings carry the correctness:
 *
 *   - **The {@see Scrubber} runs first and the URL rewrite second.** A host that
 *     put `url` on the scrub list gets `[REDACTED]` rather than a rewritten
 *     address — the stronger of the two answers, and the one they asked for —
 *     and the rewrite over what is left is then a no-op, because a redacted
 *     value has no query string.
 *   - **The whole of it runs LAST, after {@see BrowserForwarder} has written the
 *     request's own facts over the page's.** `prism.scrub` is a promise about
 *     what leaves the process, not about what a page said, so the server's own
 *     `ip` and `user_agent` meet the list exactly as the page's copies would
 *     have. A rule that governed one and not the other would be two answers to
 *     one question.
 *   - **Only the keyed pass reaches an exception's `frames`, and under any
 *     ordinary list it leaves them exactly as they arrived.** Those are what the
 *     server hashes into a browser error's fingerprint (US-004), so neither the
 *     free-text rule nor the URL rule is pointed at them: a file path is an
 *     address in a bundle rather than a credential, and rewriting one would move
 *     every group it touched for nothing. A host that puts `file` on the scrub
 *     list moves them deliberately, which is a different thing from moving them
 *     by accident.
 *
 * The message *is* redacted, and that is a real difference from the PHP side
 * worth stating out loud. {@see RedactRules::redactException()} may rewrite a
 * message because nothing groups on it; a browser fingerprint hashes the
 * message template, so something does. It is still right to redact: the
 * rewrite is deterministic and happens before the event leaves the backend, so
 * every occurrence of one fault is redacted identically and groups identically,
 * and the console never sees the original either way. What it costs is that
 * *adding* a key to `prism.scrub` splits the group of any browser error whose
 * message carried that pair — the same one-off move a host accepts whenever it
 * changes what it captures, and cheaper than shipping the secret.
 */
final class BrowserScrubber
{
    /**
     * The keys whose value is an address, wherever a bag of values is redacted.
     *
     * `url` and `referrer` are the SDK's own names on a page view, in an error's
     * context and in a failed call's; `from` and `to` are a navigation
     * breadcrumb's. One list rather than one per place, so a query string is
     * rewritten by the same rule whichever of them it arrived under.
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
        // By key, everywhere, to any depth — exactly as a request body is
        // answered, and by the same object. Everything below it is what a keyed
        // rule cannot do: a token in a query string is not a key of the bag, it
        // is a fragment of one of its values.
        $payload = $this->scrubber->scrub($payload);

        // An error's or a log line's message. Free text holding `name = value`
        // pairs is exactly what `redactText()` is: a `console.error` printing a
        // request body, an SDK message quoting the call that failed.
        if (is_string($payload['message'] ?? null)) {
            $payload['message'] = $this->rules->redactText($payload['message']);
        }

        // A page view's own address and referrer. An error's are in its context
        // and a failed call's target is in its log line's, which is the same
        // list one level down.
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
     * Both halves that can carry a value are answered — beyond the keyed pass
     * above, which has already walked the whole list. `data` holds addresses (an
     * `http` crumb's url, a `navigation` crumb's `from` and `to`) and `message`
     * is free text that often *contains* one rather than naming it (`navigation`
     * renders as `from → to`), which is why the pair rule runs over the message
     * as well as the URL rule running over the data beneath it.
     *
     * Anything that is not an object is returned as it arrived: a page in a bad
     * state is the page most worth hearing from, so a malformed crumb is carried
     * rather than made a reason to lose the nineteen good ones beside it — the
     * stance {@see BrowserEvent} takes throughout.
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
