<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Flush;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Misakstvanu\Prism\Console\CheckCommand;
use Misakstvanu\Prism\Jobs\DrainSpoolJob;
use Throwable;

/**
 * A shared, cache-backed holding area for finished batches, so the process that produced telemetry never
 * ships it (US-038, `spool` flush strategy). The inline strategies send from whichever process filled the
 * buffer: a round trip to the ingest host per request, and an outright deadlock when that host is served by
 * the very process doing the sending — a single-worker dev server answering its own POST. Here a terminal
 * flush writes its envelope and returns at once, and one debounced {@see DrainSpoolJob} ships the
 * accumulation ({@see SpoolScheduler} guarantees exactly one is pending).
 *
 * Two invariants make a concurrent reader safe. **Only whole units are spooled**: {@see push()} is reached
 * only from a terminal flush (the app's `terminating` callback, a job's or a scheduled task's terminal
 * event), so nothing half-produced is ever visible. **Only whole envelopes are visible**: the payload is
 * written under its own key *before* that key joins the index, a reader's only route to it, so a drain can
 * never read a segment still being written, however the two interleave. The index — a short list of segment
 * keys — is guarded by a cache lock every append and claim takes; payloads stay outside it, so the lock
 * covers a read-append-write of a few strings, not megabytes of events, and producers barely contend even
 * under load. Everything degrades rather than throws: no atomic locks (the one hard requirement), a lock
 * not taken inside its wait, an unencodable envelope — each returns false, so {@see Flusher} sends the
 * batch itself and it is never dropped on the floor.
 */
final class BatchSpool
{
    /** Cache key holding the list of spooled segment keys, in arrival order. */
    private const INDEX_KEY = 'prism:spool:index';

    /** Prefix for the per-batch payload keys the index points at. */
    private const SEGMENT_PREFIX = 'prism:spool:batch:';

    /** Name of the lock serialising every read-modify-write of the index. */
    private const INDEX_LOCK = 'prism:spool:index';

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    /**
     * Whether the configured cache store can back a spool at all. Atomic locks are the one hard requirement
     * — without them two producers could interleave a read-modify-write of the index and lose a batch — so a
     * store with none, or an unresolvable name, reports unusable and the caller keeps its existing send path.
     */
    public function usable(): bool
    {
        return $this->store() instanceof LockProvider;
    }

    /**
     * Whether a drain job can run later. A spool is only emptied by a queued job, so on the `sync` driver
     * the "deferred" drain runs inline in the terminating callback that spooled it — the blocking send
     * spooling exists to avoid. Reported by {@see CheckCommand}, honoured by {@see Flusher}, which sends
     * normally instead.
     */
    public function drainable(): bool
    {
        $connection = $this->config->get('queue.default');

        if (! is_string($connection) || $connection === '') {
            return false;
        }

        return $this->config->get("queue.connections.{$connection}.driver") !== 'sync';
    }

    /**
     * Spool one finished batch. False when it could not be stored — the caller's cue to send it inline
     * instead; a false here must never mean a discarded batch.
     *
     * @param  array<string, mixed>  $envelope  A complete wire envelope.
     * @param  int  $attempts  Failed sends this envelope has already survived.
     */
    public function push(array $envelope, int $attempts = 0): bool
    {
        $repository = $this->repository();
        $encoded = $this->encode($envelope, $attempts);

        if ($repository === null || $encoded === null) {
            return false;
        }

        $key = self::SEGMENT_PREFIX.Str::uuid()->toString();

        // Written before it is indexed: the index is a reader's only path to this key, so
        // publishing the key last makes a partially-written payload unreachable by construction.
        $repository->put($key, $encoded, $this->ttl());

        $max = $this->maxBatches();

        /** @var list<string> $evicted */
        $evicted = [];

        $indexed = $this->withIndex(static function (array $index) use ($key, $max, &$evicted): array {
            $index[] = $key;

            // Past the cap the oldest go, not the newest: a backing-up spool means an unreachable
            // ingest host, and fresh telemetry beats a stale batch nobody could deliver.
            if ($max > 0 && count($index) > $max) {
                $overflow = count($index) - $max;
                $evicted = array_slice($index, 0, $overflow);
                $index = array_slice($index, $overflow);
            }

            return array_values($index);
        });

        if (! $indexed) {
            // Unreachable without an index entry, so drop it now rather than leave it to expire.
            $repository->forget($key);

            return false;
        }

        // Deleted outside the lock — they are already unreachable, so nothing waits on this.
        foreach ($evicted as $stale) {
            $repository->forget($stale);
        }

        if ($evicted !== []) {
            Log::debug('Prism spool at capacity: dropped '.count($evicted).' undelivered batch(es).');
        }

        return true;
    }

    /**
     * Take everything spooled so far, atomically. The index is read and cleared inside the same lock every
     * {@see push()} takes, so a claim sees a batch or none of it, and two drains cannot both take the same
     * batch. Payloads are read after the lock is released: a cleared index makes those keys private here.
     *
     * @return list<SpooledBatch>
     */
    public function claim(): array
    {
        $repository = $this->repository();

        if ($repository === null) {
            return [];
        }

        /** @var list<string> $keys */
        $keys = [];

        $claimed = $this->withIndex(static function (array $index) use (&$keys): array {
            $keys = $index;

            return [];
        });

        if (! $claimed) {
            return [];
        }

        $batches = [];

        foreach ($keys as $key) {
            /** @var mixed $encoded */
            $encoded = $repository->pull($key);

            $batch = is_string($encoded) ? $this->decode($encoded) : null;

            if ($batch !== null) {
                $batches[] = $batch;
            }
        }

        return $batches;
    }

