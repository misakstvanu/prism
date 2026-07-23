<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Capture;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Container\Container;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\IgnoreList;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Throwable;

/**
 * Turns a scheduled task's run into a buffered `schedule` telemetry event
 * (US-049), mirroring the server's `schedules` columns (US-006): the task's
 * command (or description for a closure), its cron expression, the exit code,
 * duration, peak memory, captured output and the hostname it ran on.
 *
 * {@see PrismServiceProvider::registerScheduleCapture()} wires three listeners
 * to this one singleton, one per lifecycle stage:
 *
 *   - `ScheduledTaskStarting` → {@see recordStart()} stamps the start time and
 *     begins a fresh trace, so any queries or logs the task emits correlate to
 *     the task's own `trace_id`.
 *   - `ScheduledTaskFinished` → {@see recordFinished()} buffers a `schedule`
 *     event with the framework-reported runtime and the task's exit code.
 *   - `ScheduledTaskFailed` → {@see recordFailed()} buffers a `schedule` event
 *     whose output carries the failure reason (the thrown exception) and whose
 *     exit code is non-zero, so a failed run is recorded distinctly (AC3).
 *
 * The scheduler runs tasks sequentially in one process, so per-task timing is
 * keyed by the task's object identity and resolved on its terminal event. The
 * provider flushes the buffer after each terminal event, so a task's telemetry
 * — the `schedule` event plus everything it captured while running — ships at
 * the end of the task rather than accumulating across the `schedule:run` process.
 *
 * A missed tick needs no client signal: the server infers it from the recorded
 * `expression` and the timestamp of the last run (AC5). Both closure and command
 * tasks are covered — the framework fires the same events for either, and the
 * command field falls back to the description a closure task carries (AC4).
 *
 * Every public method is best-effort: a malformed task object can never surface
 * an error into the host, exactly as a telemetry client must behave.
 */
final class ScheduleCapture
{
    /** The telemetry signal name (US-026); the server routes it to `schedules`. */
    private const EVENT_TYPE = 'schedule';

    /**
     * Per-task start times keyed by the task object's identity: the run-start
     * time in microseconds, set on `ScheduledTaskStarting` and consumed on the
     * matching terminal event to compute a duration when the framework does not
     * report one (a failed task carries no runtime).
     *
     * @var array<int, float>
     */
    private array $started = [];

