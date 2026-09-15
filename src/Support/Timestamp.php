<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use DateTimeImmutable;
use DateTimeZone;
use Misakstvanu\Prism\Browser\BrowserReport;
use Misakstvanu\Prism\Nightwatch\RecordTranslator;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Throwable;

/**
 * The one wire format for a telemetry event's timestamp.
 *
 * Every event in a Prism batch carries an ISO-8601 instant in UTC with
 * microsecond precision — the shape the server's ingest endpoint validates and
 * ClickHouse's `DateTime64(3)` columns parse. Three producers build one:
 * {@see RecordTranslator} from a capture-engine record's float microtime,
 * {@see PrismSpanProcessor} from an OpenTelemetry span's epoch nanoseconds, and
 * {@see fromDateTime} from an instant {@see parse} has already read out of a
 * browser report — one format by construction rather than three copies of one
 * `gmdate()` line that can drift a digit apart.
 *
 * Deliberately not `Carbon`: this runs once per captured event on the host's
 * hot path, and a `DateTimeImmutable` allocated per span buys nothing — nothing
 * here needs a calendar, only a formatter. Same reason the microseconds are
 * carried as an integer rather than rounded through a float: a span measured at
 * 400µs has to survive the conversion.
 *
 * {@see parse} is the one exception and the one reader: a browser report
 * (US-006) arrives with instants a *page* wrote, so they must be read before
 * anything can be said about them — whether they are legible at all, and by how
 * much the browser's clock is out. That answer is a calendar's, so it is the one
 * place here that allocates one. Reading and writing live in one class so what
 * the pipeline emits and what it accepts cannot drift apart.
 */
final class Timestamp
{
    /**
     * Format a float microtime — seconds since the epoch with a fractional
     * part, the shape every capture-engine record carries. A value that is not
     * a finite positive number has no honest instant to become and answers
     * null, read by the caller as "drop this record" rather than shipping an
     * event stamped at the epoch.
     */
    public static function fromMicrotime(mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = (float) $value;

        if (! is_finite($value) || $value <= 0.0) {
            return null;
        }

        $seconds = (int) floor($value);
        $microseconds = (int) round(($value - $seconds) * 1_000_000);

        return self::format($seconds, $microseconds);
    }

    /**
     * Format epoch nanoseconds, the unit every OpenTelemetry clock reads in.
     * Integer division throughout: a float would lose the low digits of a
     * nanosecond count long before it lost the microseconds this keeps.
     */
    public static function fromEpochNanos(int $nanos): ?string
    {
        if ($nanos <= 0) {
            return null;
        }

        return self::format(
            intdiv($nanos, 1_000_000_000),
            intdiv($nanos % 1_000_000_000, 1_000),
        );
    }

    /**
     * Format an instant already read into a calendar — today only a browser
     * report, whose timestamps arrive as strings a page wrote and come back out
     * of {@see parse} as objects. The microseconds are taken off the object
     * rather than through `format('...u')` on the whole string, so the result is
     * assembled by the same {@see format} every other producer here uses: one
     * wire shape, one place it is spelled.
     */
    public static function fromDateTime(DateTimeImmutable $at): string
    {
        return self::format($at->getTimestamp(), (int) $at->format('u'));
    }

    /**
     * Read an instant a browser wrote, or null when it is not one.
     *
     * The SDK sends `Date.prototype.toISOString()`, but this is the one input to
     * the pipeline a *page* composes, so what arrives is whatever a page put
     * there. PHP's own parser decides — it reads every ISO-8601 shape a browser
     * produces, with or without fractional seconds and with any offset — and a
     * value it cannot read answers null, which {@see BrowserReport} reads as
     * "drop this event" rather than stamping it at the epoch or at now: a
     * browser event with a made-up timestamp lands in the middle of a chart and
     * is worse than an event that never arrived.
     *
     * A blank string is refused up front because `DateTimeImmutable('')` is
     * *now*, which would silently turn a missing timestamp into a plausible one.
     */
    public static function parse(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Assemble the string, carrying a microsecond count that rounded up to a
     * whole second into the seconds rather than emitting `.1000000`.
     */
    private static function format(int $seconds, int $microseconds): string
    {
        if ($microseconds >= 1_000_000) {
            $seconds++;
            $microseconds -= 1_000_000;
        }

        return gmdate('Y-m-d\TH:i:s', $seconds).'.'.str_pad((string) $microseconds, 6, '0', STR_PAD_LEFT).'+00:00';
    }
}
