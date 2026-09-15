<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Nightwatch;

use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Nightwatch\Contracts\Ingest;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Delivery\RecipientRecorder;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Http\Middleware\CaptureHttpBodies;
use Misakstvanu\Prism\Otel\SpanLane;
use Misakstvanu\Prism\Otel\SpanLineage;
use Misakstvanu\Prism\Queue\JobPayloadRecorder;
use Misakstvanu\Prism\Support\Recursion;
use Throwable;

/**
 * Prism's own pipeline standing behind Nightwatch's ingest interface. Nightwatch's sensors hand every record to
 * whatever implements `Contracts\Ingest`, whose own implementation writes them over a TCP socket to the
 * `nightwatch:agent` daemon; Prism replaces it (US-004 swaps it onto `Core::$ingest` at boot), so records go into
 * the buffer and leave over Prism's own transport, and no agent process is required — the socket implementation is
 * never constructed, never called and never reached. Nothing below changes: {@see RecordTranslator} makes a record
 * a Prism event, {@see EventBuffer} accumulates them, {@see Flusher} builds the versioned envelope and picks the
 * ship strategy (`terminate` / `sync` / `spool`, per `prism.batch.flush`), and the transport POSTs it.
 *
 * **`flush()` means DISCARD and `digest()` means SEND** — backwards from every other flush here. Upstream,
 * `Core::finishExecution()` is `$this->sampling ? $this->ingest->digest() : $this->ingest->flush()`, so `flush()`
 * answers an execution the sampler *rejected* and must ship nothing at all. `flush()` as "send" would bill the host
 * for every execution sampling meant to drop; `digest()` as "discard" would ship nothing ever, the pipeline looking
 * dead with no error anywhere. `PrismIngestTest` asserts the sampled-out case first, on a real `Core`.
 *
 *   - A record with no honest Prism event is dropped and counted, never fatal: a signal Prism has no table for
 *     (`user`, an identity upsert), a shape version past the translator, or a throw out of it. {@see dropped()}
 *     counts those; {@see EventBuffer::dropped()} counts what the buffer's capacity refused.
 *   - Shipping never throws into the host: every send runs inside a {@see Recursion::suppress()} scope, so a log
 *     line or exception the flush emits is the package's own rather than the next batch's contents, and a failure is
 *     logged at debug and swallowed. Otherwise Nightwatch catches it and routes it into its own
 *     unrecoverable-exception handling, reporting to a service that is not listening.
 *   - An exception on `prism.ignore.exceptions` is refused here, only because there is nowhere earlier: Nightwatch
 *     has a reject callback for every record type it buffers except this one; every other dimension of the ignore
 *     list never reaches this class at all, {@see RejectRules}.
 *   - The `request_id` of a `request` record is stamped here, the one field the pure translator cannot know: the
 *     id lives on the `Core`'s execution state, which only the swap site can reach; {@see stampExecutionId()}.
 *   - A record the span lane has already reported is dropped here, not in the engine (US-018): both engines watch a
 *     query and an outgoing HTTP call, so one must give way or work is stored twice, {@see SpanLane} deciding which.
 *     Deliberately *not* through an engine `reject*` callback: `$executionState->queries++` lives inside the sensor's
 *     own resolver, which they return before, so rejecting a query upstream reports a request that ran forty as
 *     running none. {@see superseded()}
 *   - Every record's `trace_id` is rewritten to OpenTelemetry's (US-017) — here rather than at each sensor
 *     because this is the single funnel every record passes through; see {@see stampTraceId()}.
 */
final class PrismIngest implements Ingest
{
    /**
     * Event types belonging to no execution, onto which {@see stampExecutionId()} must therefore not stamp one.
     * Today only Prism's own `replica_metric` (US-013): CPU, memory, uptime and queue depth are facts about the
     * *process*, sampled on a wall-clock interval, and the execution finishing when the interval elapsed had nothing
     * to do with them — stamping its id on would file a replica's health under a request, a correlation the console
     * would draw. Every Nightwatch record is deliberately absent even though most carry an `execution_id`: where one
     * arrives blank, the current execution is the right answer and the stamp is a recovery.
     *
     * @var list<string>
     */
    private const EXECUTIONLESS_TYPES = ['replica_metric'];

    /**
     * Whether a buffer at capacity should ship rather than start losing records. Set per execution by
     * `Core::sample()`: true when the execution is being kept, false when it has already been sampled out (the
     * batch is going to be discarded anyway, so paying to send it is worse than dropping it). Defaults to true,
     * matching upstream.
     */
    private bool $digestWhenBufferIsFull = true;

