<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Browser;

use DateTimeImmutable;

/**
 * The server's answer to a browser's clock (US-008).
 *
 * Every other timestamp in the pipeline is written by the machine that will ship
 * it. A browser event's is written by a page, on a device whose clock is
 * whatever its owner set it to, and clocks in the field are wrong far more often
 * than they are wrong by a little: a phone left in a drawer, a VM resumed from a
 * snapshot and a laptop with the year typed in by hand all produce well-formed
 * ISO-8601 instants days, months or years out.
 *
 * Not cosmetic. `page_views`, `exceptions` and `logs` are **partitioned by day**
 * and carry a per-row TTL, so a row stamped a year ago lands in a partition
 * retention drops on sight — accepted, answered 204, shipped, inserted, gone —
 * while one stamped a year ahead sits in a partition no screen's window reaches
 * and pushes every chart's axis out to meet it. Neither failure says anything
 * anywhere.
 *
 * The correction is possible because the report says when it was sent. `sent_at`
 * and every event timestamp in it are read off the *same* clock, so however
 * wrong that clock is the distance between them is right — and the distance
 * between `sent_at` and the server's clock is the whole of the error, near
 * enough (the residue is one HTTP request's flight time, below the resolution
 * anything here is read at).
 *
 * Two rules, answering different faults:
 *
 *   - **A shift, past a tolerance.** Beyond {@see TOLERANCE_MICROSECONDS} the
 *     clock is out and every timestamp in the report moves by the same offset,
 *     preserving the ordering and the gaps that make a report legible. Inside
 *     the tolerance the offset is *not* clock error — it is the batching delay,
 *     the flight time and the difference between two ordinarily-correct clocks —
 *     so shifting by it would add noise rather than remove it.
 *   - **A clamp, always.** An event cannot have happened after the report
 *     carrying it was sent, which cannot have happened after it arrived; a
 *     timestamp still in the future once the shift is applied is not a clock
 *     that is out, it is nonsense (a page composing its own timestamps, or an
 *     event stamped after `sent_at` was read). It becomes now. A second rule
 *     rather than a special case of the first because it applies to a report
 *     well inside the tolerance too, where no shift ran at all.
 *
 * The instant compared against is passed in rather than read here, so the class
 * is a pure function of its two inputs and the one place that decides what "now"
 * means — {@see BrowserForwarder}, through Carbon, so a test clock moves it —
 * stays the one place.
 */
final class BrowserClock
{
    /**
     * How far out a browser's clock may be before its report is corrected, in
     * microseconds.
     *
     * Five seconds: comfortably more than a report's batching delay plus the
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
            // Relative modification rather than epoch arithmetic: it keeps the
            // sub-second half exact. The offset is carried in microseconds
            // throughout, and a float seconds count would lose its low digits
            // somewhere nobody would look.
            $at = $at->modify(sprintf('%+d microseconds', $this->offset));
        }

        return $at > $this->now ? $this->now : $at;
    }

    /**
     * An instant as whole microseconds since the epoch.
     *
     * Integer for the reason above, and safe to add up: a 64-bit int holds epoch
     * microseconds until well past the year 200000.
     */
    private static function microseconds(DateTimeImmutable $at): int
    {
        return $at->getTimestamp() * 1_000_000 + (int) $at->format('u');
    }
}
