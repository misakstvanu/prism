<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Browser;

use DateTimeImmutable;

/**
 * The server's answer to a browser's clock (US-008).
 *
 * Every other timestamp in the pipeline is written by the machine that will
 * ship it. A browser event's is not: it is written by a page, on a device whose
 * clock is whatever its owner set it to, and clocks in the field are wrong far
 * more often than they are wrong by a little. A phone left in a drawer, a VM
 * resumed from a snapshot and a laptop with the year typed in by hand all
 * produce well-formed ISO-8601 instants that are days, months or years out.
 *
 * That is not a cosmetic problem here. `page_views`, `exceptions` and `logs` are
 * **partitioned by day** and carry a per-row TTL, so a row stamped a year ago
 * lands in a partition retention drops on sight — the report is accepted,
 * answered 204, shipped, inserted, and gone — while one stamped a year ahead
 * sits in a partition no screen's window reaches and pushes every chart's axis
 * out to meet it. Neither failure says anything anywhere.
 *
 * What makes the correction possible is that the report says when it was sent.
 * `sent_at` and every event timestamp in it are read off the *same* clock, so
 * however wrong that clock is, the distance between them is right — and the
 * distance between `sent_at` and the server's own clock is the whole of the
 * error, near enough (the flight time of one HTTP request is the residue, which
 * is smaller than the resolution anything here is read at).
 *
 * Two rules, and they answer different faults:
 *
 *   - **A shift, past a tolerance.** Beyond {@see TOLERANCE_MICROSECONDS} the
 *     clock is out and every timestamp in the report moves by the same offset,
 *     which preserves the ordering and the gaps that make a report legible. The
 *     tolerance exists because inside it the offset is *not* clock error — it is
 *     the batching delay, the flight time and the difference between two
 *     ordinarily-correct clocks — and shifting by that would be adding noise
 *     rather than removing it.
 *   - **A clamp, always.** An event cannot have happened after the report that
 *     carries it was sent, which cannot have happened after it arrived; so a
 *     timestamp still in the future once the shift has been applied is not a
 *     clock that is out, it is a value that is nonsense (a page composing its
 *     own timestamps, or an event stamped after `sent_at` was read). It becomes
 *     now. That is the second rule rather than a special case of the first
 *     because it applies to a report whose clock was well inside the tolerance
 *     too, where no shift ran at all.
 *
 * The instant this compares against is passed in rather than read here, so the
 * class is a pure function of its two inputs and the one place that decides what
 * "now" means — {@see BrowserForwarder}, through Carbon, so a test clock moves
 * it — stays the one place.
 */
final class BrowserClock
{
    /**
     * How far out a browser's clock may be before its report is corrected, in
     * microseconds.
     *
     * Five seconds: comfortably more than a report's own batching delay plus the
     * flight time of the post that carried it, and comfortably less than any
     * clock error worth calling one.
     */
    private const TOLERANCE_MICROSECONDS = 5_000_000;

    /**
     * Microseconds every event timestamp moves by — zero inside the tolerance,
     * which is the ordinary case and costs nothing.
     */
    private readonly int $offset;

    private function __construct(
        private readonly DateTimeImmutable $now,
        int $offset,
    ) {
        $this->offset = abs($offset) > self::TOLERANCE_MICROSECONDS ? $offset : 0;
    }

    /**
     * The correction implied by a report that says it was sent at `$sentAt` and
     * arrived at `$now`.
     */
    public static function correcting(DateTimeImmutable $sentAt, DateTimeImmutable $now): self
    {
        return new self($now, self::microseconds($now) - self::microseconds($sentAt));
    }

    /**
     * One event's instant, as the server believes it.
     */
    public function correct(DateTimeImmutable $at): DateTimeImmutable
    {
        if ($this->offset !== 0) {
            // A relative modification rather than arithmetic on the epoch,
            // because it keeps the sub-second half exact: the offset is carried
            // in microseconds throughout and a float seconds count would lose
            // its low digits somewhere nobody would look.
            $at = $at->modify(sprintf('%+d microseconds', $this->offset));
        }

        return $at > $this->now ? $this->now : $at;
    }

    /**
     * An instant as whole microseconds since the epoch.
     *
     * Integer throughout for the reason above, and safe to add up: a 64-bit int
     * holds epoch microseconds until well past the year 200000.
     */
    private static function microseconds(DateTimeImmutable $at): int
    {
        return $at->getTimestamp() * 1_000_000 + (int) $at->format('u');
    }
}
