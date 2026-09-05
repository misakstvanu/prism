<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Misakstvanu\Prism\Support\Text;
use Misakstvanu\Prism\Tests\ConsoleHostTestCase;
use Misakstvanu\Prism\Transport\Transport;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * What a command row says it was invoked with (US-015).
 *
 * `backup:run --only-db --disk=s3` and a bare `backup:run` are two different
 * runs, and the `commands.command` column is where the difference lives. The
 * question the story opened with was whether the capture engine reports it at
 * all — and it does: upstream's command sensor builds the field from the input
 * the console kernel handed it, which for a real CLI run is the raw argv
 * tokens. So Prism needs no holder of its own here, the shape a job payload or
 * an HTTP body needs; what it adds is the cap upstream has nowhere, and the
 * proof that the whole chain — real listeners, real redaction, real translation
 * — puts the line on the wire.
 *
 * Every case drives a **real artisan run** through the console kernel, and
 * reads the **transmitted batch**. Three things make that the only honest
 * shape:
 *
 *   - **The record does not exist unless the console hooks registered.** They
 *     are wired by upstream's `CommandStarting` listener, onto the console
 *     kernel, from a register-time decision this suite's own case exists for
 *     (see {@see ConsoleHostTestCase}). A hand-driven
 *     `Core::command()` would assert on a record no real run produces the same
 *     way.
 *   - **The input object is the whole question.** Upstream reads an `ArgvInput`
 *     one way and everything else another, and only the kernel decides which
 *     one the sensor sees.
 *   - **A command record is written AFTER the app terminates.** `Kernel::terminate()`
 *     calls `$app->terminate()` — where Prism's flusher drains the buffer —
 *     and only then runs the lifecycle handler that writes the record and
 *     digests it. So the event leaves in a batch of its own, and the buffer is
 *     empty by the time a test could read it: the transport is the only place
 *     the line can be observed.
 */
beforeEach(function () {
    config(['prism.scrub' => ['password']]);

    $this->transport = new CommandLineRecordingTransport;

    app()->instance(Transport::class, $this->transport);

    // Laravel bridges Symfony's console events onto `CommandStarting` /
    // `CommandFinished` from a `booted` callback — and skips it outright when
    // `runningUnitTests()`, which is every process this suite runs in. Without
    // the bridge the engine's `CommandStarting` listener never fires, so no
    // console hooks are registered, no lifecycle handler is installed on the
    // kernel and no command record is ever written: the run would succeed and
    // capture nothing. It must be re-installed BEFORE anything resolves the
    // Artisan application, because the dispatcher is attached only where that
    // application is first built — which `registerCommand()` below does.
    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();

    app(ConsoleKernel::class)->registerCommand(new CommandLineProbeCommand);
});

it('ships the whole command line a real artisan run was invoked with', function () {
    commandLineRun(['artisan', 'prism:probe', '41', '--only-db', '--disk=s3']);

    // The arguments and the options, in the order they were typed — which is
    // what makes two runs of one command distinguishable at all.
    expect(commandLineShipped())->toBe('prism:probe 41 --only-db --disk=s3');
});

it('redacts an option value against prism.scrub', function () {
    commandLineRun(['artisan', 'prism:probe', '41', '--password=hunter2-cli']);

    $line = commandLineShipped();

    // The engine applies Prism's `redactCommand` callback to the record before
    // the resolver builds the event, so the secret is never transmitted — while
    // the option's own name survives, so the run is still readable.
    expect($line)->not->toContain('hunter2-cli')
        ->and($line)->toContain('--password=[REDACTED]')
        ->and($line)->toContain('41');
});

it('caps the line and says that it cut one', function () {
    commandLineRun(['artisan', 'prism:probe', str_repeat('x', 5000)]);

    $line = commandLineShipped();

    // 4 KB is far past any line a person types. Upstream caps this nowhere and
    // `commands.command` is a plain `String` column, so without this one run
    // carrying a document as an argument rides the wire and lands in ClickHouse
    // whole.
    expect(strlen($line))->toBe(4096 + strlen(Text::TRUNCATED))
        ->and($line)->toStartWith('prism:probe x')
        ->and($line)->toEndWith(Text::TRUNCATED);
});

/**
 * Run one artisan command exactly as `php artisan` does: the kernel handles an
 * `ArgvInput` and is then terminated with it, which is the call upstream's
 * lifecycle handler hangs off.
 *
 * One run per test on purpose. `configureCommandSampling()` consults Laravel's
 * Context before the configured rate, and the previous run wrote its verdict
 * there — so a second command in one test would inherit the first's decision
 * and pass for a reason that is not the one under test.
 *
 * @param  list<string>  $argv
 */
function commandLineRun(array $argv): void
{
    $kernel = app(ConsoleKernel::class);

    $input = new ArgvInput($argv);

    $kernel->terminate($input, $kernel->handle($input, new NullOutput));
}

/** The `command` column value on the one `command` event that was transmitted. */
function commandLineShipped(): string
{
    /** @var CommandLineRecordingTransport $transport */
    $transport = test()->transport;

    $lines = [];

    foreach ($transport->sent as $envelope) {
        /** @var list<array<string, mixed>> $events */
        $events = $envelope['events'];

        foreach ($events as $event) {
            if (($event['type'] ?? null) !== 'command') {
                continue;
            }

            /** @var array<string, mixed> $payload */
            $payload = $event['payload'];

            $lines[] = (string) ($payload['command'] ?? '');
        }
    }

    // Non-vacuous: a chain that captured nothing would otherwise be read as a
    // line that merely does not contain the secret.
    expect($lines)->toHaveCount(1);

    return $lines[0];
}

/** A command with an argument and two options, so there is a line to report. */
final class CommandLineProbeCommand extends Command
{
    protected $signature = 'prism:probe {tenant?} {--only-db} {--disk=} {--password=}';

    protected $description = 'Does nothing; exists so a real artisan run can be captured.';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

/**
 * A transport that records envelopes instead of sending them. Named apart from
 * the equivalents elsewhere in the suite because Pest loads every test file
 * into one process, where a redeclared class is fatal.
 */
final class CommandLineRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
