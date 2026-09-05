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
 * Prism's own pipeline standing behind Nightwatch's ingest interface.
 *
 * Nightwatch's sensors do not know where their records go: they hand each one
 * to whatever implements `Contracts\Ingest`, and its own implementation writes
 * them over a TCP socket to the `nightwatch:agent` daemon. Prism replaces that
 * object (US-004 swaps it onto `Core::$ingest` at boot) so the records go into
 * the buffer Prism has always used and leave over the transport Prism has
 * always used. That is the whole reason no agent process is required: the
 * socket implementation is never constructed, never called and never reached.
 *
 * Nothing below this class changes. {@see RecordTranslator} turns a record into
 * a Prism event, {@see EventBuffer} accumulates them, {@see Flusher} builds the
 * versioned envelope and picks the ship strategy (`terminate` / `sync` /
 * `spool`, per `prism.batch.flush`), and the transport POSTs it. This class is
 * only the adapter between one vocabulary and the other.
 *
 * **`flush()` means DISCARD and `digest()` means SEND.** That is the single
 * most important thing about this interface and it reads backwards from every
 * other flush in this package. Upstream, `Core::finishExecution()` is literally
 * `$this->sampling ? $this->ingest->digest() : $this->ingest->flush()` — so
 * `flush()` is what an execution the sampler *rejected* is answered with, and
 * it must ship nothing at all. Implementing it as "send" bills the host for
 * every execution sampling was supposed to drop; implementing `digest()` as
 * "discard" ships nothing ever and the pipeline looks dead with no error
 * anywhere. `PrismIngestTest` asserts the sampled-out case first, on a real
 * `Core`, for exactly that reason.
 *
 * Four further shapes are worth knowing:
 *
 *   - **A record with no honest Prism event is dropped and counted, never
 *     fatal.** That covers a Nightwatch signal Prism has no table for (`user`,
 *     an identity upsert), a record shape whose version has moved past the
 *     translator, and any throw out of the translator itself. {@see dropped()}
 *     is the count since the last drain, alongside {@see EventBuffer::dropped()}
 *     which counts the ones the buffer's capacity refused.
 *
 *   - **Shipping never throws into the host.** Every send runs inside a
 *     {@see Recursion::suppress()} scope, so a log line or exception the flush
 *     itself emits is recognised as the package's own instead of becoming the
 *     next batch's contents, and a failure is logged at debug and swallowed.
 *     Nightwatch would otherwise catch it and route it through its own
 *     unrecoverable-exception handling, which reports to a service that is not
 *     listening.
 *
 *   - **An exception on `prism.ignore.exceptions` is refused here**, and only
 *     because there is nowhere earlier: Nightwatch has a reject callback for
 *     every record type it buffers except this one. Every other dimension of
 *     the ignore list is answered by an upstream hook and never reaches this
 *     class at all — see {@see RejectRules}.
 *
 *   - **The `request_id` of a `request` record is stamped here**, because it is
 *     the one field the pure translator cannot know: a request record carries
 *     no `execution_id` — it *is* the execution — while the id lives on the
 *     `Core`'s execution state, which only the swap site can reach. See
 *     {@see stampExecutionId()}.
 *
 *   - **A record the span lane has already reported is dropped here, not in
 *     the engine** (US-018). Both engines watch a query and an outgoing HTTP
 *     call, so one of the two producers has to give way or the same work is
 *     stored twice; {@see SpanLane} decides which, and this is where the losing
 *     record is refused. Deliberately *not* through one of the engine's
 *     `reject*` callbacks: those return before the sensor's own resolver runs,
 *     and the resolver is where `$executionState->queries++` lives — so
 *     rejecting a query upstream would also report a request that ran forty of
 *     them as having run none. See {@see superseded()}.
 *
 *   - **Every record's `trace_id` is rewritten to OpenTelemetry's** (US-017).
 *     Two engines mint trace ids — the capture engine a UUID of its own, the
 *     SDK a 32-hex W3C id — and every Prism detail screen correlates a request
 *     with its spans, logs, queries and exceptions by that column, so a console
 *     shows a third of a trace if they disagree. OTel wins because its id is
 *     the one that travels to the next service and into a queued job. This is
 *     the single funnel every record passes through, which is why the rewrite
 *     is here rather than at each sensor; see {@see stampTraceId()}.
 */
