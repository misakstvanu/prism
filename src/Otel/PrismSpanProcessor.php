<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Otel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RedactRules;
use Misakstvanu\Prism\Nightwatch\RejectRules;
use Misakstvanu\Prism\Prism;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Timestamp;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use Throwable;

/**
 * Turns every finished OpenTelemetry span into one Prism `span` event (US-015).
 *
 * **This is the story the whole OpenTelemetry decision exists for.** The capture
 * engine reports a cache read, a query and an outgoing call on *completion*
 * only — there is no start hook and nothing that says what was open at the
 * time — so any decorator over those records can produce flat siblings sharing
 * a trace id and nothing else. An OTel span carries a parent span id by
 * construction, which is the one thing a waterfall needs and the one thing the
 * engine cannot give. Everything else about the span lane is plumbing.
 *
 * Four things about that plumbing are load-bearing:
 *
 *   - **It is registered through upstream's own `traces.processors` slot**, so
 *     the `TracerProvider` this reads from is the one `keepsuit/…` built —
 *     with its resource, its sampler and its propagators. Replacing the
 *     provider would mean owning all of that, and a host that later publishes
 *     `config/opentelemetry.php` to export to its own collector would find
 *     Prism had quietly taken the tracer away from it. The processor sits
 *     *beside* the batch processor upstream always installs; because US-014
 *     pins every exporter to `null`, that one is a Noop and nothing leaves the
 *     process.
 *
 *   - **A span's emitted `span_id` is the SDK's own**, and so is the
 *     `parent_span_id` its children name. That equality is the whole contract:
 *     the server groups children by parent and walks the tree depth-first, so
 *     a parent id naming a span that is not in the trace silently drops that
 *     row to a root and the waterfall goes flat with nothing reporting a
 *     problem. Nothing here re-keys a span.
 *
 *   - **Nothing downstream changes.** The event goes into the same
 *     {@see EventBuffer} every other signal writes into, so it rides the same
 *     envelope, the same transport and the same `prism.batch.flush` strategy —
 *     and, because {@see PrismIngest::flush()} discards that buffer for an
 *     execution the sampler rejected, a dropped execution drops its spans with
 *     it rather than shipping a waterfall for a request nothing else recorded.
 *     There is no depth column on the `spans` table and never was: depth and
 *     `hasChildren` are derived at read time by the server, which is the house
 *     rule that the service produces every display figure.
 *
 *   - **`offset_ms` is milliseconds from the front of the trace**, not from the
 *     epoch. OTel timestamps are absolute, so the trace's origin is remembered
 *     by {@see SpanLineage} from `onStart` — the only hook that runs before a
 *     child's own start.
 *
 * **A db span stands for two signals, not one** (US-018). It is a bar in the
 * `db` lane *and* a row in the `queries` table the Queries screen reads — both
 * emitted here, from one span, because both engines watch `QueryExecuted` and
 * only one of them may be the producer or the same query is stored twice. The
 * capture engine's own `query` record is the one that gives way; see
 * {@see recordQuery()} and {@see SpanLane}.
 *
 * The type mapping answers Prism's existing six-lane vocabulary
 * (`mw|ctrl|db|http|cache|resp`) from the span's kind and attributes; see
 * {@see type()}. It never drops a span it cannot classify — an unmapped span is
 * `ctrl`, because a bar in the wrong lane is a colour and a missing bar is a
 * hole in the trace.
 */
final class PrismSpanProcessor implements SpanProcessorInterface
{
    /** The telemetry signal name; the server routes it to the `spans` table. */
    private const EVENT_TYPE = 'span';

    /**
     * The attribute a caller-declared waterfall lane rides on (US-021).
     *
     * {@see Prism::span()} takes a `$type` argument and the
     * manual span it opens has none of the attributes {@see type()} reads a lane
     * off, so without this every hand-instrumented span would come out `ctrl`
     * whatever the caller asked for. Namespaced because the attribute bag is the
     * host's and the SDK's as much as ours; it lands in the event payload and is
     * dropped server-side, there being no `spans` column named for it.
     */
    public const TYPE_ATTRIBUTE = 'prism.span.type';

    /**
     * The second signal a db span carries: the server routes this one to the
     * `queries` table, which is what the Queries screen reads. See
     * {@see recordQuery()}.
     */
    private const QUERY_EVENT_TYPE = 'query';

