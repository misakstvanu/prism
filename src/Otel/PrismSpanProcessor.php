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
 * Turns every finished OpenTelemetry span into one Prism `span` event (US-015). **The whole OpenTelemetry
 * decision exists for this**: the capture engine reports a cache read, a query and an outgoing call on
 * *completion* only — no start hook — so a decorator over those records can only produce flat siblings
 * sharing a trace id, where an OTel span carries a parent span id by construction. Load-bearing plumbing:
 *
 *   - **Registered through upstream's own `traces.processors` slot**, so the `TracerProvider` stays
 *     `keepsuit/…`'s with its resource, sampler and propagators: replacing it would mean owning all of
 *     that, and a host later publishing `config/opentelemetry.php` to export to its own collector would
 *     find Prism had taken the tracer away. Sits *beside* the batch processor upstream always installs,
 *     whose exporter US-014 pins to `null`, so that one is a Noop and nothing leaves the process.
 *   - **An emitted `span_id` is the SDK's own**, as is the `parent_span_id` its children name, and
 *     nothing here re-keys a span: the server groups children by parent and walks depth-first, so a
 *     parent id naming a span not in the trace silently drops that row to a root and the waterfall goes
 *     flat with nothing reporting a problem.
 *   - **Nothing downstream changes**: the same {@see EventBuffer}, envelope, transport and
 *     `prism.batch.flush` strategy as every other signal, so a sampler-rejected execution drops its
 *     spans with its buffer ({@see PrismIngest::flush()}) rather than shipping a waterfall for a request
 *     nothing else recorded. There is no depth column on `spans` and never was: the server derives depth
 *     and `hasChildren` at read time, the house rule that the service produces every display figure.
 *   - **`offset_ms` is milliseconds from the front of the trace**, not the epoch; OTel timestamps being
 *     absolute, {@see SpanLineage} remembers the origin from `onStart`, the only hook before a child's.
 *   - **A db span stands for two signals** (US-018): a `db` lane bar *and* a row in the `queries` table
 *     the Queries screen reads, one producer for a signal both engines watch — see {@see recordQuery()}
 *     and {@see SpanLane}.
 *   - **{@see type()}** maps the span's kind and attributes onto Prism's existing six-lane vocabulary
 *     (`mw|ctrl|db|http|cache|resp`), never dropping a span it cannot classify: an unmapped span is
 *     `ctrl`, a bar in the wrong lane being a colour where a missing bar is a hole in the trace.
 */
final class PrismSpanProcessor implements SpanProcessorInterface
{
    /** The telemetry signal name; the server routes it to the `spans` table. */
    private const EVENT_TYPE = 'span';

    /**
     * The attribute a caller-declared waterfall lane rides on (US-021). The manual span
     * {@see Prism::span()} opens carries none of the attributes {@see type()} reads a lane off, so
     * without this its `$type` argument would be silently ignored and every hand-instrumented span come
     * out `ctrl`. Namespaced because the attribute bag is the host's and the SDK's as much as ours; it
     * lands in the event payload and is dropped server-side, there being no `spans` column for it.
     */
    public const TYPE_ATTRIBUTE = 'prism.span.type';

    /**
     * The second signal a db span carries, routed server-side to the `queries` table the Queries screen
     * reads. See {@see recordQuery()}.
     */
    private const QUERY_EVENT_TYPE = 'query';

    /**
     * The datastore whose spans are NOT queries. Upstream's Redis instrumentation sets the same
     * `db.system.name` as the SQL one, so the `db` lane covers both — but a Redis command is not a row in
     * `queries` and never was: Prism's query signal has always been Laravel's `QueryExecuted`, which Redis
     * does not raise.
     */
    private const NOT_A_QUERY = 'redis';

    /**
     * The span upstream back-dates over the framework's bootstrap — the one OTel span belonging in
     * Prism's `mw` lane. Matched by name because it carries no attribute to match on; a rename upstream
     * costs the bar its colour, not its existence.
     */
    private const BOOTSTRAP_SPAN = 'app bootstrap';

    /**
     * The waterfall lanes the console draws, hence the only values {@see TYPE_ATTRIBUTE} is honoured for
     * — an unrecognised one falls through to the ordinary rules rather than reaching the `spans` `type`
     * column, where it would render as no lane at all.
     *
     * @var list<string>
     */
    private const LANES = ['mw', 'ctrl', 'db', 'http', 'cache', 'resp'];

