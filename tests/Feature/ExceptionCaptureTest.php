<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\ExceptionCapture;
use Misakstvanu\Prism\Prism;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A throwable used to exercise the configurable ignore list. Named apart from
 * every other test file's globals because Pest loads them all into one process
 * and would fatal on a redeclaration.
 */
class IgnorableTestException extends RuntimeException {}

/**
 * A transport double that records the envelopes handed to it instead of sending
 * them, so a full request's flush can be asserted without touching the network.
 * Uniquely named for the same one-process redeclare reason.
 */
class ExceptionCaptureTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}

/**
 * Reconfigure the app with full credentials and re-boot so registerCapture()
 * binds the ExceptionCapture singleton and registerExceptionCapture() hooks the
 * reportable callback onto the host's exception handler. The recursion and trace
 * state is reset first so a scope left open by another test file cannot leak in.
 *
 * @param  array<string, mixed>  $overrides
 */
function bootExceptions(array $overrides = []): void
{
    config(array_merge([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'sync',
        'prism.batch.queue_threshold' => 0,
        'prism.capture.exceptions' => true,
        // Replica health sampling (US-051) piggybacks a metric onto every flush;
        // disabled here so it never inflates this file's exact event counts.
        'prism.capture.metrics' => false,
    ], $overrides));

    Recursion::reset();
    TraceContext::reset();

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(Scrubber::class);
    app()->forgetInstance(ExceptionCapture::class);

    (new PrismServiceProvider(app()))->boot();
}

/**
 * The first buffered exception event, or null when none was captured.
 *
 * @return array<string, mixed>|null
 */
function bufferedException(): ?array
{
    return app(EventBuffer::class)->all()['exception'][0] ?? null;
}

// -- AC1/AC2: automatic capture through the host handler --------------------

it('hooks the host handler and captures a reported exception, leaving default logging intact', function () {
    bootExceptions();
    Log::spy();

    report(new RuntimeException('database is on fire'));

    // Prism captured it without the user editing bootstrap/app.php...
    expect(app(EventBuffer::class)->count())->toBe(1);

    // ...and the host's own reporting still ran, because the callback returns
    // nothing rather than false.
    Log::shouldHaveReceived('error')->once();
});

it('captures class, message, file, line, route, status, handled flag and correlation ids', function () {
    bootExceptions();

    $e = new RuntimeException('payment gateway timed out');
    app(ExceptionCapture::class)->capture($e, handled: false);

    $event = bufferedException();
    expect($event)->not->toBeNull();

    $payload = $event['payload'];

    expect($payload['class'])->toBe(RuntimeException::class)
        ->and($payload['message'])->toBe('payment gateway timed out')
        ->and($payload['file'])->toBe($e->getFile())
        ->and($payload['line'])->toBe($e->getLine())
        ->and($payload['status'])->toBe(500)
        ->and($payload['handled'])->toBe(0)
        ->and($payload['route'])->toBe('') // no matched route outside a request
        ->and($payload['frames'])->toBeArray()->not->toBeEmpty();

    // The event envelope carries what the console correlates and attributes on.
    expect($event['trace_id'])->toBeString()->not->toBe('')
        ->and($event['timestamp'])->toBeString()
        ->and($event)->toHaveKey('user_id');
});

it('reads the HTTP status off an HttpException instead of defaulting to 500', function () {
    bootExceptions();

    app(ExceptionCapture::class)->capture(
        new HttpException(503, 'maintenance'),
        handled: false,
    );

    expect(bufferedException()['payload']['status'])->toBe(503);
});

// -- Stack trace with vendor/app frame flags --------------------------------

it('records the full stack trace with a vendor/app flag on every frame', function () {
    bootExceptions();

    app(ExceptionCapture::class)->capture(new RuntimeException('trace me'));

    $frames = bufferedException()['payload']['frames'];

    expect($frames)->toBeArray()->not->toBeEmpty();

    foreach ($frames as $frame) {
        expect($frame)->toHaveKeys(['file', 'line', 'function', 'vendor'])
            ->and($frame['vendor'])->toBeBool();
    }

    // The throw point is this test file — application code, not a vendor frame.
    expect($frames[0]['file'])->toBe(__FILE__)
        ->and($frames[0]['vendor'])->toBeFalse();

    // Deeper frames run through the test runner under vendor/, so the flag
    // genuinely distinguishes the two.
    $hasVendorFrame = false;
    foreach ($frames as $frame) {
        $hasVendorFrame = $hasVendorFrame || $frame['vendor'] === true;
    }
    expect($hasVendorFrame)->toBeTrue();
});

