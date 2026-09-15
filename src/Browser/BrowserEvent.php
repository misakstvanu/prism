<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Browser;

use DateTimeImmutable;
use Misakstvanu\Prism\Support\Text;
use Misakstvanu\Prism\Support\Timestamp;

/**
 * One event out of a browser report, validated and cut down to size (US-006).
 * Which kind of rule is broken decides what happens:
 *
 *   - **Structure is a drop.** An event naming a signal Prism has no table for,
 *     carrying a timestamp nothing can read or whose payload is not an object
 *     has no honest row to become, so {@see tryParse} answers null and the
 *     report counts it — never fatal, a page in a bad state being the page worth
 *     hearing from, and one malformed entry must not cost the nineteen good ones
 *     beside it (the stance the workspace's own ingest takes with an unknown
 *     event type).
 *   - **Size is a truncation.** A 40 KB error message, 900 stack frames or a
 *     context object holding a serialised API response are all *real* reports
 *     and refusing them would lose an error for being verbose, so every cap
 *     below cuts rather than rejects and what is left still names the fault.
 *
 * `trace_id` is neither: a page composes it (US-019 propagates it to the backend
 * as a `traceparent`) and a value that is not a W3C trace id keys nothing, but
 * it says nothing *wrong* about the event carrying it, so it is blanked rather
 * than made a reason to drop an error — an unkeyed browser error still lands on
 * the Errors screen, a dropped one lands nowhere.
 */
final class BrowserEvent
{
    /**
     * The signals a page may report: three of the workspace's own telemetry
     * event types, restated rather than imported, since this package is
     * installed into other people's applications and cannot see the server's
     * enum. A wire contract either way — a fourth type is a table, a column list
     * and a screen, so it is added deliberately on both sides or not at all.
     *
     * @var list<string>
     */
    public const TYPES = ['exception', 'log', 'page_view'];

    /** The longest an error's or a log line's message may be, in bytes. */
    private const MESSAGE_BYTES = 4096;

    /** The deepest a stack trace is kept. Below this is framework and noise. */
    private const MAX_FRAMES = 50;

    /** How many breadcrumbs ride an error. The most recent are the useful ones. */
    private const MAX_BREADCRUMBS = 50;

    /** The longest a JSON-encoded `context` object may be, in bytes. */
    private const CONTEXT_BYTES = 8192;

    /** The longest a URL may be, in bytes — an address, not a document. */
    private const URL_BYTES = 2048;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function __construct(
        public readonly string $type,
        public readonly DateTimeImmutable $timestamp,
        public readonly string $traceId,
        public readonly array $payload,
    ) {}

    /**
     * Read one posted event, or null when it is not one worth forwarding.
     */
    public static function tryParse(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $type = $raw['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            return null;
        }

        $timestamp = $raw['timestamp'] ?? null;

        if (! is_string($timestamp)) {
            return null;
        }

        $at = Timestamp::parse($timestamp);

        if ($at === null) {
            return null;
        }

        $payload = $raw['payload'] ?? null;

        // A JSON array decodes to a PHP list, which is not an object however
        // much it is also an array — a payload has to name columns. The empty
        // array is the exception: `{}` and `[]` decode alike, and an empty
        // payload is useless rather than malformed.
        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return null;
        }

        return new self(
            type: $type,
            timestamp: $at,
            traceId: self::traceId($raw['trace_id'] ?? null),
            payload: self::cap(Text::sanitize($payload)),
        );
    }

    /**
     * A W3C trace id, or blank: 32 lowercase hex characters, and not the
     * all-zero id — the spec reserves it for "no trace", and it is what a page
     * produces when it composes an id out of a random source that answered
     * zeros.
     */
    private static function traceId(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);

        if (preg_match('/^[0-9a-f]{32}$/', $value) !== 1) {
            return '';
        }

        return $value === str_repeat('0', 32) ? '' : $value;
    }

    /**
     * Apply every size cap, in place, to the payload's own keys: those named
     * here carry unbounded text in the three payload shapes (an exception's
     * message, frames, breadcrumbs and context; a log's message and context; a
     * page view's url and referrer). A key this does not know about is left
     * alone — it is either a column with a small fixed value or one the server
     * drops as unknown, so a cap for it would be a rule nothing needs.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function cap(array $payload): array
    {
        if (is_string($payload['message'] ?? null)) {
            $payload['message'] = Text::truncate($payload['message'], self::MESSAGE_BYTES);
        }

        foreach (['url', 'referrer'] as $key) {
            if (is_string($payload[$key] ?? null)) {
                $payload[$key] = Text::truncate($payload[$key], self::URL_BYTES);
            }
        }

        if (is_array($payload['frames'] ?? null)) {
            $payload['frames'] = array_slice(array_values($payload['frames']), 0, self::MAX_FRAMES);
        }

        if (is_array($payload['breadcrumbs'] ?? null)) {
            $payload['breadcrumbs'] = array_slice(array_values($payload['breadcrumbs']), 0, self::MAX_BREADCRUMBS);
        }

        if (is_array($payload['context'] ?? null)) {
            $payload['context'] = self::capContext($payload['context']);
        }

        return $payload;
    }

    /**
     * Cut a context object down to {@see CONTEXT_BYTES} of encoded JSON.
     *
     * Two passes, the cap being on the *encoded* form and there being two ways
     * to exceed it: one enormous value (the common case — a stringified response
     * body handed to `setContext`), where truncating it keeps its key and every
     * key beside it, or many ordinary values, where the only thing left to give
     * up is keys. **The key given up is the biggest one, never the last one**: a
     * context is a page's own bag of strings alongside the handful the SDK adds
     * (url, viewport, session, release), and those are what make an error
     * legible, where dropping in insertion order would keep whatever slab of
     * text caused the problem and throw away the four keys identifying the page
     * it happened on. It must never cut the encoded string — the column holds
     * JSON, and half a document is not a shorter document, it is an unreadable
     * one.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private static function capContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $context[$key] = Text::truncate(
                $value,
                $key === 'url' || $key === 'referrer' ? self::URL_BYTES : self::CONTEXT_BYTES,
            );
        }

        while ($context !== [] && strlen((string) json_encode($context)) > self::CONTEXT_BYTES) {
            $largest = null;
            $size = -1;

            foreach ($context as $key => $value) {
                $encoded = strlen((string) json_encode($value));

                if ($encoded > $size) {
                    $size = $encoded;
                    $largest = $key;
                }
            }

            if ($largest === null) {
                break;
            }

            unset($context[$largest]);
        }

        return $context;
    }
}
