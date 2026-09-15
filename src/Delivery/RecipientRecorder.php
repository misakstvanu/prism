<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Delivery;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Misakstvanu\Prism\Http\BodyRecorder;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Queue\JobPayloadRecorder;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\Text;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Who an outbound message was addressed to, held until the record describing it is written — the gap {@see
 * BodyRecorder} fills for an HTTP exchange and {@see JobPayloadRecorder} for a job's arguments. The capture
 * engine has none: Nightwatch's `mail` record carries the mailer, mailable class, subject and three
 * *counts*, its `notification` record the channel and class, and neither names a recipient, which no count
 * answers for. A **holder, not a listener**, for {@see BodyRecorder}'s reason — addresses are in hand at
 * `MessageSending` / `NotificationSending` and the record follows later, from upstream's own listener on
 * the matching `…Sent` event, so {@see PrismIngest::stampRecipients()} closes the gap.
 *
 * **The match rule is the whole trap.** A record carries no id of any kind (a job has one both sides agree
 * on), so holder and record share only the mailable / notification **class**. Within one execution the
 * framework sends synchronously and in order (`Sending` then `Sent`, one pair at a time), so a per-class FIFO
 * consumed in order gives two sends of one class in one request a list each. Four rules decide what is held:
 *   - **A notification's own mail is not held as mail**, restating upstream's own test: its mail sensor
 *     returns null for a message carrying `__laravel_notification`, so no `mail` record follows one, and
 *     an entry pushed for it would never be claimed and would mis-pair that class's next real send.
 *   - **Keyed the way the record's `class` field is written**, so the two cannot drift: upstream cuts it to
 *     255 bytes and strips PHP's `@anonymous\0…` suffix, both restated by {@see keyFor()} / {@see
 *     notificationClass()}.
 *   - **Scrubbed, and the list keeps its length**: an address becomes {@see Scrubber::REDACTED}, never
 *     dropped, so it still agrees with the `to` / `cc` / `bcc` counts stored beside it; the list's own
 *     name or {@see ADDRESS_KEY} redacts one — the two names a host puts recipients under on `prism.scrub`.
 *   - **Read once**: {@see take()} releases the entry it answers, a list belonging to exactly one record
 *     and a worker process sending mail until told to stop. {@see LIMIT} is the second half — a send whose
 *     record never arrived (a listener returning false from `MessageSending`, an execution the sampler
 *     discarded) would otherwise sit here for the life of the process.
 */
final class RecipientRecorder
{
    /** The signal a mail message's addresses are held under — the `mail` event type. */
    public const MAIL = 'mail';

    /** The signal a notification's addresses are held under — the `notification` event type. */
    public const NOTIFICATION = 'notification';

    /**
     * The scrub key that redacts an address wherever one is stored: a recipient is an email address in the
     * channel a host is most likely to care about, so `email` is the name they would put on the list, and
     * the list's own name (`to`, `cc`, `bcc`, `recipients`) answers as well.
     */
    private const ADDRESS_KEY = 'email';

    /** How many addresses are kept per list. A send wider than this is a mailing, not a message. */
    private const MAX_ADDRESSES = 50;

    /**
     * How many unclaimed entries may be held per class. Entries are released as read, so at most one is
     * pending in a healthy process; the bound is for the unhealthy one — a send that raised `Sending` and
     * never `Sent` because a listener refused it — dropping the oldest, least likely to still be claimed.
     */
    private const LIMIT = 64;

    /**
     * Columns to stamp, by `"{signal}|{class}"`, each a FIFO in send order.
     * @var array<string, list<array<string, string>>>
     */
    private array $pending = [];

    public function __construct(
        private readonly Repository $config,
        private readonly Scrubber $scrubber,
    ) {}

