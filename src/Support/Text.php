<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

/**
 * The two rules every string has to meet before it can become a ClickHouse
 * column value (US-006).
 *
 * Both exist because of what happens at the *other* end of the pipeline. A
 * telemetry row is inserted into ClickHouse as JSON, and its parser rejects a
 * NUL byte and an invalid UTF-8 sequence outright — rejecting the whole insert,
 * not the offending row, so one stray NUL takes the entire batch it happened to
 * travel in with it. Nothing between here and there would report why: the batch
 * is built on a queue, and the insert fails long after the request that produced
 * it has been answered.
 *
 * Two callers, and each meets a different half of the danger. A browser report
 * (`Browser\BrowserEvent`) can post a NUL through
 * perfectly valid JSON — the six characters that spell the escape for code point
 * zero decode to one — which is what makes {@see clean} load-bearing rather than
 * defensive there; invalid UTF-8 cannot survive `json_decode` at all, and
 * reaches that path only through the enrichment the host adds server-side (a
 * `User-Agent` header is a byte string an old client can put anything in). A
 * captured HTTP body (`Http\BodyRecorder`) is the
 * other door and the wider one: a request body is whatever bytes an unknown
 * client wrote, and a response body is whatever the application produced — a
 * gzipped payload, a rendered image, a binary export — none of which passed
 * through a JSON parser on the way here.
 *
 * {@see truncate} is the byte-bounded half. Every cap on a captured string is
 * measured in bytes, because a column's cost is bytes — but cutting a byte
 * string at a byte offset lands inside a multi-byte character about as often as
 * the text is not ASCII, which produces exactly the invalid UTF-8 the rule above
 * exists to keep out. `mb_strcut` is the cut that respects both: at most N
 * bytes, never through the middle of a character.
 *
 * It lives in `Support/` rather than beside either caller for the reason every
 * other rule in this package has one home: two implementations of "make this
 * safe to insert" would be two chances for one of them to stop being true.
 */
final class Text
{
    /**
     * Appended to a value a byte cap cut, so a reader knows the document is
     * partial rather than malformed.
     *
     * It lives here rather than beside either caller because two captures cut
     * the same way — an HTTP body and a job payload — and a reader who has
     * learnt what one marker means must not meet a second spelling of it.
     */
    public const TRUNCATED = '… [truncated]';

    /**
     * Strip NUL bytes and anything that is not valid UTF-8.
     *
     * The encoding check is what keeps this cheap: a well-formed string — which
     * is every string in a decoded JSON body — is answered without a conversion.
     */
    public static function clean(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (str_contains($value, "\0")) {
            $value = str_replace("\0", '', $value);
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Dropped rather than substituted: mb_convert_encoding's default is to
        // write a "?" per bad sequence, which turns a mangled string into a
        // longer mangled string. The substitute character is process-global, so
        // it goes back whatever happens next.
        $substitute = mb_substitute_character();

        try {
            mb_substitute_character('none');

            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($substitute);
        }
    }

    /**
     * Cut a string to at most `$bytes` bytes without splitting a character.
     */
    public static function truncate(string $value, int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        return strlen($value) <= $bytes
            ? $value
            : mb_strcut($value, 0, $bytes, 'UTF-8');
    }

    /**
     * {@see clean} over every string in a structure, keys included — a key is a
     * JSON object's worth of text arriving from the page too, and a NUL in one
     * fails the insert exactly as a NUL in a value does.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $value): array
    {
        $clean = [];

        foreach ($value as $key => $item) {
            $clean[is_string($key) ? self::clean($key) : $key] = self::sanitizeValue($item);
        }

        return $clean;
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::clean($value);
        }

        return is_array($value) ? self::sanitize($value) : $value;
    }
}