    /** Records dropped since the last drain because there was nothing to translate them into. */
    private int $dropped = 0;

    /**
     * @param  EventBuffer  $buffer  The shared per-process buffer every Prism capture writes into.
     * @param  RecordTranslator  $translator  The pure record → event mapping.
     * @param  Closure(EventBuffer): Flusher  $flusher  A factory rather than one flusher because {@see writeNow()}
     *                                                  ships a batch deliberately not the shared buffer's.
     * @param  (Closure(): string)|null  $executionId  The id of the execution being recorded, read fresh per
     *                                                 record; see {@see stampExecutionId()}.
     * @param  RejectRules|null  $rules  `prism.ignore.*` in the engine's vocabulary. Only the exception dimension is
     *                                   answered here; every other has an upstream reject hook, {@see RejectRules}.
     * @param  SpanLineage|null  $lineage  Where OpenTelemetry is: which trace this execution belongs to
     *                                     ({@see stampTraceId()}) and which span is open, so a signal the engine
     *                                     reports as a record but Prism draws as a span joins the tree
     *                                     ({@see stampSpanLineage()}). With none, a record keeps the trace and
     *                                     identity it arrived with.
     * @param  SpanLane|null  $lane  Which signals the OpenTelemetry span lane produces, and so which of the engine's
     *                               records duplicate a span it has already emitted; see {@see superseded()}. With
     *                               none, nothing is superseded and every record the engine raises is kept.
     * @param  (Closure(): (array<string, string>|null))|null  $bodies  Headers and the two bodies this exchange carried, keyed
     *                                                                  by the `requests` column each lands in, held by the
     *                                                                  middleware that saw the response; {@see stampBodies()}.
     *                                                                  Read fresh per record, like the execution id; none here
     *                                                                  means a request row has none.
     * @param  (Closure(string): (string|null))|null  $jobPayload  What a job was asked to do, looked up by the job id the
     *                                                             record carries and released as it is answered, held by the
     *                                                             queue-event listeners; see {@see stampJobPayload()}. With
     *                                                             none, a job row carries no payload.
     * @param  (Closure(string, string): (array<string, string>|null))|null  $recipients  Who an outbound message was addressed to,
     *                                                                                    looked up by the signal and class the record carries
     *                                                                                    and released as it is answered, held by the
     *                                                                                    sending-event listeners; {@see stampRecipients()}. With
     *                                                                                    none, a mail or notification row has no names.
     */
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly RecordTranslator $translator,
        private readonly Closure $flusher,
        private readonly ?Closure $executionId = null,
        private readonly ?RejectRules $rules = null,
        private readonly ?SpanLineage $lineage = null,
        private readonly ?SpanLane $lane = null,
        private readonly ?Closure $bodies = null,
        private readonly ?Closure $jobPayload = null,
        private readonly ?Closure $recipients = null,
    ) {}

    /**
     * Buffer one record for the current execution — the path all but two of Nightwatch's signals take: translated
     * and appended to the shared buffer, an untranslatable one dropped and counted. At capacity the batch is
     * shipped rather than left to lose records, but only while `shouldDigestWhenBufferIsFull(true)` holds, i.e. only
     * while the execution is one the sampler kept; with it false the buffer's own capacity rule applies and the
     * excess is counted by {@see EventBuffer::dropped()}.
     *
     * @param  array<mixed>  $record
     */
    public function write(array $record): void
    {
        /** @var array<string, mixed> $record */
        $event = $this->translate($record);

        if ($event === null) {
            return;
        }

        $this->buffer->add($event['type'], $event);

        if ($this->digestWhenBufferIsFull && $this->bufferIsFull()) {
            $this->digest();
        }
    }

    /**
     * Ship one record on its own, right now, without touching the shared buffer. Nightwatch takes this path for an
     * unhandled exception and for a fatal error — `flush()` first, discarding what the dying execution had buffered,
     * then `writeNow()` with the fatal record — so it must work in a process about to end and must not depend on a
     * `digest()` that never comes. "Immediately" means *on this call*, not at the end of the execution; the
     * configured ship strategy still applies, since a host's reason for choosing `spool` (an ingest endpoint too
     * expensive to reach inline) does not stop being true for an exception.
     *
     * @param  array<mixed>  $record
     */
    public function writeNow(array $record): void
    {
        /** @var array<string, mixed> $record */
        $event = $this->translate($record);

        if ($event === null) {
            return;
        }

        // A buffer of its own, unbounded because it holds exactly one event. The shared buffer is left exactly as
        // it was — what "bypassing the buffer" means, and what keeps a fatal record from dragging along an
        // execution's worth of context the discard before it threw away.
        $batch = new EventBuffer(capacity: 0);
        $batch->add($event['type'], $event);

        $this->ship($batch);
    }

    /**
     * SEND. Hand the buffered batch to the flusher, which builds the envelope and applies `prism.batch.flush`. Called
     * by `Core::finishExecution()` for a sampled-in execution and by {@see write()} on a buffer filling mid-execution.
     */
    public function digest(): void
    {
        $this->ship($this->buffer);

        $this->dropped = 0;
    }

    /**
     * DISCARD. Throw the buffered batch away and send nothing. Called by `Core::finishExecution()` for a sampled-out
     * execution and by `Core::flush()` when an execution is reset; read the class docblock before making it send.
     */
    public function flush(): void
    {
        $this->buffer->clear();

        $this->dropped = 0;
    }

    /**
     * Reach the ingest. A no-op: there is no agent to reach, the point of this class. Nightwatch calls it only from
     * its own `nightwatch:status` command, whose question ("is the daemon up?") has no meaning here.
     */
    public function ping(): void
    {
        //
    }

    /**
     * Deprecated upstream in favour of {@see shouldDigestWhenBufferIsFull()}, which it delegates to — as the socket
     * ingest does, so a caller still on the old name behaves identically on either implementation.
     */
    public function shouldDigest(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull($bool);
    }

    /** @see $digestWhenBufferIsFull */
    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->digestWhenBufferIsFull = $bool;
    }

    /**
     * Records dropped since the last drain for want of an honest event: an unknown signal, a shape version that moved
     * on, a translator that threw. Separate from {@see EventBuffer::dropped()}, counting those refused for capacity.
     */
    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * Translate one record, counting the untranslatable rather than ending the host's request: the translator is pure
     * and total for every shape upstream emits today, but a record changed underneath it must not take a request down.
     *
     * @param  array<string, mixed>  $record
     * @return array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}|null
     */
    private function translate(array $record): ?array
    {
        // An ignored exception, refused before anything is built from it. Deliberately not counted as a drop:
        // `dropped()` reports a fault worth noticing, and a configured exclusion is a decision, not a fault.
        if ($this->rules?->rejectsRecord($record) === true) {
            return null;
        }

        // A signal the span lane has already reported, as a span with a real parent. Refused before translation
        // and — like an ignored record — deliberately not counted as a drop: a decision, not a fault.
        if ($this->superseded($record)) {
            return null;
        }

        try {
            $event = $this->translator->translate($record);
        } catch (Throwable) {
            $event = null;
        }

        if ($event === null) {
            $this->dropped++;

            return null;
        }

        return $this->stampRecipients($this->stampJobPayload($this->stampBodies($this->stampSpanLineage($this->stampExecutionId($this->stampTraceId($event))))));
    }

    /**
     * Put onto a `job` event what the job was asked to do. The capture engine reports a job by name, id, queue,
     * connection and outcome and nothing about its arguments, so nothing says which run of a class failed, while
     * Prism's `jobs` table has had a `payload` column, and the failed-job screen a panel on it, since long before
     * the engine arrived; {@see JobPayloadRecorder} fills them. Both halves carry it: dispatch and attempt are two
     * records, often in two processes, each holding the payload from the queue event it saw, so the failed-jobs
     * detail has it whichever row it found. Three refusals, {@see stampBodies()}'s three. `job` only: a request, a
     * command and a scheduled task have no payload of this kind, and a log line raised inside a job is not the job.
     * Nothing held, nothing stamped: the lookup answers null when the payload was never captured (switch off, or
     * another process saw the queue event), so the key is absent and the column keeps its `DEFAULT ''`. A record
     * that already names one wins.
     *
     * @param  array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}  $event
     * @return array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}
     */
    private function stampJobPayload(array $event): array
    {
        if ($event['type'] !== 'job' || $this->jobPayload === null) {
            return $event;
        }

        /** @var mixed $uuid */
        $uuid = $event['payload']['uuid'] ?? null;

        if (! is_string($uuid) || $uuid === '' || isset($event['payload']['payload'])) {
            return $event;
        }

        try {
            $payload = ($this->jobPayload)($uuid);
        } catch (Throwable) {
            return $event;
        }

        if (is_string($payload) && $payload !== '') {
            $event['payload']['payload'] = $payload;
        }

        return $event;
    }

    /**
     * Put onto a `mail` or `notification` event who it was addressed to. The capture engine reports a message by
     * mailer, class, subject and three recipient *counts*, and a notification by channel and class, so neither row
     * names anybody. {@see RecipientRecorder} holds the names from the framework's sending events, keyed by the
     * record's own `class` field, the only thing the two sides share — a message carries no id of any kind — and
     * within one execution the framework sends in order, so the holder is a per-class FIFO and each record claims
     * the entry pushed for it; {@see RecipientRecorder} keeps both keys spelt the same way. Three refusals,
     * {@see stampBodies()}'s three. `mail` and `notification` only: every other signal is an execution or something
     * raised inside one, none addressed to anybody. Nothing held, nothing stamped: the lookup answers null when the
     * addresses were never captured (switch off, or another process saw the send), so the keys are absent and the
     * columns keep their `DEFAULT ''`; an empty value is skipped on its own besides, leaving an on-demand
     * notifiable's absent id out rather than storing a bare `#`. And a record that already names them wins.
     *
     * @param  array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}  $event
     * @return array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}
     */
    private function stampRecipients(array $event): array
    {
        if ($this->recipients === null) {
            return $event;
        }

        $type = $event['type'];

        if ($type !== RecipientRecorder::MAIL && $type !== RecipientRecorder::NOTIFICATION) {
            return $event;
        }

        /** @var mixed $class */
        $class = $event['payload']['class'] ?? '';

        if (! is_string($class)) {
            return $event;
        }

        try {
            $columns = ($this->recipients)($type, $class);
        } catch (Throwable) {
            return $event;
        }

        if ($columns === null) {
            return $event;
        }

        foreach ($columns as $column => $value) {
            if ($value !== '' && ! isset($event['payload'][$column])) {
                $event['payload'][$column] = $value;
            }
        }

        return $event;
    }

    /**
     * Put the request headers and the two bodies onto the `request` event. The capture engine cannot usably supply
     * any of the three: its record has a `payload` field filled only for a 500 and landing in no Prism column, it
     * has no notion of a response body at all (a `RequestRecord` carries `responseSize` and nothing else), and its
     * header bag is redacted in a wording of its own rather than through `prism.scrub`. So all three are read by
     * {@see CaptureHttpBodies} while the response is in hand and held until here, the one point that sees the record
     * they belong to. Three refusals. `request` only: a command, a job attempt and a scheduled task are executions
     * too and none of them exchanged an HTTP body, and a `log` or a `query` inside a request is not the request
     * either. Nothing recorded, nothing stamped: the recorder answers null when every half is empty (capture
     * switched off, a content type nobody can read, a body that was not there) and each empty value is skipped on
     * its own besides, so a key absent from the payload leaves the column at its own `DEFAULT ''` rather than
     * asserting an empty body was observed. And a payload that already names them wins: nothing produces that
     * today, but such a record knows more than this seam does.
     *
     * @param  array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}  $event
     * @return array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}
     */
    private function stampBodies(array $event): array
    {
        if ($event['type'] !== 'request' || $this->bodies === null) {
            return $event;
        }

        try {
            $bodies = ($this->bodies)();
        } catch (Throwable) {
            return $event;
        }

        if ($bodies === null) {
            return $event;
        }

        foreach ($bodies as $column => $body) {
            if ($body !== '' && ! isset($event['payload'][$column])) {
                $event['payload'][$column] = $body;
            }
        }

        return $event;
    }

    /**
     * Whether this record describes work the OpenTelemetry span lane has already reported, with a real parent and a
     * real place in the tree. Keyed on the record's own `t`, not the translated event's type, which is
     * load-bearing: an outgoing request and a cache event both translate to Prism's `span` while only one is
     * superseded — OTel emits no cache span, so the engine's `cache-event` record is that lane's only producer and
     * dropping it, which testing the translated type would do, would leave a hole in every waterfall. Refused
     * before translation: nothing is gained by building an event about to be thrown away, the record array itself
     * having been built by the sensor, the price of a correct per-request counter and what {@see SpanLane} explains.
     *
     * @param  array<string, mixed>  $record
     */
    private function superseded(array $record): bool
    {
        if ($this->lane === null) {
            return false;
        }

        /** @var mixed $type */
        $type = $record['t'] ?? null;

        return is_string($type) && $this->lane->supersedes($type);
    }

    /**
     * Re-key one record onto the OpenTelemetry trace this execution belongs to. The capture engine mints a UUID of
     * its own and the SDK a W3C id (32 hex); a request and the spans, logs, queries and exceptions it produced are
     * correlated by that column on every Prism detail screen, so the two have to be one id — and OTel is the one
     * that propagates, over `traceparent` to the next service and into a queued job's payload. Two rules, both
     * refusals. No OTel trace, no rewrite: a host with the SDK off, a console command with no instrumentation, a
     * record raised before the first span opens — the engine's own id stands, so nothing is ever left unkeyed, and
     * {@see SpanLineage::traceId()} answers null rather than inventing one, reading wider than the active span
     * because the engine writes its `request` record from `terminate()`, after upstream's middleware has already
     * ended the request span. And a record that names no trace is not given one: today Prism's own `replica_metric`
     * and only it, for the reason {@see EXECUTIONLESS_TYPES} gives — the mirror of that rule.
     *
     * @param  array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}  $event
     * @return array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}
     */
    private function stampTraceId(array $event): array
    {
        if ($event['trace_id'] === '') {
            return $event;
        }

        try {
            $traceId = $this->lineage?->traceId();
        } catch (Throwable) {
            return $event;
        }

        if ($traceId === null) {
            return $event;
        }

        $event['trace_id'] = $traceId;

        return $event;
    }

    /**
     * Hang a record-shaped span off whatever OpenTelemetry span is open right now. Two of the engine's signals are
     * drawn as spans by Prism alone: a cache event (OTel emits none — upstream's cache instrumentation only calls
     * `addEvent()`, and a span *event* has no column here) and an outgoing request. The translator is pure and
     * cannot see what is open, so it leaves them with no identity and no lineage; this is the first point that can,
     * and it must be *here* rather than at the flush, because the record is written the moment the operation
     * completes and the active span a moment later is a different one. {@see SpanLineage::stampSpan()} holds the
     * rules, including the one easy to miss: the event adopts the active span's `trace_id` too, or it names a
     * parent that is not in its own trace and the server's tree assembly drops it to a root.
     *
     * @param  array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}  $event
     * @return array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}
     */
    private function stampSpanLineage(array $event): array
    {
        if ($event['type'] !== 'span' || $this->lineage === null) {
            return $event;
        }

        return $this->lineage->stampSpan($event);
    }

    /**
     * Fill in a `request_id` the translator could not know. **A `request` record carries no `execution_id`** — it
     * *is* the execution, and every other record points back at it — so the translator, seeing one record at a time,
     * leaves it empty; the id is the execution *state*'s, which the ingest can reach and the translator cannot.
     * Without this the `requests` table's `request_id` is blank for every row and a request-detail screen cannot
     * find its own children. Read per record, not captured once: a queue worker and an Octane worker both hand the
     * same `Core` many executions in a row, each with an id of its own. Blank stays blank when there is no resolver
     * (the shape `PrismIngestTest` exercises the translation with), when reading it fails — an empty column is a
     * gap, where a throw here would cost the host a request — or when the event belongs to no execution
     * ({@see EXECUTIONLESS_TYPES}).
     *
     * @param  array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}  $event
     * @return array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}
     */
    private function stampExecutionId(array $event): array
    {
        if ($event['request_id'] !== '' || $this->executionId === null) {
            return $event;
        }

        if (in_array($event['type'], self::EXECUTIONLESS_TYPES, true)) {
            return $event;
        }

        try {
            $event['request_id'] = ($this->executionId)();
        } catch (Throwable) {
            //
        }

        return $event;
    }

    /**
     * Ship whatever a buffer holds, never throwing and never being captured as telemetry itself. An empty buffer
     * costs nothing: the flusher would decline it anyway, and not building one keeps a no-op flush free.
     */
    private function ship(EventBuffer $buffer): void
    {
        if ($buffer->isEmpty()) {
            return;
        }

        Recursion::suppress(function () use ($buffer): void {
            try {
                ($this->flusher)($buffer)->flush();
            } catch (Throwable $e) {
                Log::debug('Prism flush failed: '.$e->getMessage());
            }
        });
    }

    /**
     * Whether the shared buffer has reached the capacity `prism.batch.size` gave it. A non-positive capacity is
     * unbounded, so it is never full and the mid-execution ship never fires.
     */
    private function bufferIsFull(): bool
    {
        $capacity = $this->buffer->capacity();

        return $capacity > 0 && $this->buffer->count() >= $capacity;
    }
}