    /**
     * Hold the three address lists of one outbound message. Guarded end to end: reading a mime message is
     * not worth a host's request, and a failure leaves nothing held, which reads as no recipients captured.
     */
    public function recordMail(MessageSending $event): void
    {
        if (! $this->enabled('prism.mail.capture_recipients')) {
            return;
        }

        // A notification over the mail channel produces no `mail` record — upstream's sensor refuses it on
        // this very test — so an entry pushed here could only be claimed by the wrong message.
        if (isset($event->data['__laravel_notification'])) {
            return;
        }

        try {
            $recipients = $this->encode([
                'to' => $this->addresses($event->message->getTo(), 'to'),
                'cc' => $this->addresses($event->message->getCc(), 'cc'),
                'bcc' => $this->addresses($event->message->getBcc(), 'bcc'),
            ]);
        } catch (Throwable) {
            return;
        }

        if ($recipients === '') {
            return;
        }

        $this->push(self::MAIL, $this->mailableClass($event), ['recipients' => $recipients]);
    }

    /**
     * Hold what one notification was addressed to, per channel: one sent over mail and Slack is two
     * records with two answers, a Slack delivery routing to a webhook, not the email beside it.
     */
    public function recordNotification(NotificationSending $event): void
    {
        if (! $this->enabled('prism.notification.capture_recipients')) {
            return;
        }

        try {
            $columns = [
                'notifiable' => $this->notifiable($event->notifiable),
                'recipients' => $this->encode($this->routed($event)),
            ];
        } catch (Throwable) {
            return;
        }

        $this->push(self::NOTIFICATION, self::notificationClass($event->notification), $columns);
    }

    /**
     * The columns held for the next record of this signal and class, released as they are answered. Null
     * rather than an empty array when there is none, so the ingest leaves every key off the event and the
     * columns keep their own `DEFAULT ''` rather than being told an empty list was observed.
     * @return array<string, string>|null
     */
    public function take(string $signal, string $class): ?array
    {
        $key = self::keyFor($signal, $class);

        if (($this->pending[$key] ?? []) === []) {
            return null;
        }

        $columns = array_shift($this->pending[$key]);

        if ($this->pending[$key] === []) {
            unset($this->pending[$key]);
        }

        return $columns;
    }

    /** Forget everything held. */
    public function reset(): void
    {
        $this->pending = [];
    }

    /**
     * The key one signal's entries are held under, the class cut to 255 bytes because upstream writes it
     * through `Nightwatch\Types\Str::tinyText()`; a key not matching the record's own field would hold an
     * entry nothing could ever claim.
     */
    public static function keyFor(string $signal, string $class): string
    {
        return $signal.'|'.substr($class, 0, 255);
    }

    /**
     * The class name upstream's notification sensor records. PHP names an anonymous class
     * `Some\Base@anonymous\0/path/to/file.php:12$0`; upstream keeps the part before the NUL byte, and so does
     * this, or the key and the record's `class` would name one notification differently on every request.
     */
    public static function notificationClass(mixed $notification): string
    {
        if (! is_object($notification)) {
            return '';
        }

        $class = $notification::class;

        if (! str_contains($class, "@anonymous\0")) {
            return $class;
        }

        $end = strpos($class, "\0");

        return $end === false ? $class : substr($class, 0, $end);
    }

    /**
     * The mailable class upstream's mail sensor records — the key Laravel puts on the message data, and an
     * empty string for a raw `Mail::raw()` send, which is what the record carries for one.
     */
    private function mailableClass(MessageSending $event): string
    {
        /** @var mixed $class */
        $class = $event->data['__laravel_mailable'] ?? '';

        return is_string($class) ? $class : '';
    }