    /**
     * Whether {@see shutdown()} has been called: the specification says `onStart`, `onEnd` and
     * `forceFlush` are invalid afterwards, and a second shutdown answers false.
     */
    private bool $closed = false;

    /**
     * @param  Container  $container  Resolved from lazily, not injected: this processor is
     *                                constructed while `keepsuit/…` boots, *before* Prism's own
     *                                `registerCapture()` bound the buffer it writes into.
     */
    public function __construct(
        private readonly Container $container,
        private readonly SpanLineage $lineage,
    ) {}

    /**
     * Remember where this span's trace began. `onStart` is the only hook running before a child span's own
     * start, so the only place the trace origin can be learned in time to lay the first child out against
     * it: a registry filled at `onEnd` would learn the root's start after every child had needed it.
     */
    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->lineage->observe($span->getContext()->getTraceId(), $this->startNanos($span));
        } catch (Throwable) {
            // A lane that cannot say where a trace began draws its bars from each span's own start;
            // it must never cost the host a request.
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
     * Nothing is held back, so nothing to force out: `onEnd` writes straight into the shared buffer, which
     * the host's own flush strategy ships. Answers true so a caller draining every processor — upstream's
     * worker mode after each queue job, US-019's tail sampling — reads a successful flush, not a failed one.
     */
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return ! $this->closed;
    }

    /**
     * Close the lane and forget the trace origins. Answers false when already shut down, as the
     * specification requires.
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
     * Build and buffer the event. Two refusals come first, both about not capturing Prism's own work:
     * {@see Recursion::suppressed()} is true while a batch is built and shipped, and `prism.ignore.http`
     * covers a dogfooded install's own ClickHouse and ingest hosts ({@see rejected()}). Without them,
     * sending telemetry is telemetry — the loop {@see RejectRules} closes for every capture-engine signal.
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
                // Attributes travel first so Prism's column vocabulary wins any collision, the record
                // translator's rule; anything with no `spans` column is dropped server-side, not lost work.
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
     * Buffer the `queries` row a db span also stands for (US-018). The Queries screen and the `queries`
     * table predate the span lane; only the producer changed. Both engines watch `QueryExecuted`, and
     * once the span carries the parent lineage a waterfall needs, the capture engine's flat `query`
     * record is the same work reported twice — so {@see PrismIngest} drops it and this fills the gap:
     * one query is one `queries` row and one `db` span, from one producer, the only arrangement in which
     * the two cannot disagree. Three deliberate limits follow, all upstream's, all visible on the
     * screen. A Redis command is a `db` span and is NOT a query: its instrumentation sets the same
     * `db.system.name`, which is why the `db` lane covers both, but Prism's query signal has always been
     * `QueryExecuted`, which Redis never raises ({@see NOT_A_QUERY}). `connection` is that
     * `db.system.name` — the driver (`pgsql`, `mysql`, `sqlite`) — not Laravel's connection name, which
     * no attribute carries; for the default connections the two read identically, and the driver is what
     * that column's reader wants either way. The SQL is upstream's `db.query.text`, truncated at 500
     * characters, and `bindings` stays absent rather than filled with an empty list reading as "this
     * query had none", the record translator's stance. The `slow` marker is Prism's own: the *server's*
     * sampler keeps a slow query and the trace around it whatever the workspace's rules say, still at
     * `prism.query.slow_threshold_ms` ({@see SpanLane::isSlowQuery()}).
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
     * The first of the named attributes that carries a string, or null. Two spellings each because the
     * semantic conventions renamed every one of these (`db.system` → `db.system.name`, `db.statement` →
     * `db.query.text`) and a host may run an instrumentation predating the rename: reading both costs an
     * array lookup and saves a column that silently empties on a dependency bump.
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
     * Run `prism.scrub` over the two attributes that can carry a value a host asked never to leave the
     * process (US-011, applied to the lane by US-018). Not belt and braces: once the span lane owns the
     * query and outgoing-request signals, an attribute is where that text lives, and a span meets the list
     * nowhere unless it meets it here — the engine's own records meet it through {@see RedactRules}'s
     * `redact*` callbacks, so a `prism.scrub` entry would go on reading correctly in config while covering
     * one producer out of two. Two shapes, as on the record side: a statement is free text holding
     * `name = value` pairs, a URL a query string rewritten pair by pair. Upstream redacts the URL against
     * a sensitive-parameter list of its own first; this runs over the result, so both lists apply.
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
                // `url.query` is a bare query string; adding the leading `?` the pair-splitter looks
                // for and taking it off again lets one implementation answer both.
                $attributes[$key] = $key === 'url.full'
                    ? $rules->redactUrl($url)
                    : substr($rules->redactUrl('?'.$url), 1);
            }
        }

        return $attributes;
    }

    /**
     * What the bar is labelled on the waterfall: the SDK's own name for every lane but one. Upstream names
     * an outgoing call's span `"{METHOD} {route}"`, and `route` is null unless the host installed a
     * resolver, so in practice the label is the bare word `GET`, saying nothing about who was called. The
     * lane keeps Prism's vocabulary, `GET api.example.com/v1/things`, as the record side has it
     * ({@see RecordTranslator}) — a supersession must not cost the screen the label it had — built from
     * the attributes, not `url.full`, so a credential in a query string cannot reach the `name` column.
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
     * Which of Prism's six waterfall lanes this span belongs in — the lanes the console draws and
     * `--color-span-*` names, an existing vocabulary rather than OTel's. Read in order: **db**, anything
     * speaking a datastore's protocol (upstream's query and Redis instrumentations both set
     * `db.system.name`, so one rule covers SQL and Redis without naming either); **http**, an outgoing
     * call — a CLIENT span carrying an HTTP method or a URL, kind-gated deliberately because the
     * *incoming* request span carries the same attributes and is not an outgoing call; **resp**,
     * rendering the response, produced by upstream's view instrumentation (off by default, US-014) and
     * marked by the `template.name` only it sets; **mw**, the framework's bootstrap
     * ({@see BOOTSTRAP_SPAN}); and **ctrl**, the request or job itself and the fallback for anything
     * unrecognised — a span in the wrong lane is a colour where a dropped span is a hole in the trace,
     * so nothing here returns null. There is no **cache** arm, and that is not an omission: OTel emits
     * no cache span at all (upstream's cache instrumentation only calls `addEvent()`, and a span *event*
     * has no Prism column to land in), so that lane is fed by the capture engine's own record and joined
     * to this tree by {@see SpanLineage::stampSpan()}.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function type(SpanDataInterface $data, array $attributes): string
    {
        // A lane the caller named outright wins: a manual span carries no attribute the rules below
        // could read, so the argument would otherwise be silently ignored.
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
     * The authenticated user upstream recorded on the span, if the host left `opentelemetry.user_context`
     * on. Only the request's own span carries it, and that is enough: every other signal in the trace
     * correlates by `trace_id`, not by repeating the attribution.
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
     * Whether this span describes work a host has asked Prism not to watch. Only the outgoing-call
     * dimension is answerable here: `prism.ignore.http` is what a workspace monitoring itself writes its
     * own ClickHouse and ingest hosts onto, exactly the calls that would feed the pipeline its own traffic.
     * Every other dimension of the ignore list is refused earlier by the capture engine's own reject
     * callbacks ({@see RejectRules}) and never produces a span at all.
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
     * The shared buffer, or null when the client is not live. Resolved per span, not injected: this
     * processor is built while `keepsuit/…` boots and Prism binds the buffer in its own `boot()`, one
     * provider later. The {@see PrismServiceProvider::ACTIVE} marker is the package's standing "is the
     * client live" test, stopping an enabled-but-token-less install quietly filling a buffer nothing ships.
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
     * Which signals this lane owns, or null when the client is not live enough to have bound the rule.
     * Resolved per span for {@see buffer()}'s reason: built one provider before Prism's own boot.
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
     * The `prism.scrub` rules, or null when the client is not live enough to have bound them. Resolved
     * per span for {@see buffer()}'s reason.
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
     * A starting span's epoch nanoseconds. The SDK's own span exposes them directly; the interface only
     * promises `toSpanData()`, which allocates an immutable snapshot, and `onStart` runs for every span
     * in the process, so the direct read earns its `instanceof`.
     */
    private function startNanos(ReadWriteSpanInterface $span): int
    {
        return $span instanceof Span
            ? $span->getStartEpochNanos()
            : $span->toSpanData()->getStartEpochNanos();
    }
}
