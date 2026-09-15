<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Misakstvanu\Prism\Contracts\PrismInternal;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Transport\Transport;

/**
 * Ships one already-built batch envelope off the request entirely (US-038).
 *
 * The flush pipeline queues this instead of sending inline once the buffer exceeds
 * `prism.batch.queue_threshold`: gzipping and POSTing a large payload during `terminating` would
 * still hold the worker process (especially under Octane), so a big batch goes to the queue and
 * the process is released immediately.
 *
 * The envelope is a plain array, so the job serializes cheaply and carries no models.
 * {@see Transport::send()} never throws, so the job can never fail or spam the host's failed_jobs
 * table — a dropped batch is counted, not retried into a loop.
 *
 * It carries {@see PrismInternal} so the job capture listener (US-064) excludes it from capture,
 * and its send runs inside a {@see Recursion::suppress()} scope so any log or exception the send
 * emits in the worker is recognised as the package's own and never captured (US-039).
 */
final class SendBatchJob implements PrismInternal, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $envelope  The versioned batch envelope.
     */
    public function __construct(public readonly array $envelope) {}

    public function handle(Transport $transport): void
    {
        Recursion::suppress(fn () => $transport->send($this->envelope));
    }
}
