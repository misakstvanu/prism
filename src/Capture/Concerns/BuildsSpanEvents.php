<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Capture\Concerns;

use Illuminate\Contracts\Container\Container;
use Misakstvanu\Prism\Support\SpanStack;
use Misakstvanu\Prism\Support\TraceContext;

/**
 * Shared construction of `span` telemetry events (US-046, US-050).
 *
 * Cache operations, outgoing HTTP calls, the request's own lifecycle phases and
 * manually instrumented code are all surfaced as spans so they render in the
 * trace waterfall (US-063). Every producer builds the identical event shape —
 * the server's `spans` columns (US-006) as a payload plus the correlating
 * envelope keys — so that shape lives here rather than being duplicated.
 *
 * A span carries a fresh `span_id`, its `parent_span_id` and its nesting `depth`
 * (US-050). When a caller does not pass them they default from
 * {@see SpanStack} — the currently open span becomes the parent — so a cache or
 * HTTP span emitted while a controller phase span is open nests beneath it
 * without the capturer having to thread lineage through. `offset_ms` is the
 * elapsed time from the front of the trace ({@see TraceContext::elapsedMs()}) so
 * the waterfall can lay every span out against a common origin, and `trace_id`
 * ties it to the enclosing request or job the console correlates on.
 */
trait BuildsSpanEvents
{
    /** The telemetry signal name (US-026); the server routes it to `spans`. */
    private const SPAN_EVENT_TYPE = 'span';

    /**
     * Build a span event: the span's own fields as a payload, plus the
     * correlating envelope keys (trace id, user id, timestamp) the server reads
     * off the top of the event (US-027). Any $extra keys ride the payload too;
     * ones with no matching `spans` column are dropped server-side by
     * `input_format_skip_unknown_fields`, so a capturer can keep descriptive
     * fields (a cache store, an HTTP status) without a schema change.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function spanEvent(
        string $name,
        string $type,
        float $offsetMs,
        float $durationMs,
        ?string $userId,
        array $extra = [],
        ?string $parentSpanId = null,
        ?int $depth = null,
        ?string $spanId = null,
    ): array {
        return [
            'timestamp' => now()->toIso8601String(),
            'trace_id' => TraceContext::traceId(),
            'request_id' => '',
            'user_id' => $userId,
            'payload' => array_merge([
                'span_id' => $spanId ?? $this->newSpanId(),
                'parent_span_id' => $parentSpanId ?? SpanStack::currentId(),
                'name' => $name,
                'type' => $type,
                'offset_ms' => round($offsetMs, 3),
                'duration_ms' => round($durationMs, 3),
                'depth' => $depth ?? SpanStack::currentDepth(),
            ], $extra),
        ];
    }

    /** A fresh 16-character hex span identifier, the shape US-034's seeder uses. */
    protected function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * The authenticated user id, or null with no bound guard or no user — the
     * same attribution the other capturers carry.
     */
    protected function resolveUserId(Container $container): ?string
    {
        if (! $container->bound('auth')) {
            return null;
        }

        $auth = $container->make('auth');

        if (! is_object($auth) || ! method_exists($auth, 'id')) {
            return null;
        }

        /** @var mixed $id */
        $id = $auth->id();

        return is_scalar($id) ? (string) $id : null;
    }
}
