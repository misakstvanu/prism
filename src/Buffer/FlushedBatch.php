<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Buffer;

/**
 * Immutable snapshot of an {@see EventBuffer} at flush time (US-037): the
 * buffered events grouped by type, how many were buffered, and how many were
 * dropped because the buffer had reached capacity. US-038 turns this into a
 * wire envelope and ships it; the dropped count travels with the batch so a
 * truncated batch is visible in the console rather than silently short.
 */
final class FlushedBatch
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $events  Buffered events grouped by telemetry type.
     * @param  int  $count  Number of events buffered (excludes dropped).
     * @param  int  $dropped  Number of events dropped at capacity since the last clear.
     */
    public function __construct(
        public readonly array $events,
        public readonly int $count,
        public readonly int $dropped,
    ) {}

    /** True when the flush carries no events. */
    public function isEmpty(): bool
    {
        return $this->count === 0;
    }
}
