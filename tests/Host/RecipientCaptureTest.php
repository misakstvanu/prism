<?php

declare(strict_types=1);

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Delivery\RecipientRecorder;
use Misakstvanu\Prism\Transport\Transport;

/**
 * Who an outbound message was addressed to, captured by Prism rather than by
 * the engine.
 *
 * The capture engine reports a message by mailer, class, subject and three
 * recipient *counts*, and a notification by channel and class. Neither says who
 * anything went to, so Prism holds the names from the framework's `…Sending`
 * events and stamps them onto the record upstream writes from the matching
 * `…Sent` one.
 *
 * **Every assertion drives a real request through the real HTTP kernel** and
 * reads the **transmitted batch**, for the reason the body-capture suite gives:
 * three separate things have to line up across one execution — a listener
 * registered on the event that fires *before* upstream writes its record, a
 * holder keyed the way the record's own `class` field is written, and an ingest
 * that looks the entry up. A test that called the recorder directly would pass
 * for any of the three being wrong.
 *
 * The mailer is `array`, deliberately not `Mail::fake()`: the fake swaps the
 * mailer out and dispatches neither event, so a suite built on it would assert
 * against a send that never happened.
 */
beforeEach(function () {
    $this->transport = new RecipientRecordingTransport;

    // The application booted with the real transport as a singleton, so the
    // recorder goes in afterwards — a lifecycle test that quietly ran against a
    // real `HttpTransport` would read as "no telemetry was produced" rather
    // than as a broken double.
    app()->instance(Transport::class, $this->transport);

    config([
        'mail.default' => 'array',
        'prism.scrub' => ['password', 'token'],
    ]);
});

it('records the three address lists of a message', function () {
    Route::get('/prism-mail-probe', function () {
        Mail::to('ada@probe.test')
            ->cc(['grace@probe.test', 'alan@probe.test'])
            ->bcc('audit@probe.test')
            ->send(new RecipientProbeMail);

        return response('sent', 200, ['Content-Type' => 'text/plain']);
    });

    $this->get('/prism-mail-probe')->assertOk();

    $payload = shippedDeliveryPayloads('mail')[0];

    /** @var array<string, list<string>> $recipients */
    $recipients = json_decode((string) $payload['recipients'], true);

    expect($recipients['to'])->toBe(['ada@probe.test'])
        ->and($recipients['cc'])->toBe(['grace@probe.test', 'alan@probe.test'])
        ->and($recipients['bcc'])->toBe(['audit@probe.test']);

    // And it is on the row whose counts it explains, which is the pairing the
    // whole holder exists to get right.
    expect($payload['to'])->toBe(1)
        ->and($payload['cc'])->toBe(2)
        ->and($payload['class'])->toBe(RecipientProbeMail::class);
});

it('gives each message of one class its own recipients', function () {
    // The match rule, and the reason it is a FIFO rather than a map: two sends
    // of one class inside one execution share every field the record carries,
    // so only the order they were sent in can tell their addresses apart.
    Route::get('/prism-mail-order-probe', function () {
        Mail::to('first@probe.test')->send(new RecipientProbeMail);
        Mail::to('second@probe.test')->send(new RecipientProbeMail);

        return response('sent', 200, ['Content-Type' => 'text/plain']);
    });

    $this->get('/prism-mail-order-probe')->assertOk();

    $payloads = shippedDeliveryPayloads('mail');

    expect($payloads)->toHaveCount(2)
        ->and($payloads[0]['recipients'])->toContain('first@probe.test')
        ->and($payloads[0]['recipients'])->not->toContain('second@probe.test')
        ->and($payloads[1]['recipients'])->toContain('second@probe.test');
});

it('redacts addresses by key and keeps the list as long as its count', function () {
    config(['prism.scrub' => ['email']]);

    Route::get('/prism-mail-scrub-probe', function () {
        Mail::to(['ada@probe.test', 'grace@probe.test'])->send(new RecipientProbeMail);

        return response('sent', 200, ['Content-Type' => 'text/plain']);
    });

    $this->get('/prism-mail-scrub-probe')->assertOk();

    $payload = shippedDeliveryPayloads('mail')[0];

    /** @var array<string, list<string>> $recipients */
    $recipients = json_decode((string) $payload['recipients'], true);

    // One placeholder per address, never one for the whole list: the counts
    // are stored in the same row, and a list shorter than its own count would
    // read as a message that went to fewer people than it did.
    expect($recipients['to'])->toBe(['[REDACTED]', '[REDACTED]'])
        ->and($payload['to'])->toBe(2)
        ->and((string) $payload['recipients'])->not->toContain('ada@probe.test');
});

it('records what a notification was addressed to and where its channel routed', function () {
    Route::get('/prism-notification-probe', function () {
        NotificationFacade::send(new RecipientProbeUser, new RecipientProbeNotification);

        return response('sent', 200, ['Content-Type' => 'text/plain']);
    });

    $this->get('/prism-notification-probe')->assertOk();

    $payload = shippedDeliveryPayloads('notification')[0];

    expect($payload['channel'])->toBe('mail')
        ->and($payload['notifiable'])->toBe(RecipientProbeUser::class.'#41')
        ->and($payload['recipients'])->toBe('["user@probe.test"]');

    // The message the mail channel then sent is a NOTIFICATION, and upstream
    // writes no `mail` record for one — so an entry held for it would be
    // claimed by the next real send of that class instead.
    expect(shippedDeliveryPayloads('mail'))->toBeEmpty();
});

