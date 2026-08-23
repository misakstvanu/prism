<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use Misakstvanu\Prism\Nightwatch\RecordTranslator;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;

/**
 * The one wire format for a telemetry event's timestamp.
 *
 * Every event in a Prism batch carries an ISO-8601 instant in UTC with
 * microsecond precision, which is the shape the server's ingest endpoint
 * validates and ClickHouse's `DateTime64(3)` columns parse. Two producers now
 * build one: {@see RecordTranslator} from a capture-engine record's float
 * microtime, and {@see PrismSpanProcessor} from an OpenTelemetry span's epoch
 * nanoseconds. They are the same format by construction rather than by two
 * copies of one `gmdate()` line that can drift a digit apart.
 *
 * Deliberately not `Carbon`: this runs once per captured event on the host's
 * hot path, and a `DateTimeImmutable` allocated per span is a cost with no
 * corresponding benefit — nothing here needs a calendar, only a formatter. It
 * is also why the microseconds are carried as an integer rather than rounded
 * through a float: a span measured at 400µs has to survive the conversion.
 */
final class Timestamp
{
    /**
     * Format a float microtime — seconds since the epoch, with a fractional
     * part — the shape every capture-engine record carries.
     *
     * A value that is not a finite positive number has no honest instant to
     * become and answers null, which the caller reads as "drop this record"
     * rather than shipping an event stamped at the epoch.
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
     *
     * Integer division throughout: a float would lose the low digits of a
     * nanosecond count long before it lost the microseconds this actually
     * keeps.
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
