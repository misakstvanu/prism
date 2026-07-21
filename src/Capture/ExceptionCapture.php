<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Capture;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Prism;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns a thrown exception into a buffered telemetry event (US-042).
 *
 * A single container singleton feeds both capture paths so they share one
 * buffer, scrubber and ignore list:
 *
 *   - Automatic. {@see PrismServiceProvider::registerExceptionCapture()}
 *     registers a `reportable` callback on the host's exception handler, so an
 *     unhandled exception is captured without the user editing
 *     `bootstrap/app.php`. The callback returns nothing, so the host's own
 *     reporting (its default logging) continues untouched.
 *   - Manual. {@see Prism::captureException()} calls
 *     {@see capture()} directly with `handled: true`, so an application that
 *     caught an exception can still report it.
 *
 * Two exceptions are never captured, on either path:
 *
 *   - The package's own faults ({@see Recursion::isInternalException()}) — a
 *     bug in the client must never be reported through the client, and an error
 *     thrown while flushing would otherwise feed the buffer it is draining.
 *   - Anything in the configurable ignore list (`prism.ignore.exceptions`,
 *     defaulting to `NotFoundHttpException` and `ValidationException`), which is
 *     mostly routine control flow rather than a fault worth an error group.
 *
 * The captured payload mirrors the columns the server's `exceptions` table
 * stores (US-006): class, message, file, line, the stack trace with a
 * vendor/app flag per frame, the matched route, the HTTP status, and whether it
 * was handled. The authenticated user id and current trace id ride the event
 * envelope so the console can attribute and correlate the fault. Everything is
 * run through the {@see Scrubber} before buffering so a secret in the collected
 * data never leaves the process.
 */
final class ExceptionCapture
{
    /** The telemetry signal name (US-026); the server routes it to `exceptions`. */
    private const EVENT_TYPE = 'exception';

    /**
     * @param  list<mixed>  $ignore  Exception class names never captured. An
     *                               entry matches the exception and its
     *                               subclasses.
     */
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Scrubber $scrubber,
        private readonly Container $container,
        private readonly array $ignore,
    ) {}

    /**
     * Capture one exception into the buffer, unless it is the package's own or
     * on the ignore list. `$handled` distinguishes an exception the application
     * caught and reported (true) from one that escaped to the handler (false),
     * which the console shows as the `handled` flag.
     */
    public function capture(Throwable $e, bool $handled = false): void
    {
        if (! $this->shouldCapture($e)) {
            return;
        }

        $this->buffer->add(self::EVENT_TYPE, $this->buildEvent($e, $handled));
    }

    /**
     * Whether this exception is worth capturing: not the package's own fault and
     * not on the configured ignore list.
     */
    private function shouldCapture(Throwable $e): bool
    {
        return ! Recursion::isInternalException($e) && ! $this->isIgnored($e);
    }

    /** Whether the exception matches (by class or subclass) any ignore entry. */
    private function isIgnored(Throwable $e): bool
    {
        foreach ($this->ignore as $class) {
            if (is_string($class) && $class !== '' && $e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the event: the exception's own fields as a scrubbed payload, plus
     * the correlating envelope keys (trace id, user id, timestamp) the server
     * reads off the top of the event (US-027).
     *
     * @return array<string, mixed>
     */
    private function buildEvent(Throwable $e, bool $handled): array
    {
        $payload = $this->scrubber->scrub([
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'frames' => $this->buildFrames($e),
            'route' => $this->currentRoute(),
            'status' => $this->statusFor($e),
            'handled' => $handled ? 1 : 0,
        ]);

        return [
            'timestamp' => now()->toIso8601String(),
            'trace_id' => TraceContext::traceId(),
            'user_id' => $this->currentUserId(),
            'payload' => $payload,
        ];
    }

    /**
     * The full stack trace as a list of frames, each tagged whether it is
     * vendor (library) or application code — the flag the console uses to fold
     * framework noise and surface the app frame that caused the fault.
     *
     * A PHP backtrace pairs each entry's *call-site* file/line with the function
     * it called, so the frames are shifted by one: the throw location
     * ({@see Throwable::getFile()}/{@see Throwable::getLine()}) is the top frame,
     * paired with the function that was executing there, and each subsequent
     * frame pairs a call site with the function containing it.
     *
     * @return list<array<string, mixed>>
     */
    private function buildFrames(Throwable $e): array
    {
        $trace = $e->getTrace();

        $frames = [$this->frame($e->getFile(), $e->getLine(), $this->functionLabel($trace[0] ?? []))];

        foreach ($trace as $i => $entry) {
            $frames[] = $this->frame(
                is_string($entry['file'] ?? null) ? $entry['file'] : '',
                is_int($entry['line'] ?? null) ? $entry['line'] : 0,
                $this->functionLabel($trace[$i + 1] ?? []),
            );
        }

        return $frames;
    }

    /**
     * @return array{file: string, line: int, function: string, vendor: bool}
     */
    private function frame(string $file, int $line, string $function): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'function' => $function,
            'vendor' => $this->isVendorFrame($file),
        ];
    }

    /**
     * The fully-qualified `Class::method` (or bare function) label for a trace
     * entry, empty when the entry is the top-level script.
     *
     * @param  array<string, mixed>  $entry
     */
    private function functionLabel(array $entry): string
    {
        $class = is_string($entry['class'] ?? null) ? $entry['class'] : '';
        $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';
        $function = is_string($entry['function'] ?? null) ? $entry['function'] : '';

        return $class.$type.$function;
    }

    /** Whether a frame's file lives in a package/library directory. */
    private function isVendorFrame(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        return str_contains($file, '/vendor/') || str_contains($file, '/node_modules/');
    }

    /**
     * The matched route as `METHOD uri`, or empty when there is no request or no
     * route matched (a console command or queue job).
     */
    private function currentRoute(): string
    {
        $request = $this->currentRequest();
        $route = $request?->route();

        if (! $request instanceof Request || ! $route instanceof Route) {
            return '';
        }

        return $request->method().' '.$route->uri();
    }

    /**
     * The HTTP status the exception would render as: an {@see
     * HttpExceptionInterface} carries its own, everything else is a 500.
     */
    private function statusFor(Throwable $e): int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        return 500;
    }

    /** The authenticated user id, or null with no bound guard or no user. */
    private function currentUserId(): ?string
    {
        if (! $this->container->bound('auth')) {
            return null;
        }

        $auth = $this->container->make('auth');

        if (! is_object($auth) || ! method_exists($auth, 'id')) {
            return null;
        }

        /** @var mixed $id */
        $id = $auth->id();

        return is_scalar($id) ? (string) $id : null;
    }

    /** The current request, or null outside an HTTP context. */
    private function currentRequest(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        return $request instanceof Request ? $request : null;
    }
}