    /**
     * The datastore whose spans are NOT queries. Upstream's Redis
     * instrumentation sets the same `db.system.name` attribute the SQL one
     * does, so the `db` lane covers both — but a Redis command is not a row in
     * the `queries` table and never was: Prism's query signal has always been
     * Laravel's `QueryExecuted`, which Redis does not raise.
     */
    private const NOT_A_QUERY = 'redis';

    /**
     * The span upstream back-dates over the framework's bootstrap, which is
     * the one span OTel produces that belongs in Prism's `mw` lane. Matched by
     * name because it carries no attribute to match on; a rename upstream
     * costs the bar its colour, not its existence.
     */
    private const BOOTSTRAP_SPAN = 'app bootstrap';

    /**
     * The waterfall lanes the console draws, and therefore the only values
     * {@see TYPE_ATTRIBUTE} is honoured for — an unrecognised one falls through
     * to the ordinary rules rather than reaching the `spans` table's `type`
     * column, where it would render as no lane at all.
     *
     * @var list<string>
     */
    private const LANES = ['mw', 'ctrl', 'db', 'http', 'cache', 'resp'];

    /**
     * Whether {@see shutdown()} has been called. The specification says
     * `onStart`, `onEnd` and `forceFlush` are invalid afterwards, and a second
     * shutdown answers false.
     */
    private bool $closed = false;

    /**
     * @param  Container  $container  Resolved from lazily rather than injected: this processor is
     *                                constructed while `keepsuit/…` boots, which is *before* Prism's
     *                                own `registerCapture()` has bound the buffer it writes into.
     */
    public function __construct(
        private readonly Container $container,
        private readonly SpanLineage $lineage,
    ) {}