final class PrismIngest implements Ingest
{
    /**
     * Event types that belong to no execution, and onto which
     * {@see stampExecutionId()} must therefore not stamp one.
     *
     * Today that is Prism's own `replica_metric` (US-013): CPU, memory, uptime
     * and queue depth are facts about the *process*, sampled on a wall-clock
     * interval, and the execution that happened to be finishing when the
     * interval elapsed had nothing to do with them. Stamping its id on would
     * file a replica's health under a request, which is a correlation the
     * console would then draw.
     *
     * Every Nightwatch record is deliberately absent from this list even though
     * most carry an `execution_id` already: where one arrives blank, the current
     * execution really is the right answer and the stamp is a recovery.
     *
     * @var list<string>
     */
    private const EXECUTIONLESS_TYPES = ['replica_metric'];

    /**
     * Whether a buffer that has reached capacity should ship rather than start
     * losing records. Set per execution by `Core::sample()`: true when the
     * execution is being kept, false when it has already been sampled out (in
     * which case the batch is going to be discarded anyway, so paying to send
     * it would be worse than dropping it). Defaults to true, matching upstream.
     */
    private bool $digestWhenBufferIsFull = true;

    /** Records dropped since the last drain because there was nothing to translate them into. */
    private int $dropped = 0;

    /**
     * @param  EventBuffer  $buffer  The shared per-process buffer every Prism capture writes into.
     * @param  RecordTranslator  $translator  The pure record → event mapping.
     * @param  Closure(EventBuffer): Flusher  $flusher  Builds a flusher over a given buffer. A
     *                                                  factory rather than one flusher because
     *                                                  {@see writeNow()} ships a batch that is
     *                                                  deliberately not the shared buffer's.
     * @param  (Closure(): string)|null  $executionId  The id of the execution being recorded, read
     *                                                 fresh per record; see {@see stampExecutionId()}.
     * @param  RejectRules|null  $rules  `prism.ignore.*` in the engine's vocabulary. Only the
     *                                   exception dimension is answered here — every other one has
     *                                   an upstream reject hook and never reaches this class; see
     *                                   {@see RejectRules}.
     * @param  SpanLineage|null  $lineage  Where OpenTelemetry is: which trace this execution
     *                                     belongs to ({@see stampTraceId()}) and which span is
     *                                     open, so a signal the engine reports as a record but
     *                                     Prism draws as a span joins the span tree
     *                                     ({@see stampSpanLineage()}). With none, a record keeps
     *                                     the trace and the identity it arrived with.
     * @param  SpanLane|null  $lane  Which signals the OpenTelemetry span lane produces, and so
     *                               which of the engine's records are duplicates of a span it has
     *                               already emitted; see {@see superseded()}. With none, nothing is
     *                               superseded and every record the engine raises is kept.
     * @param  (Closure(): (array<string, string>|null))|null  $bodies  What this HTTP exchange carried — its
     *                                                                  headers and its two bodies, keyed by the `requests`
     *                                                                  column each lands in — held by the middleware that saw
     *                                                                  the response; see {@see stampBodies()}. Read fresh per
     *                                                                  record, for the reason the execution id is. With none, a
     *                                                                  request row carries none of them.
     * @param  (Closure(string): (string|null))|null  $jobPayload  What a job was asked to do, looked up by the
     *                                                             job id the record carries and released as it is
     *                                                             answered; held by the listeners that saw the
     *                                                             queue events. See {@see stampJobPayload()}. With
     *                                                             none, a job row carries no payload.
     * @param  (Closure(string, string): (array<string, string>|null))|null  $recipients  Who an outbound message
     *                                                                                    was addressed to, looked up by the signal and the
     *                                                                                    class the record carries and released as it is
     *                                                                                    answered; held by the listeners that saw the
     *                                                                                    sending events. See {@see stampRecipients()}. With
     *                                                                                    none, a mail or notification row carries no names.
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
     * Buffer one record for the current execution.
     *
     * This is the path all but two of Nightwatch's signals take. The record is
     * translated and appended to the shared buffer; an untranslatable one is
     * dropped and counted.
     *
     * When the buffer reaches capacity the batch is shipped rather than left to
     * lose records — but only while `shouldDigestWhenBufferIsFull(true)` holds,
     * which is to say only while the execution is one the sampler kept. With it
     * false the buffer's own capacity rule applies and the excess is counted by
     * {@see EventBuffer::dropped()}.
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
     * Ship one record on its own, right now, without touching the shared buffer.
     *
     * Nightwatch takes this path for an unhandled exception and for a fatal
     * error — where it first calls `flush()` to discard whatever the dying
     * execution had buffered, then `writeNow()`s the fatal record. So this has
     * to work in a process that is about to end, and it must not depend on a
     * `digest()` that will never come.
     *
     * "Immediately" means *on this call* rather than at the end of the
     * execution; the host's configured ship strategy still applies, because the
     * reason a host chose `spool` (an ingest endpoint too expensive to reach
     * inline) does not stop being true because the record is an exception.
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

        // A buffer of its own, unbounded because it holds exactly one event —
        // the shared buffer is left exactly as it was, which is what "bypassing
        // the buffer" means and what keeps a fatal record from dragging along an
        // execution's worth of context that the discard before it threw away.
        $batch = new EventBuffer(capacity: 0);
        $batch->add($event['type'], $event);

        $this->ship($batch);
    }

    /**
     * SEND. Hand the buffered batch to the flusher, which builds the envelope
     * and applies `prism.batch.flush` exactly as it always has.
     *
     * Called by `Core::finishExecution()` for an execution the sampler kept,
     * and by {@see write()} when the buffer fills mid-execution.
     */
    public function digest(): void
    {
        $this->ship($this->buffer);

        $this->dropped = 0;
    }

