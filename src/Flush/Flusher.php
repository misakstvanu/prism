<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Flush;

use Illuminate\Contracts\Config\Repository;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Buffer\FlushedBatch;
use Misakstvanu\Prism\Jobs\SendBatchJob;
use Misakstvanu\Prism\Otel\SpanFlush;
use Misakstvanu\Prism\Transport\Transport;

/**
 * Drains the event buffer and ships it, off the request's critical path (US-038). Invoked from the service
 * provider's `terminating` callback, so it runs only after the response has been sent — the host never
 * waits on a flush. The wire envelope is the versioned format the server validates (US-026):
 * `{ v: 1, app, env, replica, sent_at, events: [ { type, ... } ] }`. The buffer groups events by type; the
 * flusher flattens them into one list, stamping each with its group's type so the server can route it to
 * the right table.
 *
 * Sending is inline by default; a batch larger than `prism.batch.queue_threshold` goes to
 * {@see SendBatchJob} instead, so the gzip-and-POST of a large payload does not hold the process. The
 * `sync` strategy forces the inline path regardless — for scripts and tests with no queue worker. The
 * `spool` strategy sends from neither: the envelope goes onto the cache-backed {@see BatchSpool} and one
 * debounced drain job ({@see SpoolScheduler}) ships everything that accumulated — for a host whose ingest
 * endpoint is expensive to reach per request, or is served by the very process doing the sending, where an
 * inline POST deadlocks against itself. It needs a queue worker and a cache store with atomic locks;
 * without either, {@see shouldSpool()} declines and the batch takes the normal path rather than being lost.
 *
 * **The OpenTelemetry tracer is force-flushed first** (US-019). Tail sampling holds a trace's spans until
 * its decision, which by default waits longer than a request lives, so anything still held when this runs
 * would miss the batch its own execution shipped in. {@see SpanFlush} holds the ordering rationale and the
 * "is there a span lane at all" gate.
 */
final class Flusher
{
    public function __construct(
        private readonly Repository $config,
        private readonly EventBuffer $buffer,
        private readonly Transport $transport,
        private readonly BatchSpool $spool,
        private readonly SpoolScheduler $scheduler,
        private readonly SpanFlush $spans,
    ) {}

    /**
     * Ship whatever is buffered. A no-op on an empty buffer, so a request that produced no telemetry costs
     * nothing at terminate. Every caller is a terminal hook — the app's `terminating` callback, a job's
     * terminal queue event, a scheduled task's terminal event — so the batch always describes finished
     * work, which lets the spool hand it to another process with no risk of shipping the middle of a
     * request.
     */
    public function flush(): void
    {
        // BEFORE the empty check, not after it: an execution whose only telemetry is a span the
        // tail-sampling decision still holds would otherwise return on an empty buffer and force nothing
        // out.
        $this->spans->flush();

        if ($this->buffer->isEmpty()) {
            return;
        }

        $batch = $this->buffer->flush();
        $envelope = $this->envelope($batch);

        // Spool first: only a refusal (no lock support, no worker, a lock the producer could not take in
        // time) falls through to sending here, so declining is always safe.
        if ($this->shouldSpool() && $this->spool->push($envelope)) {
            $this->scheduler->arm();

            return;
        }

        if ($this->shouldQueue($batch)) {
            SendBatchJob::dispatch($envelope)->onQueue($this->queue());

            return;
        }

        $this->transport->send($envelope);
    }

    /**
     * Whether this flush should be spooled for a drain job rather than sent. Three things have to hold:
     * the strategy asks for it; the cache store offers atomic locks, which keep concurrent producers and
     * the drain from losing a batch between them; and the queue connection is not `sync`, on which the
     * "deferred" drain would run inline inside the terminating callback — the blocking send spooling
     * exists to avoid.
     */
    private function shouldSpool(): bool
    {
        if ($this->config->get('prism.batch.flush', 'terminate') !== 'spool') {
            return false;
        }

        return $this->spool->usable() && $this->spool->drainable();
    }

    /**
     * A batch is queued when it exceeds the configured threshold and the flush
     * strategy is not `sync`. A threshold of zero (or `sync`) keeps every flush
     * inline.
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
     * flattened out of their per-type groups into one list, each stamped with its
     * type so the server can route it.
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
