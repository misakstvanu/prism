<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Misakstvanu\Prism\Contracts\PrismInternal;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Flush\SpoolScheduler;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Transport\Transport;

/**
 * Ships everything on the {@see BatchSpool} in one pass (US-038, `spool` flush
 * strategy).
 *
 * One of these is pending at a time — {@see SpoolScheduler} holds a cache lock
 * from dispatch until this job starts, so however many requests, jobs and
 * scheduled tasks spooled a batch during the debounce window, they are delivered
 * by a single drain rather than a round trip each. The job carries the lock's
 * owner token so it releases its own marker and never a replacement armed after
 * its own expired.
 *
 * The order of the first two statements in {@see handle()} is load-bearing, and
 * is argued in full on {@see SpoolScheduler}: **disarm, then claim.** A batch
 * spooled between those two points is either taken by this drain or arms the
 * next one — in both orders of the race it ships. Reversing them would let a
 * batch land after the claim but while the marker still stood, with nothing
 * scheduled to collect it.
 *
 * A batch that fails to send goes back on the spool one attempt older, and a
 * fresh drain is armed for it, so a momentary outage costs a delay rather than
 * the telemetry; {@see BatchSpool::restore()} discards a batch that has burned
 * through `max_attempts` so an endpoint that is simply wrong cannot keep the
 * queue busy forever.
 *
 * It carries {@see PrismInternal} so job capture excludes it (US-039 AC2), and
 * the whole run is a {@see Recursion::suppress()} scope so the cache reads,
 * queries and log lines the drain itself emits are recognised as the package's
 * own — otherwise draining the spool would refill it.
 */
final class DrainSpoolJob implements PrismInternal, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt. {@see Transport::send()} never throws, so a failure is
     * reported rather than raised, and the retry lives on the spool (the batch is
     * re-spooled with a fresh drain armed) instead of in the queue's retry
     * machinery — which would re-run this job with a claim that is already empty.
     */
    public int $tries = 1;

    /**
     * @param  string|null  $owner  Owner token of the pending-marker lock this
     *                              job was dispatched under; null releases
     *                              whatever marker is set (a hand-dispatched
     *                              drain).
     */
    public function __construct(public readonly ?string $owner = null) {}

    public function handle(SpoolScheduler $scheduler, BatchSpool $spool, Transport $transport): void
    {
        Recursion::suppress(function () use ($scheduler, $spool, $transport): void {
            // Reopen scheduling first: from here on, anything spooled arms a new
            // drain, so the claim below cannot strand a batch that lands while
            // this one is still sending.
            $scheduler->disarm($this->owner);

            $batches = $spool->claim();

            if ($batches === []) {
                return;
            }

            $failed = [];

            foreach ($batches as $batch) {
                if (! $transport->send($batch->envelope)) {
                    $failed[] = $batch;
                }
            }

            if ($failed === []) {
                return;
            }

            // Re-spool before arming, exactly as a producer does — the retry has
            // to be visible to the drain that will collect it.
            if ($spool->restore($failed) > 0) {
                $scheduler->arm();
            }
        });
    }
}
