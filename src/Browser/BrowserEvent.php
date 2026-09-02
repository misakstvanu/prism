<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Browser;

use DateTimeImmutable;
use Misakstvanu\Prism\Support\Text;
use Misakstvanu\Prism\Support\Timestamp;

/**
 * One event out of a browser report, validated and cut down to size (US-006).
 *
 * The rules here divide into two kinds, and which kind a rule is decides what
 * happens when it is broken:
 *
 *   - **Structure is a drop.** An event naming a signal Prism has no table for,
 *     carrying a timestamp nothing can read, or whose payload is not an object
 *     has no honest row to become, so {@see tryParse} answers null and the
 *     report counts it. Never fatal: a page in a bad state is exactly the page
 *     worth hearing from, and one malformed entry must not cost the nineteen
 *     good ones beside it — the same stance the workspace's own ingest takes
 *     with an unknown event type.
 *   - **Size is a truncation.** A 40 KB error message, 900 stack frames or a
 *     context object holding a serialised API response are all *real* reports;
 *     refusing them would lose an error because it was verbose. So every cap
 *     below cuts rather than rejects, and what is left still names the fault.
 *
 * The one field that is neither is `trace_id`. A page composes it (US-019
 * propagates it to the backend as a `traceparent`), and a value that is not a
 * W3C trace id would key nothing — but it also says nothing *wrong* about the
 * event carrying it, so it is blanked rather than made a reason to drop an
 * error. An unkeyed browser error still lands on the Errors screen; a dropped
 * one does not land anywhere.
 */
final class BrowserEvent
{
    /**
     * The signals a page may report.
     *
     * These are three of the workspace's own telemetry event types, restated
     * here rather than imported: this package is installed into other people's
     * applications and cannot see the server's enum. They are a wire contract
     * either way — a fourth type is a table, a column list and a screen, so it
     * is added deliberately on both sides or not at all.
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
        // much it is also an array — a payload has to be able to name columns.
        // The empty array is the exception: `{}` and `[]` are the same value
        // once decoded, and an event with an empty payload is merely useless
        // rather than malformed.
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
     * A W3C trace id, or blank.
     *
     * 32 lowercase hex characters, and not the all-zero id — which the spec
     * reserves for "no trace" and which is what a page produces when it composes
     * an id out of a random source that answered zeros.
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
     * Apply every size cap, in place, to the payload's own keys.
     *
     * The keys named here are the ones that carry unbounded text in the three
     * payload shapes (an exception's message, frames, breadcrumbs and context; a
     * log's message and context; a page view's url and referrer). A payload key
     * this does not know about is left alone: it is either a column with a small
     * fixed value or a key the server will drop as unknown, and inventing a cap
     * for it here would be a rule nothing needs.
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
     * Two passes, because the cap is on the *encoded* form and there are two
     * ways to exceed it. One enormous value is the common case — a stringified
     * response body handed to `setContext` — and truncating it keeps its key and
     * every key beside it. Many ordinary values is the other, and there the only
     * thing left to give up is keys.
     *
     * **The key given up is the biggest one, never the last one.** What is in a
     * context is a page's own bag of strings alongside the handful the SDK adds
     * (the url, the viewport, the session, the release), and those are what make
     * an error legible; dropping in insertion order would keep whatever slab of
     * text caused the problem and throw away the four keys that identify the
     * page it happened on.
     *
     * What it must never do is cut the encoded string. The column holds JSON,
     * and half a document is not a shorter document, it is an unreadable one.
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
