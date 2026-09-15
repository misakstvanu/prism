<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Flush;

/**
 * One complete batch envelope waiting in the {@see BatchSpool}, with the number of send attempts
 * it has already survived.
 *
 * A batch enters the spool only from a terminal flush — the end of a request, job or scheduled
 * task — so this always holds a whole unit of work, never the partial contents of something still
 * running. The attempt count rides with it so a batch the drain job could not deliver is spooled
 * again a bounded number of times rather than cycling forever against a dead endpoint.
 */
final class SpooledBatch
{
    /**
     * @param  array<string, mixed>  $envelope  The versioned wire envelope (US-026).
     * @param  int  $attempts  Failed send attempts so far; zero on first spool.
     */
    public function __construct(
        public readonly array $envelope,
        public readonly int $attempts = 0,
    ) {}
}