it('does not let a notification\'s own mail be claimed by a later message', function () {
    // Upstream's mail sensor refuses a message carrying `__laravel_notification`
    // and writes no `mail` record for one, so an entry held for it could only
    // ever be claimed by the next real send of the same class — and a raw send
    // has the same empty class every notification mail would.
    Route::get('/prism-notification-then-raw-probe', function () {
        NotificationFacade::route('mail', 'ondemand@probe.test')
            ->notify(new RecipientProbeNotification);

        Mail::raw('plain body', function ($message) {
            $message->to('raw@probe.test')->subject('Raw probe');
        });

        return response('sent', 200, ['Content-Type' => 'text/plain']);
    });

    $this->get('/prism-notification-then-raw-probe')->assertOk();

    $payload = shippedDeliveryPayloads('mail')[0];

    expect($payload['recipients'])->toContain('raw@probe.test')
        ->and($payload['recipients'])->not->toContain('ondemand@probe.test');
});

it('reads an on-demand notifiable as its class alone', function () {
    Route::get('/prism-anonymous-probe', function () {
        NotificationFacade::route('mail', 'ondemand@probe.test')
            ->notify(new RecipientProbeNotification);

        return response('sent', 200, ['Content-Type' => 'text/plain']);
    });

    $this->get('/prism-anonymous-probe')->assertOk();

    $payload = shippedDeliveryPayloads('notification')[0];

    // No key, so no `#` — a bare hash would claim an identity that does not
    // exist.
    expect($payload['notifiable'])->toBe('Illuminate\Notifications\AnonymousNotifiable')
        ->and($payload['recipients'])->toBe('["ondemand@probe.test"]');
});

it('records nothing when the switches are off', function () {
    config([
        'prism.mail.capture_recipients' => false,
        'prism.notification.capture_recipients' => false,
    ]);

    Route::get('/prism-off-probe', function () {
        Mail::to('ada@probe.test')->send(new RecipientProbeMail);
        NotificationFacade::send(new RecipientProbeUser, new RecipientProbeNotification);

        return response('sent', 200, ['Content-Type' => 'text/plain']);
    });

    $this->get('/prism-off-probe')->assertOk();

    // ABSENT, not empty: a key that is not in the payload leaves the column at
    // its own `DEFAULT ''` rather than asserting an empty list was observed.
    expect(shippedDeliveryPayloads('mail')[0])->not->toHaveKey('recipients');

    $notification = shippedDeliveryPayloads('notification')[0];

    expect($notification)->not->toHaveKey('recipients')
        ->and($notification)->not->toHaveKey('notifiable');
});

it('puts the names on the two delivery events and on nothing else', function () {
    Route::get('/prism-scope-probe', function () {
        Mail::to('ada@probe.test')->send(new RecipientProbeMail);

        return response('sent', 200, ['Content-Type' => 'text/plain']);
    });

    $this->get('/prism-scope-probe')->assertOk();

    $others = [];

    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            if ($event['type'] !== 'mail' && $event['type'] !== 'notification') {
                $others[] = $event;
            }
        }
    }

    expect($others)->not->toBeEmpty();

    foreach ($others as $event) {
        expect($event['payload'])->not->toHaveKey('recipients')
            ->and($event['payload'])->not->toHaveKey('notifiable');
    }
});

it('caps a list and leaves the count reporting the true width', function () {
    $recipients = [];

    for ($i = 0; $i < 60; $i++) {
        $recipients[] = "bulk{$i}@probe.test";
    }

    Route::get('/prism-cap-probe', function () use ($recipients) {
        Mail::to($recipients)->send(new RecipientProbeMail);

        return response('sent', 200, ['Content-Type' => 'text/plain']);
    });

    $this->get('/prism-cap-probe')->assertOk();

    $payload = shippedDeliveryPayloads('mail')[0];

    /** @var array<string, list<string>> $decoded */
    $decoded = json_decode((string) $payload['recipients'], true);

    expect($decoded['to'])->toHaveCount(50)
        // The row still says how wide the send really was, which is what makes
        // the cap readable rather than a quiet lie.
        ->and($payload['to'])->toBe(60);
});

it('keys the holder the way the record spells its class', function () {
    // The one thing the two sides share. Upstream restricts the field to 255
    // bytes and strips an anonymous class's `@anonymous\0…` suffix, so the key
    // restates both — a key that did not match would hold an entry nothing
    // could ever claim, which is silent.
    $anonymous = new class extends Notification {};

    expect(RecipientRecorder::notificationClass($anonymous))
        ->toBe(explode("\0", $anonymous::class)[0])
        ->and(RecipientRecorder::keyFor('mail', str_repeat('a', 300)))
        ->toBe('mail|'.str_repeat('a', 255));
});

/**
 * The payloads of every event of one delivery signal the last exchange shipped,
 * in the order they were sent.
 *
 * @return list<array<string, mixed>>
 */
function shippedDeliveryPayloads(string $type): array
{
    $payloads = [];

    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            if ($event['type'] === $type) {
                $payloads[] = $event['payload'];
            }
        }
    }

    return $payloads;
}

/** A mailable with a body of its own, so no view has to exist. */
final class RecipientProbeMail extends Mailable
{
    public function build(): self
    {
        return $this->subject('Probe subject')->html('<p>probe</p>');
    }
}

/** A notifiable with a key and a mail route, and no database behind either. */
final class RecipientProbeUser
{
    use Notifiable;

    public string $email = 'user@probe.test';

    public function getKey(): int
    {
        return 41;
    }
}

/** A notification that goes over the mail channel and nowhere else. */
final class RecipientProbeNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Probe notification')->line('probe');
    }
}

/** Records the batches handed to it instead of sending them. */
final class RecipientRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
