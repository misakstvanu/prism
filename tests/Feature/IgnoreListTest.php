<?php

use Misakstvanu\Prism\Support\IgnoreList;

/**
 * The `prism.ignore.*` matcher itself (US-039), and nothing else.
 *
 * Every dimension it answers used to be applied by a capture listener of
 * Prism's own, and this file used to drive those listeners. Since US-021 there
 * are none: `RejectRules` says the same lists in the capture engine's
 * vocabulary and `SpanLane` applies them to the span lane, so what each
 * dimension *does* is asserted end-to-end against the real engine in
 * `tests/Host/NightwatchRejectRulesTest.php` and, for the internal-marker
 * boundary, in `RecursionGuardTest`. What is left here is the matcher's own
 * semantics — the part both of those depend on and neither would localise a
 * failure in.
 */
it('normalises a raw config value into a pattern list', function (): void {
    // Config is untyped, so a published file can hold anything at all.
    expect(IgnoreList::patterns(['a', 'b']))->toBe(['a', 'b'])
        ->and(IgnoreList::patterns('not-an-array'))->toBe([])
        ->and(IgnoreList::patterns(null))->toBe([])
        // Non-string entries are dropped, and the survivors re-keyed into a list.
        ->and(IgnoreList::patterns(['a', 42, null, 'b']))->toBe(['a', 'b']);
});

it('matches any candidate against any pattern, with wildcards', function (): void {
    expect(IgnoreList::matches(['api/*'], 'api/ingest'))->toBeTrue()
        ->and(IgnoreList::matches(['api/*'], 'web/ingest'))->toBeFalse()
        // No wildcard means an exact match, not a prefix one.
        ->and(IgnoreList::matches(['api'], 'api/ingest'))->toBeFalse()
        // Any candidate matching any pattern is a match.
        ->and(IgnoreList::matches(['clickhouse'], 'other', 'clickhouse'))->toBeTrue()
        // A backslashed class name survives the pattern escaping.
        ->and(IgnoreList::matches(['App\Events\*'], 'App\Events\TelemetryUpdated'))->toBeTrue()
        ->and(IgnoreList::matches([], 'anything'))->toBeFalse()
        // An empty candidate must not be silenced by a bare wildcard meant for
        // something else — a missing host is not "every host".
        ->and(IgnoreList::matches(['*'], ''))->toBeFalse();
});

/**
 * The `Str::is` → PCRE conversion (US-010).
 *
 * `laravel/nightwatch`'s cache-key rejection is the one matcher in either
 * engine that speaks regex: it runs `@preg_match($pattern, $key)` and falls
 * back to a literal string comparison only when the pattern will not compile.
 * A raw `prism:*` does not compile — no delimiters — so it is compared to the
 * key as a literal and silences nothing at all, with no error anywhere. These
 * assert the conversion against the matcher's own semantics rather than against
 * a spelling, and pair each one with the `Str::is` verdict it has to reproduce.
 */
it('converts a wildcard pattern into a regex the engine matcher accepts', function (): void {
    $pattern = IgnoreList::toRegex('prism:*');

    // `preg_match` answers `false` for a pattern that will not compile, so a
    // 1 here is also the proof that upstream's literal-string fallback — the
    // branch that silences nothing — is not the one being taken.
    expect(preg_match($pattern, 'prism:usage:1:2026-07'))->toBe(1)
        ->and(preg_match($pattern, 'orders:recent'))->toBe(0)
        // The verdict it has to reproduce.
        ->and(IgnoreList::matches(['prism:*'], 'prism:usage:1:2026-07'))->toBeTrue();
});

it('anchors a pattern with no wildcard at both ends, so it matches exactly', function (): void {
    $pattern = IgnoreList::toRegex('prism');

    expect(preg_match($pattern, 'prism'))->toBe(1)
        ->and(preg_match($pattern, 'prism:usage'))->toBe(0)
        ->and(preg_match($pattern, 'my-prism'))->toBe(0)
        ->and(IgnoreList::matches(['prism'], 'prism:usage'))->toBeFalse();
});

it('quotes a pattern that would otherwise be read as regex syntax', function (): void {
    // A cache key is a string, not an expression: the dots and slashes a host
    // writes are literal, and an unquoted `.` would match any character.
    $pattern = IgnoreList::toRegex('laravel_cache:user.1/session');

    expect(preg_match($pattern, 'laravel_cache:user.1/session'))->toBe(1)
        ->and(preg_match($pattern, 'laravel_cacheXuserZ1/session'))->toBe(0);
});

it('handles a wildcard in the middle and a bare wildcard', function (): void {
    expect(preg_match(IgnoreList::toRegex('cache:*:lock'), 'cache:orders:lock'))->toBe(1)
        ->and(preg_match(IgnoreList::toRegex('cache:*:lock'), 'cache:orders:key'))->toBe(0)
        ->and(preg_match(IgnoreList::toRegex('*'), 'anything at all'))->toBe(1);
});
