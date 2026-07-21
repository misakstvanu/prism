<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Capture;

use Closure;
use Illuminate\Contracts\Container\Container;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\Concerns\BuildsSpanEvents;
use Misakstvanu\Prism\Prism;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\SpanStack;
use Misakstvanu\Prism\Support\TraceContext;

/**
 * Assembles trace spans into a parent/child waterfall (US-050).
 *
 * A container singleton — {@see PrismServiceProvider::registerCapture()} binds
 * one so every producer of spans shares the same buffer and the same open-span
 * stack. It is the one place a span is buffered, so parent lineage and nesting
 * depth are computed identically wherever a span comes from:
 *
 *   - {@see record()} times a closure and buffers a span for it — the engine
 *     behind manual instrumentation ({@see Prism::span()}).
 *     A span opened inside the closure nests beneath it automatically.
 *   - {@see open()}/{@see close()} bracket a span whose name is not known until
 *     it finishes (the request's controller phase, {@see CaptureRequests}), so
 *     the spans emitted while it is open still nest beneath it.
 *   - {@see emit()} buffers a span that was measured elsewhere (the cache and
 *     HTTP capturers, the request's aggregate database span).
 *
 * Because every open span pushes its id onto {@see SpanStack}, a child span's
 * `parent_span_id` and `depth` come from the stack for free, and a child's whole
 * lifetime falls inside its parent's — the containment the AC pins with a test.
 */
final class SpanRecorder
{
    use BuildsSpanEvents;

    /**
     * The spans currently open through {@see open()}, keyed by id, holding what
     * is needed to buffer each when it closes: its captured parent/depth, its
     * offset from the front of the trace and the wall-clock instant it began.
     *
     * @var array<string, array{parentId: string, depth: int, name: string, type: string, offsetMs: float, startMicros: float, extra: array<string, mixed>}>
     */
    private array $open = [];

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Container $container,
    ) {}

    /** A fresh span id a caller can reserve before it opens the span itself. */
    public function allocateId(): string
    {
        return $this->newSpanId();
    }

    /**
     * Open a span: capture its parent and depth from the current stack, note its
     * offset and start time, push it so anything emitted while it is open nests
     * beneath it, and return its id to close with later.
     *
     * @param  array<string, mixed>  $extra
     */
    public function open(string $name, string $type, array $extra = []): string
    {
        $id = $this->newSpanId();

        $this->open[$id] = [
            'parentId' => SpanStack::currentId(),
            'depth' => SpanStack::currentDepth(),
            'name' => $name,
            'type' => $type,
            'offsetMs' => TraceContext::elapsedMs(),
            'startMicros' => microtime(true),
            'extra' => $extra,
        ];

        SpanStack::open($id);

        return $id;
    }

    /**
     * Close a span opened with {@see open()}, measuring its duration and
     * buffering it with the parent and depth captured when it opened. An id that
     * was never opened (a double close) is ignored.
     */
    public function close(string $id): void
    {
        SpanStack::close($id);

        $frame = $this->open[$id] ?? null;

        if ($frame === null) {
            return;
        }

        unset($this->open[$id]);

        // Emit under the stack id so a child that captured this id as its
        // parent_span_id points at the span this actually produces.
        $this->emit(
            $frame['name'],
            $frame['type'],
            $frame['offsetMs'],
            (microtime(true) - $frame['startMicros']) * 1000,
            $frame['parentId'],
            $frame['depth'],
            $frame['extra'],
            $id,
        );
    }

    /**
     * Time a closure inside a span and buffer it, always closing the span even
     * if the closure throws. A span opened inside the closure nests beneath this
     * one. The package's own work ({@see Recursion::suppressed()}) still runs the
     * closure but records nothing, so instrumenting a flush cannot feed the next
     * flush.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  array<string, mixed>  $extra
     * @return TReturn
     */
    public function record(string $name, string $type, Closure $callback, array $extra = []): mixed
    {
        if (Recursion::suppressed()) {
            return $callback();
        }

        $id = $this->open($name, $type, $extra);

        try {
            return $callback();
        } finally {
            $this->close($id);
        }
    }

    /**
     * Buffer a span that was measured elsewhere. When $parentSpanId / $depth are
     * omitted they default to the current stack, so a span emitted while another
     * is open nests beneath it. $spanId lets a caller that pre-allocated an id
     * (so children could reference it as their parent) emit under that same id.
     *
     * @param  array<string, mixed>  $extra
     */
    public function emit(
        string $name,
        string $type,
        float $offsetMs,
        float $durationMs,
        ?string $parentSpanId = null,
        ?int $depth = null,
        array $extra = [],
        ?string $spanId = null,
    ): void {
        $this->buffer->add(self::SPAN_EVENT_TYPE, $this->spanEvent(
            name: $name,
            type: $type,
            offsetMs: $offsetMs,
            durationMs: $durationMs,
            userId: $this->resolveUserId($this->container),
            extra: $extra,
            parentSpanId: $parentSpanId,
            depth: $depth,
            spanId: $spanId,
        ));
    }

    /**
     * Drop every open span and clear the shared stack. Only for a long-lived
     * runtime resetting between requests or jobs.
     */
    public function reset(): void
    {
        $this->open = [];
        SpanStack::reset();
    }

    /**
     * Whether this recorder is active (the client is enabled and configured), so
     * {@see Prism::span()} can no-op cleanly when it is not.
     */
    public static function isActive(Container $container): bool
    {
        return $container->bound(PrismServiceProvider::ACTIVE);
    }
}