    /**
     * Where this notification's channel routed, as a list of addresses. `routeNotificationFor()` is the
     * framework's own answer, whatever the notifiable makes it: a string, a list, an `email => name` map, or
     * an object no address can be read from (the `database` channel routes to a relation). Anything
     * unreadable contributes nothing rather than a made-up value; a notifiable that throws leaves it empty.
     * @return list<string>
     */
    private function routed(NotificationSending $event): array
    {
        /** @var mixed $notifiable */
        $notifiable = $event->notifiable;

        if (! is_object($notifiable) || ! method_exists($notifiable, 'routeNotificationFor')) {
            return [];
        }

        try {
            /** @var mixed $route */
            $route = $notifiable->routeNotificationFor($event->channel, $event->notification);
        } catch (Throwable) {
            return [];
        }

        return $this->addresses($this->flatten($route), 'recipients');
    }

    /**
     * One routing answer as a flat list of candidate addresses. A string key IS the address, so it wins
     * wherever there is one (`['a@b.test' => 'Ada']`, the shape `MailChannel` reads, value = display name).
     * @return list<mixed>
     */
    private function flatten(mixed $route): array
    {
        if (! is_array($route)) {
            return $route === null ? [] : [$route];
        }

        $flat = [];

        foreach ($route as $key => $value) {
            $flat[] = is_string($key) ? $key : $value;
        }

        return $flat;
    }

    /**
     * One address list, scrubbed, capped and made insertable. A redacted list keeps its length: the `mail`
     * table stores the counts beside these names, and a shorter list would read as a message that went to
     * fewer people than it did.
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private function addresses(iterable $values, string $list): array
    {
        $redact = $this->scrubber->isSensitive($list) || $this->scrubber->isSensitive(self::ADDRESS_KEY);

        $addresses = [];

        foreach ($values as $value) {
            if (count($addresses) >= self::MAX_ADDRESSES) {
                break;
            }

            $address = Text::clean($this->address($value));

            if ($address === '') {
                continue;
            }

            $addresses[] = $redact ? Scrubber::REDACTED : $address;
        }

        return $addresses;
    }

    /** One routed value as an address, or an empty string when it is not one. */
    private function address(mixed $value): string
    {
        if ($value instanceof Address) {
            return $value->getAddress();
        }

        if (is_string($value)) {
            return $value;
        }

        // `Illuminate\Mail\Mailables\Address` and anything shaped like it, named rather than type-hinted
        // so the package needs no dependency on a class a host may or may not route through.
        if (is_object($value)) {
            foreach (['address', 'email'] as $property) {
                /** @var mixed $candidate */
                $candidate = $value->{$property} ?? null;

                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * The model a notification was addressed to, as `Class#id`. An on-demand notifiable
     * (`Notification::route(…)`) has no key and reads as its class alone — a bare `#` would claim an
     * identity that does not exist. Deliberately not an address: that is the `recipients` column beside
     * it, which the scrub list governs.
     */
    private function notifiable(mixed $notifiable): string
    {
        if (! is_object($notifiable)) {
            return '';
        }

        $key = null;

        if (method_exists($notifiable, 'getKey')) {
            try {
                /** @var mixed $key */
                $key = $notifiable->getKey();
            } catch (Throwable) {
                $key = null;
            }
        }

        $id = (is_string($key) || is_int($key)) && (string) $key !== ''
            ? '#'.$key
            : '';

        return Text::clean($notifiable::class.$id);
    }

    /**
     * Hold one entry, oldest first, bounded per class.
     * @param  array<string, string>  $columns
     */
    private function push(string $signal, string $class, array $columns): void
    {
        $key = self::keyFor($signal, $class);

        $this->pending[$key][] = $columns;

        while (count($this->pending[$key]) > self::LIMIT) {
            array_shift($this->pending[$key]);
        }
    }

    /**
     * A structure as its column stores it, on the same flags every other JSON column uses, so a value that
     * cannot be encoded whole stores as much of itself as encodes rather than costing the message its entry.
     * @param  array<array-key, mixed>  $value
     */
    private function encode(array $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return is_string($json) ? $json : '';
    }

    /** One capture switch, read per event so `config:cache` is the only cache. */
    private function enabled(string $key): bool
    {
        return (bool) $this->config->get($key, true);
    }
}