// -- AC3: configurable ignore list ------------------------------------------

it('ignores NotFoundHttpException and ValidationException by default', function () {
    bootExceptions();

    Prism::captureException(new NotFoundHttpException('no such page'));
    Prism::captureException(ValidationException::withMessages(['email' => 'required']));

    expect(app(EventBuffer::class)->count())->toBe(0);
});

it('honours a custom exception added to the ignore list', function () {
    bootExceptions(['prism.ignore.exceptions' => [IgnorableTestException::class]]);

    Prism::captureException(new IgnorableTestException('routine, skip me'));
    expect(app(EventBuffer::class)->count())->toBe(0);

    // A non-ignored exception is still captured.
    Prism::captureException(new RuntimeException('a real fault'));
    expect(app(EventBuffer::class)->count())->toBe(1);
});

// -- AC4: manual reporting --------------------------------------------------

it('captures a handled exception through Prism::captureException with handled = 1', function () {
    bootExceptions();

    Prism::captureException(new RuntimeException('caught and reported by hand'));

    $payload = bufferedException()['payload'];

    expect($payload['handled'])->toBe(1)
        ->and($payload['message'])->toBe('caught and reported by hand');
});

it('is a safe no-op when the client is inert', function () {
    // No bootExceptions(): capture is never wired, so ACTIVE is unbound and the
    // buffer never resolved. The call must simply do nothing.
    app()->forgetInstance(PrismServiceProvider::ACTIVE);

    Prism::captureException(new RuntimeException('nowhere to send'));

    expect(app()->bound(PrismServiceProvider::ACTIVE))->toBeFalse()
        ->and(app()->resolved(EventBuffer::class))->toBeFalse();
});

// -- Recursion guard: never report the package's own faults -----------------

it('never captures an exception surfacing during the package\'s own work', function () {
    bootExceptions();

    $capture = app(ExceptionCapture::class);

    // A fault thrown while Prism is flushing is suppressed, so capturing it must
    // be a no-op — otherwise shipping a batch would feed the buffer it drains.
    Recursion::suppress(function () use ($capture) {
        $capture->capture(new RuntimeException('error while flushing'));
    });

    expect(app(EventBuffer::class)->count())->toBe(0);
});

// -- AC5: the host's error response is unchanged ----------------------------

it('captures an uncaught request exception without altering the host error response', function () {
    bootExceptions();
    Log::spy();

    $transport = new ExceptionCaptureTransport;
    app()->instance(Transport::class, $transport);

    Route::get('/exploding', function () {
        throw new RuntimeException('unhandled inside a request');
    });

    $response = $this->get('/exploding');

    // The host still renders its normal 500 — Prism only observed.
    $response->assertStatus(500);

    // And the exception shipped after the response terminated, tagged with the
    // matched route and as unhandled.
    expect($transport->sent)->toHaveCount(1);

    $events = $transport->sent[0]['events'];

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('exception')
        ->and($events[0]['payload']['class'])->toBe(RuntimeException::class)
        ->and($events[0]['payload']['route'])->toBe('GET exploding')
        ->and($events[0]['payload']['handled'])->toBe(0);
});

// -- Per-domain toggle ------------------------------------------------------

it('registers no reportable hook when exception capture is disabled', function () {
    bootExceptions(['prism.capture.exceptions' => false]);
    Log::spy();

    report(new RuntimeException('the toggle is off'));

    // The manual entry point is still bound, but nothing was auto-captured...
    expect(app()->bound(ExceptionCapture::class))->toBeTrue()
        ->and(app(EventBuffer::class)->count())->toBe(0);

    // ...and the host's default logging ran, untouched.
    Log::shouldHaveReceived('error')->once();
});