    /**
     * DISCARD. Throw the buffered batch away and send nothing.
     *
     * Called by `Core::finishExecution()` for an execution the sampler
     * rejected, and by `Core::flush()` when an execution is reset. Read the
     * class docblock before changing this to send anything.
     */
    public function flush(): void
    {
        $this->buffer->clear();

        $this->dropped = 0;
    }

    /**
     * Reach the ingest. A no-op: there is no agent to reach, which is the point
     * of this class. Nightwatch calls it only from its own `nightwatch:status`
     * command, whose question ("is the daemon up?") has no meaning here.
     */
    public function ping(): void
    {
        //
    }

    /**
     * Deprecated upstream in favour of {@see shouldDigestWhenBufferIsFull()},
     * which it delegates to — the same delegation the socket ingest does, so a
     * caller still on the old name behaves identically on either implementation.
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
     * Records dropped since the last drain because there was nothing honest to
     * translate them into — an unknown signal, a shape version that has moved
     * on, or a translator that threw. Separate from
     * {@see EventBuffer::dropped()}, which counts the ones that were understood
     * and refused for capacity.
     */
    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * Translate one record, counting anything untranslatable rather than
     * letting it end the host's request. The translator is pure and total for
     * every shape upstream emits today, but a record that has changed
     * underneath it must not be able to take a request down.
     *
     * @param  array<string, mixed>  $record
     * @return array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}|null
     */
    private function translate(array $record): ?array
    {
        // An ignored exception is refused before anything is built from it.
        // Deliberately not counted as a drop: `dropped()` reports records that
        // had no honest event to become, which is a fault worth noticing, and
        // a configured exclusion is a decision rather than a fault.
        if ($this->rules?->rejectsRecord($record) === true) {
            return null;
        }

        // A signal the span lane has already reported, as a span with a real
        // parent. Refused before translation and — like an ignored record —
        // deliberately not counted as a drop: it is a decision, not a fault.
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
     * Put onto a `job` event what the job was asked to do.
     *
     * The capture engine reports a job by name, id, queue, connection and
     * outcome and says nothing about its arguments — so `backup:tenant 41` and
     * `backup:tenant 7` are one row shape with no way to tell which tenant
     * failed. Prism's `jobs` table has had a `payload` column and the
     * failed-job screen a panel drawn from it since long before the engine
     * arrived; {@see JobPayloadRecorder} is what fills them, and this is the
     * one point that sees both the held payload and the record it belongs to.
     *
     * **Both halves of a job's life carry it.** A dispatch and an attempt are
     * two records — often in two processes — and each holds the payload from
     * the queue event it saw, so the failed-jobs table's detail has it whichever
     * row it found.
     *
     * Three refusals, each of them a way of not making something up:
     *
     *   - **`job` only.** A request, a command and a scheduled task have no
     *     payload of this kind, and a log line raised inside a job is not the
     *     job.
     *   - **Nothing held, nothing stamped.** The key is looked up and answers
     *     null for a job whose payload was never captured — the switch is off,
     *     the process that saw the queue event was not this one — and the key
     *     is then absent from the payload, so the column keeps its own
     *     `DEFAULT ''` rather than asserting an empty payload was observed.
     *   - **A record that already names one wins**, for {@see stampBodies()}'s
     *     reason: nothing produces that today, but a record carrying its own
     *     answer knows more than this seam does.
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
     * Put onto a `mail` or `notification` event who it was addressed to.
     *
     * The capture engine reports a message by mailer, class, subject and three
     * recipient *counts*, and a notification by channel and class — so "this
     * went to three people" is the most either row can say, and "did the
     * customer get it" is unanswerable. {@see RecipientRecorder} holds the
     * names from the framework's sending events, and this is the one point that
     * sees both what was held and the record it belongs to.
     *
     * **Keyed by the record's own `class` field**, which is the only thing the
     * two sides share: a message carries no id of any kind. Within one
     * execution the framework sends in order, so the holder is a per-class FIFO
     * and each record claims the entry pushed for it — see
     * {@see RecipientRecorder} for what keeps the two keys spelt the same way.
     *
     * Three refusals, each of them a way of not making something up:
     *
     *   - **`mail` and `notification` only.** Every other signal is either an
     *     execution or something raised inside one, and none of them was
     *     addressed to anybody.
     *   - **Nothing held, nothing stamped.** The lookup answers null for a send
     *     whose addresses were never captured — the switch is off, the process
     *     that saw the sending event was not this one — and the keys are then
     *     absent from the payload, so the columns keep their own `DEFAULT ''`
     *     rather than asserting an empty list was observed. An empty value is
     *     skipped on its own besides, which is what leaves an on-demand
     *     notifiable's absent id out rather than storing a bare `#`.
     *   - **A record that already names them wins**, for {@see stampBodies()}'s
     *     reason: nothing produces that today, but a record carrying its own
     *     answer knows more than this seam does.
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
     * Put the request headers and the two bodies onto the `request` event.
     *
     * The capture engine cannot usably supply any of the three. Its record has
     * a `payload` field that is filled only for a 500 and lands in no Prism
     * column; it has no notion of a response body at all — a `RequestRecord`
     * carries `responseSize` and nothing else about what was sent back; and its
     * header bag is redacted in a wording of its own rather than through
     * `prism.scrub`. So all three are read by {@see CaptureHttpBodies} while
     * the response is in hand and held until here, which is the one point that
     * sees the record they belong to.
     *
     * Three refusals, each of them a way of not making something up:
     *
     *   - **`request` only.** A command, a job attempt and a scheduled task are
     *     executions too, and none of them exchanged an HTTP body; stamping a
     *     blank set onto them would add three columns' worth of nothing to every
     *     row. A `log` or a `query` inside a request is not the request either.
     *   - **Nothing recorded, nothing stamped.** The recorder answers null when
     *     every half is empty — capture switched off, a content type nobody
     *     can read, a body that was not there — and each empty value is skipped
     *     on its own besides, so a key absent from the payload leaves the column
     *     at its own `DEFAULT ''` rather than asserting an empty body was
     *     observed.
     *   - **A payload that already names them wins.** Nothing produces that
     *     today, but a record carrying its own answer is a record that knows
     *     more than this seam does.
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
     * Whether this record describes work the OpenTelemetry span lane has
     * already reported, with a real parent and a real place in the tree.
     *
     * Keyed on the record's own `t` rather than on the translated event's type,
     * and that is load-bearing: an outgoing request and a cache event both
     * translate to Prism's `span`, while only one of them is superseded. OTel
     * emits no cache span at all, so the engine's `cache-event` record is the
     * cache lane's only producer and dropping it would leave a hole in every
     * waterfall — which testing the translated type would do.
     *
     * Refused before translation because there is nothing to gain by building
     * an event that is about to be thrown away. The record array itself was
     * already built by the sensor, which is the price of a correct per-request
     * counter and what {@see SpanLane} explains.
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
     * Re-key one record onto the OpenTelemetry trace this execution belongs to.
     *
     * The capture engine mints a trace id of its own (a UUID) and the SDK mints
     * a W3C one (32 hex characters); a request and the spans, logs, queries and
     * exceptions it produced are correlated by that column on every detail
     * screen Prism has, so the two have to be one id and OTel is the one that
     * propagates — over `traceparent` to the next service, and into a queued
     * job's payload.
     *
     * Two rules, and both of them are refusals:
     *
     *   - **No OTel trace, no rewrite.** A host with the SDK switched off, a
     *     console command with no instrumentation, a record raised before the
     *     first span opens: the engine's own id stands, so nothing is ever left
     *     unkeyed. {@see SpanLineage::traceId()} answers null rather than
     *     inventing one, and it reads wider than the active span — the engine
     *     writes its `request` record from `terminate()`, after upstream's
     *     middleware has already ended the request span.
     *
     *   - **A record that names no trace is not given one.** Today that is
     *     Prism's own `replica_metric` and only it: CPU, memory and queue depth
     *     are facts about the *process*, sampled on a wall-clock interval, and
     *     the execution that happened to be open when the interval elapsed had
     *     nothing to do with them. Filing a replica's health under a trace is a
     *     correlation the console would then draw — the same reasoning
     *     {@see EXECUTIONLESS_TYPES} applies to the execution id, in the mirror
     *     direction: that one fills a blank, this one leaves one alone.
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
     * Hang a record-shaped span off whatever OpenTelemetry span is open right
     * now.
     *
     * Two of the engine's signals are drawn as spans by Prism and by nothing
     * else: a cache event (OTel emits no cache span at all — upstream's cache
     * instrumentation only calls `addEvent()`, and a span *event* has no column
     * here) and an outgoing request. The translator is pure and cannot see what
     * is open, so it leaves them with no identity and no lineage; this is the
     * first point in the pipeline that can, and it has to be *here* rather than
     * at the flush, because the record is written the moment the operation
     * completes and the active span a moment later is a different one.
     *
     * {@see SpanLineage::stampSpan()} holds the rules, including the one that
     * is easy to miss: the event adopts the active span's `trace_id` too, or it
     * names a parent that is not in its own trace and the server's tree
     * assembly drops it to a root.
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
     * Fill in a `request_id` the translator could not know.
     *
     * **A `request` record carries no `execution_id`** — it *is* the execution,
     * and every other record points back at it — so the translator, which is
     * pure and sees one record at a time, leaves the column empty. The id is
     * the execution *state*'s, which the ingest can reach and the translator
     * cannot; without this the `requests` table's `request_id` is blank for
     * every row and a request-detail screen cannot find its own children.
     *
     * Read per record rather than captured once: a queue worker and an Octane
     * worker both hand the same `Core` many executions in a row, each with an
     * id of its own. Blank stays blank when there is no resolver (the shape
     * `PrismIngestTest` exercises the translation with), when reading it fails —
     * an empty column is a gap, where a throw here would cost the host a request
     * — or when the event belongs to no execution at all
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
     * Ship whatever a buffer holds, never throwing and never being captured as
     * telemetry itself. An empty buffer costs nothing — the flusher would
     * decline it anyway, and not building one keeps a no-op flush free.
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
     * Whether the shared buffer has reached the capacity `prism.batch.size`
     * gave it. A non-positive capacity is unbounded, so it is never full and
     * the mid-execution ship never fires.
     */
    private function bufferIsFull(): bool
    {
        $capacity = $this->buffer->capacity();

        return $capacity > 0 && $this->buffer->count() >= $capacity;
    }
}
