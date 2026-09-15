<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Browser;

use DateTimeImmutable;
use JsonException;
use Misakstvanu\Prism\Http\Controllers\BrowserReportController;
use Misakstvanu\Prism\Support\Text;
use Misakstvanu\Prism\Support\Timestamp;

/**
 * A report posted by the browser SDK, read off the wire (US-006).
 *
 * The wire shape is versioned exactly as the batch envelope the client ships to
 * the workspace is, and for the same reason — the two halves are deployed by
 * different people at different times, so neither may assume the other moved:
 *
 * ```
 * { v: 1, sdk: {name, version}, sent_at, session: {id, release}, user?: {id},
 *   events: [{type, timestamp, trace_id, payload}] }
 * ```
 *
 * **The body is decoded by hand rather than through the request's own JSON
 * accessor**: the transport a page uses decides the content type and only one of
 * the two is JSON — `navigator.sendBeacon`, the only transport that survives a
 * page being closed (when the last report of a session is sent), posts
 * `text/plain` and cannot be told otherwise. A parse keyed on the header works
 * in every hand test and silently drops the reports that matter most.
 *
 * Parsing is deliberately in two steps, and {@see BrowserReportController}
 * spends the gap between them: {@see tryParse} answers the envelope — is this a
 * report at all — cheaply, saying nothing about the events beyond how many
 * arrived, which lets the caller refuse an oversized batch (413) before any
 * per-event work; {@see filter} then answers what is worth forwarding, dropping
 * and counting the events that are not (see {@see BrowserEvent}). Both are
 * total: neither throws, on any input, from any origin. The endpoint is open to
 * the internet by construction — an anonymous visitor hitting a JavaScript error
 * is the report most worth having — so every refusal here is a return value.
 */
final class BrowserReport
{
    /** The wire version this package speaks. */
    public const VERSION = 1;

    /**
     * The longest an identifier from the page may be, in bytes. Session id,
     * release name and user hint are short bounded values by construction — a
     * UUID, a version string, a primary key — so a long one is a page being
     * careless rather than a value with meaning. Cut rather than refused, for
     * the reason every other cap is.
     */
    private const IDENTIFIER_BYTES = 256;

    /**
     * @param  list<mixed>  $posted  The events as posted, before filtering.
     * @param  list<BrowserEvent>  $events  What survived {@see filter}.
     */
    private function __construct(
        public readonly DateTimeImmutable $sentAt,
        public readonly string $sessionId,
        public readonly string $release,
        public readonly ?string $userId,
        private readonly array $posted,
        public readonly array $events,
        private readonly int $dropped,
    ) {}

    /**
     * Whether a body is JSON at all — the caller's 400, told apart from its 422.
     * `json_validate()` walks the bytes without building anything, so asking
     * this before {@see tryParse} costs a scan rather than a second decode. "I
     * could not read this" and "I read it and it is not a report" are different
     * failures, and the SDK's own error handling (US-014) reads them.
     */
    public static function isDecodable(string $body): bool
    {
        return $body !== '' && json_validate($body);
    }

    /**
     * Read the envelope, or null when the body is not a version 1 report.
     * `sent_at` is required and must be legible: the offset between it and the
     * server's own clock corrects every timestamp in the report (US-008), and
     * browser clocks are wrong often enough that the correction is the point. A
     * report that cannot say when it was sent has no offset to be corrected by.
     */
    public static function tryParse(string $body): ?self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        if (($decoded['v'] ?? null) !== self::VERSION) {
            return null;
        }

        $sentAt = $decoded['sent_at'] ?? null;

        if (! is_string($sentAt)) {
            return null;
        }

        $at = Timestamp::parse($sentAt);

        if ($at === null) {
            return null;
        }

        $events = $decoded['events'] ?? null;

        if (! is_array($events)) {
            return null;
        }

        $session = is_array($decoded['session'] ?? null) ? $decoded['session'] : [];
        $user = is_array($decoded['user'] ?? null) ? $decoded['user'] : [];

        return new self(
            sentAt: $at,
            sessionId: self::identifier($session['id'] ?? null) ?? '',
            release: self::identifier($session['release'] ?? null) ?? '',
            userId: self::identifier($user['id'] ?? null),
            posted: array_values($events),
            events: [],
            dropped: 0,
        );
    }

    /** How many events arrived, whether or not they are any good. */
    public function eventCount(): int
    {
        return count($this->posted);
    }

    /** How many of them {@see filter} refused. */
    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * The same report, narrowed to the events worth forwarding. A new instance
     * rather than a mutation, so the report a forwarder is handed is by
     * construction the filtered one: no ordering lets an unvalidated event reach
     * the pipeline.
     */
    public function filter(): self
    {
        $accepted = [];
        $dropped = 0;

        foreach ($this->posted as $raw) {
            $event = BrowserEvent::tryParse($raw);

            if ($event === null) {
                $dropped++;

                continue;
            }

            $accepted[] = $event;
        }

        return new self(
            sentAt: $this->sentAt,
            sessionId: $this->sessionId,
            release: $this->release,
            userId: $this->userId,
            posted: $this->posted,
            events: $accepted,
            dropped: $dropped,
        );
    }

    /**
     * A short identifier the page supplied, cleaned and cut, or null when it
     * supplied nothing usable. A number is accepted as well as a string: a
     * `user.id` is a primary key on the other side of the wire and JSON has one
     * number type, so `{"id": 42}` means what `{"id": "42"}` means.
     */
    private static function identifier(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim(Text::clean($value));

        return $value === '' ? null : Text::truncate($value, self::IDENTIFIER_BYTES);
    }
}