    /**
     * Put batches the drain could not deliver back on the spool, one attempt older. One that has exhausted
     * `max_attempts` is dropped and logged instead — a permanently unreachable endpoint must not leave the
     * queue cycling one payload forever.
     *
     * @param  list<SpooledBatch>  $batches
     * @return int Number of batches actually re-spooled.
     */
    public function restore(array $batches): int
    {
        $max = $this->maxAttempts();
        $kept = 0;
        $dropped = 0;

        foreach ($batches as $batch) {
            $attempts = $batch->attempts + 1;

            if ($max > 0 && $attempts >= $max) {
                $dropped++;

                continue;
            }

            if ($this->push($batch->envelope, $attempts)) {
                $kept++;

                continue;
            }

            $dropped++;
        }

        if ($dropped > 0) {
            Log::debug('Prism spool discarded '.$dropped.' batch(es) after repeated send failures.');
        }

        return $kept;
    }

    /**
     * How many batches are waiting. A lock-free read, for reporting ({@see CheckCommand}): a count
     * taken while a push is in flight may be one behind — never a reason to hold the lock.
     */
    public function pending(): int
    {
        return count($this->index());
    }

    /**
     * Discard everything spooled. Not part of the flush path — for tests, and for an operator
     * abandoning an undeliverable backlog.
     */
    public function purge(): void
    {
        $repository = $this->repository();

        if ($repository === null) {
            return;
        }

        $keys = [];

        $this->withIndex(static function (array $index) use (&$keys): array {
            $keys = $index;

            return [];
        });

        foreach ($keys as $key) {
            $repository->forget($key);
        }
    }

    /**
     * Run $mutator against the current index under the index lock and store what it returns. The lock is
     * what makes a concurrent append and claim safe; the body is deliberately tiny (a list of key strings)
     * so the wait stays short. False when the lock could not be taken inside its wait, or the store cannot
     * lock at all — the caller falls back rather than risk a lost or duplicated batch.
     *
     * @param  callable(list<string>): list<string>  $mutator
     */
    private function withIndex(callable $mutator): bool
    {
        $repository = $this->repository();
        $store = $this->store();

        if ($repository === null || ! $store instanceof LockProvider) {
            return false;
        }

        try {
            $lock = $store->lock(self::INDEX_LOCK, $this->lockSeconds());

            return (bool) $lock->block($this->lockWait(), function () use ($repository, $mutator): bool {
                $next = $mutator($this->index());

                if ($next === []) {
                    $repository->forget(self::INDEX_KEY);
                } else {
                    $repository->put(self::INDEX_KEY, $next, $this->ttl());
                }

                return true;
            });
        } catch (Throwable $e) {
            // A lock timeout, or any store fault: both mean "not spooled", so the caller sends inline.
            Log::debug('Prism spool index unavailable: '.$e->getMessage());

            return false;
        }
    }

    /**
     * The current index, normalised. Config and cache are untyped, so a value that is not a list of
     * strings reads as an empty spool rather than being coerced into keys nobody wrote.
     *
     * @return list<string>
     */
    private function index(): array
    {
        $repository = $this->repository();

        if ($repository === null) {
            return [];
        }

        /** @var mixed $index */
        $index = $repository->get(self::INDEX_KEY);

        if (! is_array($index)) {
            return [];
        }

        return array_values(array_filter($index, 'is_string'));
    }

    /**
     * Encode one batch for storage. JSON, not PHP serialization, so one process's segment is readable by
     * another on a different build of the host; an unencodable payload fails before indexing, not at drain
     * time.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function encode(array $envelope, int $attempts): ?string
    {
        try {
            return json_encode([
                'attempts' => $attempts,
                'envelope' => $envelope,
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::debug('Prism could not encode a batch for the spool: '.$e->getMessage());

            return null;
        }
    }

    /** Decode a stored segment, or null when it is unreadable (and so unusable). */
    private function decode(string $encoded): ?SpooledBatch
    {
        /** @var mixed $decoded */
        $decoded = json_decode($encoded, true);

        if (! is_array($decoded) || ! isset($decoded['envelope']) || ! is_array($decoded['envelope'])) {
            return null;
        }

        /** @var array<string, mixed> $envelope */
        $envelope = $decoded['envelope'];

        $attempts = $decoded['attempts'] ?? 0;

        return new SpooledBatch($envelope, is_numeric($attempts) ? (int) $attempts : 0);
    }

    /** The cache repository backing the spool, or null when it cannot be resolved. */
    private function repository(): ?CacheRepository
    {
        try {
            $store = $this->config->get('prism.batch.spool.store');

            return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
        } catch (Throwable $e) {
            Log::debug('Prism spool cache store unavailable: '.$e->getMessage());

            return null;
        }
    }

    /** The underlying store, tested for lock support before any spool operation. */
    private function store(): ?Store
    {
        return $this->repository()?->getStore();
    }

    /** Seconds a spooled batch (and the index) survives before the cache expires it. */
    private function ttl(): int
    {
        return max(1, (int) $this->config->get('prism.batch.spool.ttl', 900));
    }

    /** Maximum batches held at once; zero is unbounded. */
    private function maxBatches(): int
    {
        return (int) $this->config->get('prism.batch.spool.max_batches', 500);
    }

    /** Send attempts a batch gets before it is discarded; zero retries forever. */
    private function maxAttempts(): int
    {
        return (int) $this->config->get('prism.batch.spool.max_attempts', 3);
    }

    /** How long the index lock is held before it self-expires. */
    private function lockSeconds(): int
    {
        return max(1, (int) $this->config->get('prism.batch.spool.lock_seconds', 5));
    }

    /** How long a producer waits for the index lock before giving up. */
    private function lockWait(): int
    {
        return max(1, (int) $this->config->get('prism.batch.spool.lock_wait', 3));
    }
}
