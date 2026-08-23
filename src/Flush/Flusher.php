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
 *
 * The `spool` strategy sends from neither: the envelope goes onto the
 * cache-backed {@see BatchSpool} and one debounced drain job
 * ({@see SpoolScheduler}) ships everything that accumulated. That is what a host
 * wants when the ingest endpoint is expensive to reach per request — or is served
 * by the very process doing the sending, where an inline POST deadlocks against
 * itself. It needs a queue worker and a cache store with atomic locks; without
 * either, {@see shouldSpool()} declines and the batch takes the normal path
 * rather than being lost.
 *
 * **The OpenTelemetry tracer is force-flushed first** (US-019). Tail sampling
 * holds a trace's spans until its decision, which by default waits longer than
 * a request lives, so anything still held when this runs would miss the batch
 * its own execution shipped in. See {@see SpanFlush}, which is where the
 * ordering is explained and where the "is there a span lane at all" gate lives.
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
     * Ship whatever is buffered. A no-op when the buffer is empty, so a request
     * that produced no telemetry costs nothing at terminate.
     *
     * Every caller is a terminal hook — the app's `terminating` callback, a job's
     * terminal queue event, a scheduled task's terminal event — so the batch this
     * builds always describes finished work. That is what lets the spool hand a
     * batch to another process without any risk of shipping the middle of a
     * request.
     */
    public function flush(): void
    {
        // BEFORE the empty check, not after it: an execution whose only
        // telemetry is a span the tail-sampling decision is still holding would
        // otherwise return on an empty buffer and force nothing out at all.
        $this->spans->flush();

        if ($this->buffer->isEmpty()) {
            return;
        }

        $batch = $this->buffer->flush();
        $envelope = $this->envelope($batch);

        // Spool first: only a refusal (no lock support, no worker, a lock the
        // producer could not take in time) falls through to sending it here, so
        // declining is always safe.
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
     * Whether this flush should be spooled for a drain job rather than sent.
     *
     * Three things have to hold. The strategy has to ask for it; the cache store
     * has to offer atomic locks, which is what keeps concurrent producers and the
     * drain from losing a batch between them; and the queue connection must not
     * be `sync`, on which the "deferred" drain would run inline inside the
     * terminating callback — precisely the blocking send spooling exists to
     * avoid.
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
