<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use Misakstvanu\Prism\PrismServiceProvider;
use stdClass;

/**
 * Redacts sensitive values before an event leaves the process (US-040).
 *
 * A secret must never make the trip to the console: the scrub happens at the
 * source, so a redacted value is never serialized, transmitted or stored. Every
 * capture listener (US-042+) runs the structures it collects — request bodies,
 * query strings, headers, query bindings and job payloads — through {@see
 * scrub()} before buffering them.
 *
 * Matching is by key, **exact and case-insensitive**. That is why the default
 * list enumerates `password` and `password_confirmation` separately rather than
 * relying on one substring: exact matching is predictable and never
 * over-redacts a benign field that merely contains a sensitive word (a
 * `password_strength` score, a `tokenized` flag). The list is additive through
 * config `prism.scrub`, so a host adds any app-specific field with no code.
 *
 * Redaction is recursive: it walks nested arrays and objects to any depth,
 * replacing a matched key's value — whatever its type — with {@see REDACTED}
 * while preserving the key, so the shape of the payload stays legible in the
 * console. Non-matching keys are copied through untouched.
 *
 * Registered as a container singleton by
 * {@see PrismServiceProvider::registerCapture()} with the
 * key set read once from config, so a listener resolves it once and reuses it on
 * every event rather than re-reading config on a hot path.
 */
final class Scrubber
{
    /** The placeholder a scrubbed value is replaced with. */
    public const REDACTED = '[REDACTED]';

    /**
     * The keys to redact, lower-cased and held as a set (`key => true`) so a
     * per-field lookup is O(1) rather than a linear scan of the list.
     *
     * @var array<string, true>
     */
    private array $keys;

    /**
     * @param  iterable<mixed>  $keys  Field names to redact. Compared
     *                                 case-insensitively, so casing here does
     *                                 not matter; non-string entries are ignored.
     */
    public function __construct(iterable $keys)
    {
        $set = [];

        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                $set[strtolower($key)] = true;
            }
        }

        $this->keys = $set;
    }

    /**
     * Redact a structure, returning a scrubbed copy. Recurses through nested
     * arrays and objects; a key matching the scrub list has its value replaced
     * with {@see REDACTED} regardless of that value's type, so a whole nested
     * secret (an array of tokens, a credentials object) is redacted wholesale.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrub(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = $this->scrubValue($value);
        }

        return $clean;
    }

    /** Whether a value should be redacted based on its key. Case-insensitive. */
    public function isSensitive(string $key): bool
    {
        return isset($this->keys[strtolower($key)]);
    }

    /**
     * Recurse into a value. Arrays and objects are walked; scalars, null and
     * resources pass through unchanged. An object is scrubbed into a plain
     * {@see stdClass} of its public properties — the same set `json_encode`
     * would emit — so redaction reaches nested objects without mutating the
     * caller's instance or leaking non-public state.
     */
    private function scrubValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->scrub($value);
        }

        if (is_object($value)) {
            return (object) $this->scrub(get_object_vars($value));
        }

        return $value;
    }
}
