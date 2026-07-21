<?php

use Illuminate\Support\Facades\Log;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\PrismLogHandler;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;
use Monolog\Logger as MonologLogger;

/**
 * Point the default log channel at a real single-file Monolog channel writing to
 * a throwaway temp file, so the "host destinations still receive everything" AC
 * can be proved against an actual file rather than a spy. Returns the file path.
 */
function prismLogFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'prism_log_');

    config([
        'logging.default' => 'prismtest',
        'logging.channels.prismtest' => [
            'driver' => 'single',
            // The Monolog logger name becomes $record->channel; Laravel defaults
            // it to the app env, so name it explicitly to make the assertion
            // deterministic and to document what the captured "channel" is.
            'name' => 'prismtest',
            'path' => $path,
            'level' => 'debug',
        ],
    ]);

    Log::forgetChannel('prismtest');

    return $path;
}

/**
 * Reconfigure the app with full credentials and re-boot so registerCapture()
 * binds the PrismLogHandler singleton and pushes it onto the configured channels.
 * Named apart from the other files' boot helpers so Pest's one-process run does
 * not redeclare a global function.
 *
 * @param  array<string, mixed>  $overrides
 */
function bootLogs(array $overrides = []): void
{
    config(array_merge([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'sync',
        'prism.batch.queue_threshold' => 0,
        'prism.capture.logs' => true,
    ], $overrides));

    Recursion::reset();
    TraceContext::reset();

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(Scrubber::class);
    app()->forgetInstance(PrismLogHandler::class);

    (new PrismServiceProvider(app()))->boot();
}

/**
 * The buffered log events.
 *
 * @return list<array<string, mixed>>
 */
function bufferedLogs(): array
{
    return app(EventBuffer::class)->all()['log'] ?? [];
}

// -- AC1: a Monolog handler is registered on the configured channels ----------

it('registers a Monolog handler on the default channel', function () {
    prismLogFile();
    bootLogs();

    $monolog = Log::channel()->getLogger();
    expect($monolog)->toBeInstanceOf(MonologLogger::class);

    $hasPrismHandler = collect($monolog->getHandlers())
        ->contains(fn ($handler) => $handler instanceof PrismLogHandler);

    expect($hasPrismHandler)->toBeTrue();
});

it('registers only on the named channels when channels are configured', function () {
    prismLogFile();

    config([
        'logging.channels.secondary' => [
            'driver' => 'single',
            'path' => tempnam(sys_get_temp_dir(), 'prism_secondary_'),
            'level' => 'debug',
        ],
    ]);
    Log::forgetChannel('secondary');

    bootLogs(['prism.log.channels' => ['secondary']]);

    $onSecondary = collect(Log::channel('secondary')->getLogger()->getHandlers())
        ->contains(fn ($handler) => $handler instanceof PrismLogHandler);
    $onDefault = collect(Log::channel()->getLogger()->getHandlers())
        ->contains(fn ($handler) => $handler instanceof PrismLogHandler);

    expect($onSecondary)->toBeTrue()
        ->and($onDefault)->toBeFalse();

    // A line on the named channel is captured; one on the default channel is not.
    Log::channel('secondary')->info('watched');
    Log::info('unwatched');

    $logs = bufferedLogs();
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['payload']['message'])->toBe('watched');
});

// -- AC2: level, message, channel and scrubbed context are captured ----------

it('captures level, message, channel and scrubbed context with correlation ids', function () {
    prismLogFile();
    bootLogs();

    Log::warning('disk almost full', ['free_mb' => 128, 'password' => 'hunter2']);

    $logs = bufferedLogs();
    expect($logs)->toHaveCount(1);

    $event = $logs[0];
    $payload = $event['payload'];

    expect($payload['level'])->toBe('warning')
        ->and($payload['message'])->toBe('disk almost full')
        ->and($payload['channel'])->toBe('prismtest')
        ->and($payload['context']['free_mb'])->toBe(128)
        ->and($payload['context']['password'])->toBe(Scrubber::REDACTED);

    // The envelope carries what the console correlates and attributes on.
    expect($event['trace_id'])->toBeString()->not->toBe('')
        ->and($event['timestamp'])->toBeString()
        ->and($event)->toHaveKey('user_id');
});

// -- AC3: the minimum level is configurable, defaulting to debug --------------

it('captures every level by default (minimum debug)', function () {
    prismLogFile();
    bootLogs();

    Log::debug('a debug line');
    Log::info('an info line');

    expect(bufferedLogs())->toHaveCount(2);
});

it('drops records below a configured minimum level', function () {
    prismLogFile();
    bootLogs(['prism.log.level' => 'warning']);

    Log::debug('too quiet');
    Log::info('still too quiet');
    Log::warning('loud enough');
    Log::error('definitely loud');

    $logs = bufferedLogs();
    expect($logs)->toHaveCount(2)
        ->and($logs[0]['payload']['level'])->toBe('warning')
        ->and($logs[1]['payload']['level'])->toBe('error');
});

// -- AC4: the host's own destinations still receive everything ---------------

it('leaves the host file log destination unaffected', function () {
    $file = prismLogFile();
    bootLogs();

    Log::info('written to both prism and the file');

    // Prism captured it...
    expect(bufferedLogs())->toHaveCount(1);

    // ...and the host's file channel still wrote it, because the handler bubbles.
    $contents = file_get_contents($file);
    expect($contents)->toContain('written to both prism and the file');
});

// -- AC5: Prism's own internal logging is excluded ---------------------------

it('never captures a log emitted during the package\'s own work', function () {
    prismLogFile();
    bootLogs();

    // The flush and send wrap their own debug lines in a suppression scope.
    Recursion::suppress(fn () => Log::debug('Prism flush failed: boom'));

    expect(bufferedLogs())->toBeEmpty();

    // A normal application log after the scope is still captured.
    Log::info('back to normal');
    expect(bufferedLogs())->toHaveCount(1);
});

// -- Per-domain toggle -------------------------------------------------------

it('registers nothing when log capture is disabled', function () {
    prismLogFile();
    bootLogs(['prism.capture.logs' => false]);

    Log::info('should not be captured');

    $onDefault = collect(Log::channel()->getLogger()->getHandlers())
        ->contains(fn ($handler) => $handler instanceof PrismLogHandler);

    expect(app()->bound(PrismLogHandler::class))->toBeFalse()
        ->and($onDefault)->toBeFalse()
        ->and(app(EventBuffer::class)->count())->toBe(0);
});
