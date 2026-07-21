<?php

declare(strict_types=1);

namespace Misakstvanu\Prism;

use Closure;
use Illuminate\Container\Container;
use Misakstvanu\Prism\Capture\ExceptionCapture;
use Misakstvanu\Prism\Capture\SpanRecorder;
use Throwable;

/**
 * The client's public API surface — the handful of things an application calls
 * directly rather than having captured automatically.
 *
 * Everything here resolves through the container and is a safe no-op when the
 * client is inert (disabled or unconfigured), so a host can call it
 * unconditionally without guarding on whether Prism is set up: a build with
 * `PRISM_ENABLED=false` simply does nothing.
 */
final class Prism
{
    /**
     * Manually report a handled exception (US-042). The application caught it
     * and wants it in Prism anyway — a swallowed integration error, a
     * best-effort background task's failure.
     *
     * Captured as `handled` (distinct from an unhandled exception that escaped
     * to the reporter), scrubbed and buffered like any other; the same ignore
     * list and recursion guard apply. A no-op when the client is inert.
     */
    public static function captureException(Throwable $e): void
    {
        $app = Container::getInstance();

        // ACTIVE is bound only once capture is wired (enabled + token). Absent
        // means the client is inert, so there is nowhere to send this.
        if (! $app->bound(PrismServiceProvider::ACTIVE)) {
            return;
        }

        $app->make(ExceptionCapture::class)->capture($e, handled: true);
    }

    /**
     * Manually instrument a block of code as a trace span (US-050). The closure
     * is timed and recorded as a span carrying its offset from the front of the
     * trace, its duration, its parent span and its nesting depth, so arbitrary
     * application work — an expensive computation, a third-party SDK call the
     * automatic capturers do not see — appears on the trace waterfall.
     *
     * A span opened inside the closure (another `Prism::span()`, a cache lookup,
     * an outgoing HTTP call) nests beneath this one automatically. The default
     * `ctrl` type renders it as application logic; pass one of the prototype's
     * span types (`db`, `http`, …) to tint it differently.
     *
     * A safe no-op when the client is inert: the closure still runs and its
     * value is returned, it is simply not recorded — so a host can wrap code in
     * `Prism::span()` unconditionally.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  array<string, mixed>  $extra
     * @return TReturn
     */
    public static function span(string $name, Closure $callback, string $type = 'ctrl', array $extra = []): mixed
    {
        $app = Container::getInstance();

        if (! SpanRecorder::isActive($app)) {
            return $callback();
        }

        return $app->make(SpanRecorder::class)->record($name, $type, $callback, $extra);
    }
}
