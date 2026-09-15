<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Buffer;

use Misakstvanu\Prism\PrismServiceProvider;

/**
 * In-memory, per-process accumulator for the telemetry events captured during
 * a single request or queue job (US-037).
 *
 * Registered as a container singleton by
 * {@see PrismServiceProvider::registerCapture()} so every capture listener
 * (US-042+) writes into one buffer, drained on flush (US-038 ships the batch).
 * Two invariants keep it safe under load:
 *
 *   - Bounded memory. Capacity (config `prism.batch.size`, default 1,000) caps
 *     how many events one request may hold; past it the excess is dropped and
 *     counted rather than accumulated, so a storm of queries or logs can never
 *     grow the buffer without limit. The `dropped` count travels with the
 *     flushed batch so the console can surface it.
 *
 *   - No leakage between runs. A queue worker is long-lived and handles many
 *     jobs; the buffer is cleared at the start of every job (the provider
 *     listens on JobProcessing) and after every flush, so one job never sees
 *     another's events.
 *
 * Adding an event is O(1) — an array append plus a counter bump, no
 * serialization; encoding happens only at flush.
 */
final class EventBuffer
{
    /**
     * Events accumulated so far, grouped by telemetry type — the server groups
     * by type for its bulk insert, so grouping at the source is free.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $events = [];

    /**
     * Number of events currently buffered. A counter so add() and count() stay
     * O(1) instead of walking the grouped arrays.
     */
    private int $count = 0;

    /**
     * Events dropped since the last clear because the buffer was at capacity.
     * Shipped with the batch (US-038) so a truncated batch is visible.
     */
    private int $dropped = 0;

    /**
     * @param  int  $capacity  Maximum events one request may buffer before the
     *                         excess is dropped and counted. Zero or negative
     *                         means unbounded.
     */
    public function __construct(private readonly int $capacity = 1000) {}

    /**
     * Buffer one event under its type. O(1): an array append and a counter
     * bump, no serialization. At capacity the event is dropped and counted
     * instead of stored, which bounds memory.
     *
     * @param  array<string, mixed>  $event
     */
    public function add(string $type, array $event): void
    {
        if ($this->capacity > 0 && $this->count >= $this->capacity) {
            $this->dropped++;

            return;
        }

        $this->events[$type][] = $event;
        $this->count++;
    }

    /**
     * Drain the buffer: snapshot the events and dropped count, then clear so
     * the next request or job starts empty.
     */
    public function flush(): FlushedBatch
    {
        $batch = new FlushedBatch($this->events, $this->count, $this->dropped);

        $this->clear();

        return $batch;
    }

    /**
     * Reset to empty. Called after a flush and at the start of each queue job
     * so events never leak across runs.
     */
    public function clear(): void
    {
        $this->events = [];
        $this->count = 0;
        $this->dropped = 0;
    }

    /**
     * Snapshot of the buffered events, grouped by type, for inspection; callers
     * that consume the buffer should flush().
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function all(): array
    {
        return $this->events;
    }

    /** Number of events currently buffered (excludes dropped). */
    public function count(): int
    {
        return $this->count;
    }

    /** Number of events dropped since the last clear because of the cap. */
    public function dropped(): int
    {
        return $this->dropped;
    }

    /** The configured capacity; zero or negative means unbounded. */
    public function capacity(): int
    {
        return $this->capacity;
    }

    /** True when nothing is buffered — a flush would ship an empty batch. */
    public function isEmpty(): bool
    {
        return $this->count === 0;
    }
}
