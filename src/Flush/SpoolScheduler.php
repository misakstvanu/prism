<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Flush;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Misakstvanu\Prism\Jobs\DrainSpoolJob;
use Throwable;

/**
 * Keeps exactly one {@see DrainSpoolJob} pending, and guarantees that whatever
 * reaches the {@see BatchSpool} is covered by one.
 *
 * Every terminal flush spools a batch and then calls {@see arm()}. Dispatching a
 * drain per flush would put the queue back where the inline send was — one round
 * trip per request — so the first caller to win a cache lock dispatches a single
 * delayed job and everyone else rides along with it. The lock *is* the "a job is
 * already queued" marker: it is held from dispatch until the job starts, and its
 * expiry (delay plus a grace period) is what re-arms a spool whose drain was lost
 * with its worker.
 *
 * ## Why nothing is left behind
 *
 * The one rule that makes this safe is the order the drain job works in: it
 * {@see disarm()}s **before** it claims. Take any push P, and the first
 * {@see arm()} call A that follows it:
 *
 *   - A wins the lock. It dispatches a drain, which necessarily claims after A,
 *     and therefore after P. P ships.
 *   - A loses the lock. Some drain D held it at the moment of A. D releases the
 *     lock only at the start of its own run, so D's release — and therefore D's
 *     claim, which comes after it — happens after A, and therefore after P. P
 *     ships.
 *
 * Either way a batch that is on the spool has a claim still ahead of it. The
 * reverse order (claim, then release) opens the gap this closes: a push landing
 * between the claim and the release would find the marker still set, skip
 * arming, and sit there until unrelated traffic happened to arm the next drain.
 *
 * The cost of that ordering is the opposite, harmless case: a push during a
 * drain's send window arms a second drain that may find the spool already empty.
 * A no-op drain is cheap; a stranded batch is not.
 */
final class SpoolScheduler
{
    /** Name of the lock standing for "a drain job is already pending". */
    private const PENDING_LOCK = 'prism:spool:pending';

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    /**
     * Ensure a drain job is pending, dispatching one only if none is. Returns
     * true when this call is the one that dispatched it.
     *
     * Called after the batch is on the spool, never before: a job armed ahead of
     * the push could drain and finish before the push landed, leaving the batch
     * with nothing scheduled to collect it.
     */
    public function arm(): bool
    {
        $store = $this->store();

        if (! $store instanceof LockProvider) {
            return false;
        }

        $owner = Str::uuid()->toString();

        try {
            if (! $store->lock(self::PENDING_LOCK, $this->holdSeconds(), $owner)->get()) {
                return false;
            }

            $this->dispatch($owner);

            return true;
        } catch (Throwable $e) {
            Log::debug('Prism could not schedule a spool drain: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Release the pending marker so the next push arms a fresh drain. Called by
     * the drain job as its first act, before it claims — see the class docblock
     * for why that order is the whole guarantee.
     *
     * The owner token is checked on release, so a job whose lock already expired
     * (a worker that sat idle past the grace period, letting another producer arm
     * a replacement) releases nothing and leaves the replacement pending.
     */
    public function disarm(?string $owner): void
    {
        $store = $this->store();

        if (! $store instanceof LockProvider) {
            return;
        }

        try {
            if ($owner === null) {
                $store->lock(self::PENDING_LOCK)->forceRelease();

                return;
            }

            $store->restoreLock(self::PENDING_LOCK, $owner)->release();
        } catch (Throwable $e) {
            Log::debug('Prism could not clear the spool drain marker: '.$e->getMessage());
        }
    }

    /** Dispatch the delayed drain, onto the configured queue when one is named. */
    private function dispatch(string $owner): void
    {
        $pending = DrainSpoolJob::dispatch($owner)->delay($this->delay());

        $queue = $this->config->get('prism.batch.queue');

        if (is_string($queue) && $queue !== '') {
            $pending->onQueue($queue);
        }
    }

    /**
     * Seconds a batch waits on the spool before the drain runs. The window is
     * what coalesces many requests into one ingest POST; zero drains as soon as
     * a worker picks the job up.
     */
    private function delay(): int
    {
        return max(0, (int) $this->config->get('prism.batch.spool.delay', 5));
    }

    /**
     * How long the pending marker is held: the debounce window plus a grace
     * period covering the time a job waits for a free worker. Once it lapses the
     * next push arms a replacement, so a drain lost to a restarted or overloaded
     * worker cannot strand the spool.
     */
    private function holdSeconds(): int
    {
        $grace = max(1, (int) $this->config->get('prism.batch.spool.grace', 60));

        return $this->delay() + $grace;
    }

    /** The store behind the configured spool cache, or null when unresolvable. */
    private function store(): ?Store
    {
        try {
            $store = $this->config->get('prism.batch.spool.store');

            return $this->cache->store(is_string($store) && $store !== '' ? $store : null)->getStore();
        } catch (Throwable $e) {
            Log::debug('Prism spool cache store unavailable: '.$e->getMessage());

            return null;
        }
    }
}
