<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Capture;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * A Monolog handler that turns every log record into a buffered `log` telemetry
 * event (US-044).
 *
 * {@see PrismServiceProvider::registerLogCapture()} pushes one shared instance of
 * this handler onto the Monolog logger of each configured channel (the app's
 * default channel/stack when none is named), so the host's own log output is
 * captured with no `bootstrap/app.php` or `config/logging.php` edit. The handler
 * bubbles ({@see AbstractProcessingHandler}'s default), so the channel's existing
 * destinations — the file, syslog, Slack, whatever the host configured — keep
 * receiving every record unchanged; Prism only observes.
 *
 * The minimum level is the handler's own, taken from `prism.log.level` (default
 * `debug`): {@see AbstractProcessingHandler::isHandling()} filters records below
 * it before {@see write()} is ever called.
 *
 * The recorded payload mirrors the server's `logs` columns (US-006): level,
 * message, channel and the structured context, all run through the
 * {@see Scrubber} so a secret in the context never leaves the process. The
 * current trace id and authenticated user id ride the event envelope so the
 * console can correlate and attribute the line.
 *
 * Prism's own internal logging is never captured: the flush and send emit their
 * debug lines inside a {@see Recursion::suppress()} scope, so {@see write()}
 * short-circuits when the guard is raised — otherwise shipping a batch would feed
 * the buffer the next flush drains.
 */
final class PrismLogHandler extends AbstractProcessingHandler
{
    /** The telemetry signal name (US-026); the server routes it to `logs`. */
    private const EVENT_TYPE = 'log';

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Scrubber $scrubber,
        private readonly Container $container,
        int|string|Level $level = Level::Debug,
    ) {
        parent::__construct($level, bubble: true);
    }

    /**
     * Buffer one log record, unless it was emitted while the package is doing its
     * own work. Monolog has already filtered records below the configured minimum
     * level, so every record reaching here is worth capturing.
     */
    protected function write(LogRecord $record): void
    {
        if (Recursion::suppressed()) {
            return;
        }

        $this->buffer->add(self::EVENT_TYPE, $this->buildEvent($record));
    }

    /**
     * Build the event: the record's own fields as a scrubbed payload, plus the
     * correlating envelope keys (timestamp, trace id, user id) the server reads
     * off the top of the event (US-027). The timestamp is the record's own so a
     * deferred flush cannot skew when the line was written.
     *
     * @return array<string, mixed>
     */
    private function buildEvent(LogRecord $record): array
    {
        $payload = $this->scrubber->scrub([
            'level' => $record->level->toPsrLogLevel(),
            'message' => $record->message,
            'channel' => $record->channel,
            'context' => $record->context,
        ]);

        return [
            'timestamp' => Carbon::instance($record->datetime)->toIso8601String(),
            'trace_id' => TraceContext::traceId(),
            'user_id' => $this->currentUserId(),
            'payload' => $payload,
        ];
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
}
