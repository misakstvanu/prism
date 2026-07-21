<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Capture;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\Concerns\BuildsSpanEvents;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\TraceContext;

/**
 * Turns cache activity into buffered `span` telemetry events (US-046).
 *
 * {@see PrismServiceProvider::registerCacheCapture()} listens for Laravel's four
 * cache lifecycle events — {@see CacheHit}, {@see CacheMissed}, {@see KeyWritten}
 * and {@see KeyForgotten} — and hands each here, and binds this as a container
 * singleton so every listener feeds the same buffer.
 *
 * Cache behaviour is emitted as a span rather than its own signal so it appears
 * on the trace waterfall (US-050/US-063) next to the request's other work: the
 * span's `name` records the operation, the (truncated) key and the store, its
 * `type` is `cache`, and its `offset_ms` places it in the trace. Cache events
 * carry no timing, so a cache span has a zero duration — it marks a point in the
 * trace rather than an interval.
 *
 * The package's own cache activity — a lookup run while it flushes a batch — is
 * never captured: {@see capture()} short-circuits under
 * {@see Recursion::suppressed()}.
 */
final class CacheCapture
{
    use BuildsSpanEvents;

    /** The span sub-type (US-006 `spans.type`); the waterfall tints it as a cache span. */
    private const SPAN_TYPE = 'cache';

    /**
     * The cache key is recorded on the span name, so a very long key is trimmed
     * to keep the name (and the batch) bounded — the key still reads clearly at
     * this length, and the AC only asks that it be sensibly truncated.
     */
    private const MAX_KEY_LENGTH = 120;

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Container $container,
    ) {}

    /**
     * Record one cache event into the buffer as a span, unless it is the
     * package's own work or an event type we do not surface.
     */
    public function capture(CacheEvent $event): void
    {
        if (Recursion::suppressed()) {
            return;
        }

        $operation = self::operationFor($event);

        if ($operation === null) {
            return;
        }

        $key = Str::limit((string) $event->key, self::MAX_KEY_LENGTH);
        $store = is_string($event->storeName) && $event->storeName !== '' ? $event->storeName : 'default';

        $this->buffer->add(self::SPAN_EVENT_TYPE, $this->spanEvent(
            name: sprintf('cache:%s %s (%s)', $operation, $key, $store),
            type: self::SPAN_TYPE,
            offsetMs: TraceContext::elapsedMs(),
            durationMs: 0.0,
            userId: $this->resolveUserId($this->container),
            extra: [
                'operation' => $operation,
                'key' => $key,
                'store' => $store,
            ],
        ));
    }

    /**
     * The verb for one of the four captured cache events, or null for any other
     * cache event (a flush, a lock) US-046 does not surface as a span.
     */
    private static function operationFor(CacheEvent $event): ?string
    {
        return match (true) {
            $event instanceof CacheHit => 'hit',
            $event instanceof CacheMissed => 'miss',
            $event instanceof KeyWritten => 'write',
            $event instanceof KeyForgotten => 'forget',
            default => null,
        };
    }
}