    /**
     * Remember where this span's trace began.
     *
     * `onStart` is the only hook that runs before a child span's own start, so
     * it is the only place the trace origin can be learned in time to lay the
     * first child out against it. A registry filled at `onEnd` would learn the
     * root's start after every one of its children had already needed it.
     */
    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->lineage->observe($span->getContext()->getTraceId(), $this->startNanos($span));
        } catch (Throwable) {
            // A lane that cannot say where a trace began draws its bars from
            // each span's own start; it must never cost the host a request.
        }
    }

    /** Buffer the finished span as a Prism `span` event. */
    public function onEnd(ReadableSpanInterface $span): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->record($span);
        } catch (Throwable $e) {
            Log::debug('Prism span capture failed: '.$e->getMessage());
        }
    }

    /**
     * Nothing is held back, so there is nothing to force out: `onEnd` writes
     * straight into the shared buffer, which the host's own flush strategy
     * ships. Answers true so a caller draining every processor — upstream's
     * worker mode does this after each queue job, and US-019's tail sampling
     * will — reads a successful flush rather than a failed one.
     */
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return ! $this->closed;
    }

    /**
     * Close the lane and forget the trace origins. Answers false when already
     * shut down, as the specification requires.
     */
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        if ($this->closed) {
            return false;
        }

        $this->closed = true;
        $this->lineage->reset();

        return true;
    }

    /**
     * Build and buffer the event.
     *
     * Two refusals come first, and both are about not capturing Prism's own
     * work: {@see Recursion::suppressed()} is true while a batch is being built
     * and shipped, and `prism.ignore.http` is what a dogfooded install writes
     * its own ClickHouse and ingest hosts onto. Without them, sending telemetry
     * is telemetry — the exact loop {@see RejectRules} closes for every signal
     * the capture engine raises.
     */
    private function record(ReadableSpanInterface $span): void
    {
        if (Recursion::suppressed()) {
            return;
        }

        $buffer = $this->buffer();

        if ($buffer === null) {
            return;
        }

        $data = $span->toSpanData();

        /** @var array<string, mixed> $attributes */
        $attributes = $data->getAttributes()->toArray();

        if ($this->rejected($attributes)) {
            return;
        }

        $attributes = $this->redacted($attributes);

        $traceId = $data->getTraceId();
        $startNanos = $data->getStartEpochNanos();
        $origin = $this->lineage->originNanos($traceId) ?? $startNanos;
        $timestamp = Timestamp::fromEpochNanos($startNanos) ?? Timestamp::fromMicrotime(microtime(true));
        $durationMs = round(max(0.0, ($data->getEndEpochNanos() - $startNanos) / 1_000_000), 3);
        $type = $this->type($data, $attributes);

        $buffer->add(self::EVENT_TYPE, [
            'timestamp' => $timestamp,
            'trace_id' => $traceId,
            'request_id' => '',
            'user_id' => $this->userId($attributes),
            'payload' => [
                // The span's own attributes travel first so Prism's column
                // vocabulary wins any collision, the same rule the record
                // translator follows. Anything with no `spans` column is
                // dropped server-side rather than lost work.
                ...$attributes,
                'span_id' => $data->getSpanId(),
                'parent_span_id' => $data->getParentContext()->isValid() ? $data->getParentSpanId() : '',
                'name' => $this->name($data, $attributes, $type),
                'type' => $type,
                'offset_ms' => round(max(0.0, ($startNanos - $origin) / 1_000_000), 3),
                'duration_ms' => $durationMs,
            ],
        ]);

        if ($type === 'db') {
            $this->recordQuery($buffer, $attributes, $traceId, $timestamp, $durationMs);
        }
    }

    /**
     * Buffer the `queries` row a db span also stands for (US-018).
     *
     * The Queries screen and the `queries` table long predate the span lane and
     * are not going anywhere: what changed is the producer. Both engines watch
     * `QueryExecuted`, and once the span carries the parent lineage a waterfall
     * needs, the capture engine's flat `query` record is the same work reported
     * a second time — so {@see PrismIngest} drops that record and this fills the
     * gap. One query therefore lands as exactly one row in `queries` and exactly
     * one `db` span, from one producer, which is the only arrangement in which
     * the two cannot disagree.
     *
     * Three deliberate limits, all of them upstream's and all visible on the
     * screen rather than hidden:
     *
     *   - **A Redis command is a `db` span and is NOT a query.** Upstream's
     *     Redis instrumentation sets the same `db.system.name` attribute, which
     *     is exactly why the `db` lane covers both — but Prism's query signal
     *     has always been `QueryExecuted`, which Redis never raises. See
     *     {@see NOT_A_QUERY}.
     *   - **`connection` is the driver, not Laravel's connection name.** The
     *     span carries `db.system.name` (`pgsql`, `mysql`, `sqlite`) and no
     *     attribute names the connection the query ran on; for the default
     *     connections the two read identically, and the driver is the answer a
     *     reader of that column is looking for either way.
     *   - **The SQL is upstream's `db.query.text`**, which it truncates at 500
     *     characters. `bindings` stays absent rather than being filled with an
     *     empty list that would read as "this query had none" — the same
     *     stance the record translator takes.
     *
     * The `slow` marker is Prism's own and rides the payload exactly as it
     * always has: the *server's* sampler keeps a slow query and the trace
     * around it whatever the workspace's rules say, and
     * `prism.query.slow_threshold_ms` is still the threshold. See
     * {@see SpanLane::isSlowQuery()}.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recordQuery(
        EventBuffer $buffer,
        array $attributes,
        string $traceId,
        string $timestamp,
        float $durationMs,
    ): void {
        $lane = $this->lane();

        if ($lane === null || ! $lane->supersedes('query')) {
            return;
        }

        $system = $this->attribute($attributes, 'db.system.name', 'db.system');
        $sql = $this->attribute($attributes, 'db.query.text', 'db.statement');

        if ($sql === null || $system === self::NOT_A_QUERY) {
            return;
        }

        $buffer->add(self::QUERY_EVENT_TYPE, [
            'timestamp' => $timestamp,
            'trace_id' => $traceId,
            'request_id' => '',
            'user_id' => $this->userId($attributes),
            'payload' => [
                'sql' => $sql,
                'connection' => $system ?? $this->attribute($attributes, 'db.namespace') ?? '',
                'duration_ms' => $durationMs,
                'slow' => $lane->isSlowQuery($durationMs) ? 1 : 0,
            ],
        ]);
    }

    /**
     * The first of the named attributes that carries a string, or null.
     *
     * Two spellings per attribute because the semantic conventions renamed
     * every one of these (`db.system` → `db.system.name`, `db.statement` →
     * `db.query.text`) and a host is free to run an instrumentation that
     * predates the rename. Reading both costs an array lookup and saves a
     * column that silently empties on a dependency bump.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function attribute(array $attributes, string ...$names): ?string
    {
        foreach ($names as $name) {
            /** @var mixed $value */
            $value = $attributes[$name] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Run `prism.scrub` over the two attributes that can carry a value a host
     * asked never to leave the process (US-011, applied to the lane by US-018).
     *
     * This is not belt and braces: once the span lane owns the query and
     * outgoing-request signals, an attribute is where that text lives. The
     * engine's own records meet the list through {@see RedactRules}'s
     * `redact*` callbacks, and a span meets nothing at all unless it meets it
     * here — so a `prism.scrub` entry would go on reading correctly in config
     * while quietly covering one producer out of two.
     *
     * Two shapes, the same two the record side has: a statement is free text
     * holding `name = value` pairs, and a URL is a query string rewritten pair
     * by pair. Upstream redacts the URL against a sensitive-parameter list of
     * its own first; this one runs over the result, so both lists apply.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function redacted(array $attributes): array
    {
        $rules = $this->redactions();

        if ($rules === null) {
            return $attributes;
        }

        foreach (['db.query.text', 'db.statement'] as $key) {
            if (is_string($attributes[$key] ?? null)) {
                /** @var string $text */
                $text = $attributes[$key];
                $attributes[$key] = $rules->redactText($text);
            }
        }

        foreach (['url.full', 'url.query'] as $key) {
            if (is_string($attributes[$key] ?? null)) {
                /** @var string $url */
                $url = $attributes[$key];
                // `url.query` is a bare query string; giving it the leading `?`
                // the pair-splitter looks for and taking it off again is what
                // lets one implementation answer both.
                $attributes[$key] = $key === 'url.full'
                    ? $rules->redactUrl($url)
                    : substr($rules->redactUrl('?'.$url), 1);
            }
        }

        return $attributes;
    }

    /**
     * What the bar is labelled on the waterfall.
     *
     * The SDK's own name is used for every lane but one. An outgoing call's
     * span is named `"{METHOD} {route}"` upstream — and `route` is null unless
     * the host installed a resolver, so in practice the label is the bare word
     * `GET`, which says nothing about who was called. Prism has always named
     * that bar `GET api.example.com/v1/things`, on the record side too
     * ({@see RecordTranslator}), so the lane keeps that vocabulary: a
     * supersession must not cost the screen the label it had.
     *
     * Built from the attributes rather than from `url.full`, so a credential in
     * a query string cannot reach the `name` column by the back door.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function name(SpanDataInterface $data, array $attributes, string $type): string
    {
        if ($type !== 'http') {
            return $data->getName();
        }

        $method = $this->attribute($attributes, 'http.request.method', 'http.method') ?? '';
        $host = $this->attribute($attributes, 'server.address', 'net.peer.name') ?? '';
        $path = $this->attribute($attributes, 'url.path') ?? '';

        $name = trim($method.' '.$host.$path);

        return $name === '' ? $data->getName() : $name;
    }

    /**
     * Which of Prism's six waterfall lanes this span belongs in.
     *
     * The lanes are the ones the console has always drawn and the ones
     * `--color-span-*` names, so this maps into an existing vocabulary rather
     * than introducing OTel's. Read in order:
     *
     *   - **db** — anything speaking a datastore's protocol. Upstream's query
     *     and Redis instrumentations both set `db.system.name`, so one rule
     *     covers SQL and Redis without naming either.
     *   - **http** — an outgoing call: a CLIENT span carrying an HTTP method or
     *     a URL. Kind-gated deliberately, because the *incoming* request span
     *     carries the same attributes and is not an outgoing call.
     *   - **resp** — rendering the response. Upstream's view instrumentation
     *     (off by default, US-014) is the producer, and `template.name` is the
     *     attribute only it sets.
     *   - **mw** — the framework's bootstrap; see {@see BOOTSTRAP_SPAN}.
     *   - **ctrl** — the request or job itself, and the fallback for anything
     *     unrecognised. A span in the wrong lane is a colour; a dropped span is
     *     a hole in the trace, so nothing here returns null.
     *
     * There is no **cache** arm, and that is not an omission: OTel emits no
     * cache span at all (upstream's cache instrumentation only calls
     * `addEvent()`, and a span *event* has no Prism column to land in), so the
     * cache lane is fed by the capture engine's own record and joined to this
     * tree by {@see SpanLineage::stampSpan()}.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function type(SpanDataInterface $data, array $attributes): string
    {
        // A lane the caller named outright wins: a manual span carries no
        // attribute any of the rules below could read, and the argument would
        // otherwise be silently ignored.
        if (in_array($attributes[self::TYPE_ATTRIBUTE] ?? null, self::LANES, true)) {
            /** @var string */
            return $attributes[self::TYPE_ATTRIBUTE];
        }

        if (isset($attributes['db.system.name']) || isset($attributes['db.query.text'])) {
            return 'db';
        }

        if ($data->getKind() === SpanKind::KIND_CLIENT
            && (isset($attributes['http.request.method']) || isset($attributes['url.full']))) {
            return 'http';
        }

        if (isset($attributes['template.name'])) {
            return 'resp';
        }

        if ($data->getName() === self::BOOTSTRAP_SPAN) {
            return 'mw';
        }

        return 'ctrl';
    }

    /**
     * The authenticated user upstream recorded on the span, if the host left
     * `opentelemetry.user_context` on. Only the request's own span carries it,
     * which is enough: every other signal in the trace is correlated by
     * `trace_id`, not by repeating the attribution.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function userId(array $attributes): ?string
    {
        /** @var mixed $id */
        $id = $attributes['user.id'] ?? null;

        return is_scalar($id) ? (string) $id : null;
    }

    /**
     * Whether this span describes work a host has asked Prism not to watch.
     *
     * Only the outgoing-call dimension is answerable here — `prism.ignore.http`
     * is what a workspace monitoring itself writes its own ClickHouse and
     * ingest hosts onto, and those calls are exactly the ones that would feed
     * the pipeline its own traffic. Every other dimension of the ignore list is
     * refused earlier, by the capture engine's own reject callbacks
     * ({@see RejectRules}), and never produces a span in the first place.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function rejected(array $attributes): bool
    {
        /** @var mixed $url */
        $url = $attributes['url.full'] ?? null;

        if (! is_string($url) || $url === '') {
            return false;
        }

        $rules = $this->rules();

        return $rules !== null && $rules->rejectsUrl($url);
    }

    /**
     * The shared buffer, or null when the client is not live.
     *
     * Resolved per span rather than injected: this processor is built while
     * `keepsuit/…` boots, and Prism binds the buffer in its own `boot()` — one
     * provider later. The {@see PrismServiceProvider::ACTIVE} marker is the
     * package's standing "is the client live" test, and is what stops an
     * enabled-but-token-less install quietly filling a buffer nothing will
     * ever ship.
     */
    private function buffer(): ?EventBuffer
    {
        if (! $this->container->bound(PrismServiceProvider::ACTIVE)) {
            return null;
        }

        try {
            $buffer = $this->container->make(EventBuffer::class);
        } catch (Throwable) {
            return null;
        }

        return $buffer instanceof EventBuffer ? $buffer : null;
    }

    /**
     * Which signals this lane owns, or null when the client is not live enough
     * to have bound the rule. Resolved per span for {@see buffer()}'s reason:
     * this processor is built one provider before Prism's own boot.
     */
    private function lane(): ?SpanLane
    {
        try {
            $lane = $this->container->make(SpanLane::class);
        } catch (Throwable) {
            return null;
        }

        return $lane instanceof SpanLane ? $lane : null;
    }

    /**
     * The `prism.scrub` rules, or null when the client is not live enough to
     * have bound them. Resolved per span for {@see buffer()}'s reason.
     */
    private function redactions(): ?RedactRules
    {
        if (! $this->container->bound(RedactRules::class)) {
            return null;
        }

        try {
            $rules = $this->container->make(RedactRules::class);
        } catch (Throwable) {
            return null;
        }

        return $rules instanceof RedactRules ? $rules : null;
    }

    /** The ignore lists, or null when the client is not live enough to have bound them. */
    private function rules(): ?RejectRules
    {
        if (! $this->container->bound(RejectRules::class)) {
            return null;
        }

        try {
            $rules = $this->container->make(RejectRules::class);
        } catch (Throwable) {
            return null;
        }

        return $rules instanceof RejectRules ? $rules : null;
    }

    /**
     * A starting span's epoch nanoseconds.
     *
     * The SDK's own span exposes them directly; the interface only promises
     * `toSpanData()`, which allocates an immutable snapshot. `onStart` runs for
     * every span in the process, so the direct read is worth the `instanceof`.
     */
    private function startNanos(ReadWriteSpanInterface $span): int
    {
        return $span instanceof Span
            ? $span->getStartEpochNanos()
            : $span->toSpanData()->getStartEpochNanos();
    }
}
