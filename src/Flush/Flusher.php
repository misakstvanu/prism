<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Flush;

use Illuminate\Contracts\Config\Repository;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Buffer\FlushedBatch;
use Misakstvanu\Prism\Jobs\SendBatchJob;
use Misakstvanu\Prism\Transport\Transport;

/**
 * Drains the event buffer and ships it, off the request's critical path
 * (US-038). Invoked from the service provider's `terminating` callback so it
 * runs only after the response has been sent to the client — the host never
 * waits on a flush.
 *
 * The wire envelope is the versioned format the server validates (US-026):
 * `{ v: 1, app, env, replica, sent_at, events: [ { type, ... } ] }`. The buffer
 * groups events by type; the flusher flattens them into one list, stamping each
 * with its group's type so the server can route it to the right table.
 *
 * Sending is inline by default, but a batch larger than
 * `prism.batch.queue_threshold` is handed to {@see SendBatchJob} instead so the
 * gzip-and-POST of a large payload does not hold the process. The `sync` flush
 * strategy forces the inline path regardless — it exists for scripts and tests
 * with no queue worker.
 */
final class Flusher
{
    public function __construct(
        private readonly Repository $config,
        private readonly EventBuffer $buffer,
        private readonly Transport $transport,
    ) {}

    /**
     * Ship whatever is buffered. A no-op when the buffer is empty, so a request
     * that produced no telemetry costs nothing at terminate.
     */
    public function flush(): void
    {
        if ($this->buffer->isEmpty()) {
            return;
        }

        $batch = $this->buffer->flush();
        $envelope = $this->envelope($batch);

        if ($this->shouldQueue($batch)) {
            SendBatchJob::dispatch($envelope)->onQueue($this->queue());

            return;
        }

        $this->transport->send($envelope);
    }

    /**
     * A batch is queued when it exceeds the configured threshold and the flush
     * strategy is not `sync`. A threshold of zero (or the `sync` strategy)
     * keeps every flush inline.
     */
    private function shouldQueue(FlushedBatch $batch): bool
    {
        if ($this->config->get('prism.batch.flush', 'terminate') === 'sync') {
            return false;
        }

        $threshold = (int) $this->config->get('prism.batch.queue_threshold', 0);

        return $threshold > 0 && $batch->count > $threshold;
    }

    /** The queue the send job is dispatched onto; null uses the default queue. */
    private function queue(): ?string
    {
        $queue = $this->config->get('prism.batch.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    /**
     * Build the versioned wire envelope from a flushed batch. Events are
     * flattened out of their per-type groups into one list, each stamped with
     * its type so the server can route it.
     *
     * @return array<string, mixed>
     */
    private function envelope(FlushedBatch $batch): array
    {
        $events = [];

        foreach ($batch->events as $type => $group) {
            foreach ($group as $event) {
                $event['type'] = $type;
                $events[] = $event;
            }
        }

        return [
            'v' => 1,
            'app' => (string) $this->config->get('prism.app'),
            'env' => (string) $this->config->get('prism.environment'),
            'replica' => (string) $this->config->get('prism.replica'),
            'sent_at' => now()->toIso8601String(),
            'events' => $events,
        ];
    }
}