    /**
     * @param  list<string>  $ignore  Command patterns never captured.
     */
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Scrubber $scrubber,
        private readonly Container $container,
        private readonly int $maxOutput,
        private readonly array $ignore = [],
    ) {}

    /**
     * Whether a task is on the configured ignore list, matched on the same
     * command string the captured event carries ({@see command()}) so a pattern
     * is written against the identity the console displays. Wildcards make a
     * family of internal tasks (`prism:*`, `*:cleanup`) one pattern.
     */
    private function isIgnored(ScheduledEvent $task): bool
    {
        return IgnoreList::matches($this->ignore, $this->command($task));
    }

    /**
     * Record the start of a task: stamp the start time keyed by the task's
     * identity and begin a fresh trace so the task's queries and logs correlate
     * to the `schedule` event this run produces.
     */
    public function recordStart(ScheduledTaskStarting $event): void
    {
        try {
            if (Recursion::suppressed() || $this->isIgnored($event->task)) {
                return;
            }

            $this->started[spl_object_id($event->task)] = $this->nowMicros();

            // A fresh trace per task, so anything the task captures while running
            // shares the trace id this run's `schedule` event carries.
            TraceContext::start(null);
        } catch (Throwable) {
            // Telemetry must never surface an error into the host application.
        }
    }

    /**
     * Buffer a `schedule` event for a task that finished, using the runtime the
     * framework reports (seconds) and the task's own exit code.
     */
    public function recordFinished(ScheduledTaskFinished $event): bool
    {
        return $this->record(
            $event->task,
            $this->exitCode($event->task, failed: false),
            $this->durationMs($event->task, round($event->runtime * 1000, 3)),
            $this->readOutput($event->task),
        );
    }

    /**
     * Buffer a `schedule` event for a task that failed or threw: the output
     * carries the failure reason (the thrown exception), and the exit code is
     * non-zero (AC3). No runtime is reported for a failure, so it is computed
     * from the start stamp.
     */
    public function recordFailed(ScheduledTaskFailed $event): bool
    {
        $reason = $event->exception instanceof Throwable ? (string) $event->exception : '';
        $output = trim($this->readOutput($event->task)."\n".$reason);

        return $this->record(
            $event->task,
            $this->exitCode($event->task, failed: true),
            $this->durationMs($event->task, null),
            $output,
        );
    }

    /**
     * Build and buffer one `schedule` event for a terminal outcome. Returns
     * whether an event was recorded, so the provider knows whether to flush the
     * buffer for this task (AC5); a suppressed run records nothing.
     */
    private function record(ScheduledEvent $task, int $exitCode, float $durationMs, string $output): bool
    {
        try {
            if (Recursion::suppressed() || $this->isIgnored($task)) {
                return false;
            }

            unset($this->started[spl_object_id($task)]);

            $this->buffer->add(
                self::EVENT_TYPE,
                $this->buildEvent($task, $exitCode, $durationMs, $output),
            );

            return true;
        } catch (Throwable) {
            // Telemetry must never surface an error into the host application.
            return false;
        }
    }

    /**
     * The event: the task's fields as a scrubbed payload the server spreads over
     * the `schedules` columns, plus the correlating envelope keys.
     *
     * @return array<string, mixed>
     */
    private function buildEvent(ScheduledEvent $task, int $exitCode, float $durationMs, string $output): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'trace_id' => TraceContext::traceId(),
            'request_id' => '',
            'user_id' => $this->currentUserId(),
            'payload' => $this->scrubber->scrub([
                'command' => $this->command($task),
                'expression' => is_string($task->expression) ? $task->expression : '',
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs,
                'memory_mb' => $this->peakMemoryMb(),
                'output' => $this->truncate($output),
                'host' => gethostname() ?: 'unknown',
            ]),
        ];
    }

    /**
     * The task's identifying command: its human description if set, otherwise the
     * command string, otherwise `Closure` for an unnamed closure task (AC4). This
     * is the same identity the framework surfaces in `schedule:list`, minus the
     * shell output-redirection its `getSummaryForDisplay()` appends to a bare
     * command — the raw command reads cleaner in the console.
     */
    private function command(ScheduledEvent $task): string
    {
        if (is_string($task->description) && $task->description !== '') {
            return $task->description;
        }

        if (is_string($task->command) && $task->command !== '') {
            return $task->command;
        }

        return 'Closure';
    }

    /**
     * The task's exit code: the code the framework recorded, or a sensible
     * default — non-zero for a failure, zero for a clean finish — when the task
     * exposes none.
     */
    private function exitCode(ScheduledEvent $task, bool $failed): int
    {
        if (is_int($task->exitCode)) {
            return $task->exitCode;
        }

        return $failed ? 1 : 0;
    }

    /**
     * The task's duration in milliseconds: the value the caller passes when the
     * framework reports one (a finished task), otherwise computed from the start
     * stamp (a failed task, which carries no runtime). Zero when neither is known.
     */
    private function durationMs(ScheduledEvent $task, ?float $reported): float
    {
        if ($reported !== null) {
            return max(0.0, $reported);
        }

        $started = $this->started[spl_object_id($task)] ?? null;

        if ($started === null) {
            return 0.0;
        }

        return max(0.0, round(($this->nowMicros() - $started) / 1000, 3));
    }

    /**
     * The task's captured output, read from the file the task streamed its output
     * to. A task with no output destination (the default `/dev/null`) or an
     * unreadable one contributes nothing rather than failing.
     */
    private function readOutput(ScheduledEvent $task): string
    {
        $path = $task->output;

        if (! is_string($path) || $path === '' || $path === '/dev/null' || $path === 'NUL') {
            return '';
        }

        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $contents = @file_get_contents($path);

        return is_string($contents) ? $contents : '';
    }

    /**
     * Truncate captured output to the configured byte size so a chatty task can
     * never bloat a batch. Zero keeps the whole output uncapped.
     */
    private function truncate(string $output): string
    {
        if ($this->maxOutput > 0 && strlen($output) > $this->maxOutput) {
            return substr($output, 0, $this->maxOutput);
        }

        return $output;
    }

    /** The process's peak memory in megabytes. */
    private function peakMemoryMb(): float
    {
        return round(memory_get_peak_usage(true) / 1_048_576, 3);
    }

    /** Current time in microseconds, honouring Carbon's test clock. */
    private function nowMicros(): float
    {
        return (float) now()->getPreciseTimestamp(6);
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
