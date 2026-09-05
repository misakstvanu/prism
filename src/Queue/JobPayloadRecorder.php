<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Queue;

use Illuminate\Contracts\Config\Repository;
use Misakstvanu\Prism\Http\BodyRecorder;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RedactRules;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\Text;
use Throwable;

/**
 * What a job was asked to do, held until the record describing it is written.
 *
 * **The capture engine has no answer here, which is why this class exists** —
 * the same gap {@see BodyRecorder} fills for an HTTP
 * exchange, one signal along. Nightwatch's `queued-job` and `job-attempt`
 * records carry the job's name, id, queue, connection and outcome and nothing
 * about its *arguments*; Prism's `jobs` table has a `payload` column and the
 * failed-job screen draws a panel from it, so without this the panel is empty
 * for every row a real install ever writes.
 *
 * It is a **holder, not a listener**, for {@see BodyRecorder}'s
 * reason: the payload is in hand while the framework raises its queue events,
 * and the record it belongs to is written later — a `queued-job` record at
 * `JobQueued`, a `job-attempt` record when the worker has finished running it.
 * {@see PrismIngest::stampJobPayload()} is what closes the gap.
 *
 * Four rules decide what is held, and each of them is a way of not lying:
 *
 *   - **Keyed by the job id the sensor itself uses.** Both of upstream's job
 *     sensors identify a job as `payload['nightwatch']['job_id'] ?? payload['uuid']`,
 *     and {@see keyFor()} is that expression restated — so the key this holds a
 *     payload under and the `uuid` the translated event carries cannot name two
 *     different jobs. A payload with neither is not held at all: an entry
 *     nothing can ask for is a leak, not a capture.
 *   - **Scrubbed by key, never as text.** The payload is a JSON document with
 *     keys, so it meets the {@see Scrubber} exactly as a JSON request body
 *     does. It is deliberately *not* run through
 *     {@see RedactRules::redactText()} afterwards: `data.command` is a
 *     serialised PHP object whose string lengths are part of its syntax, so
 *     rewriting a value inside it would produce a document that no longer
 *     parses — a corruption where the honest answer is that a key nobody named
 *     was not redacted. A host that wants a job's constructor argument scrubbed
 *     names the property in `prism.scrub`; the serialised blob is beyond any
 *     rule that works on keys.
 *   - **Capped in bytes, and it says when it cut.** {@see Text::truncate()}, so
 *     the cut never lands inside a multi-byte character and the value stays
 *     insertable; the marker is appended after the cut, so the cap reads as the
 *     number the host configured.
 *   - **Read once.** {@see take()} releases the entry it answers, because a
 *     payload belongs to exactly one record and a worker process runs jobs
 *     until it is told to stop. {@see LIMIT} is the second half of that: a job
 *     that was queued and whose record never arrived — a dispatch that threw
 *     between the two events, an execution the sampler discarded — would
 *     otherwise sit here forever.
 */
final class JobPayloadRecorder
{
    /**
     * How many unread payloads may be held at once.
     *
     * Entries are released as they are read, so in a healthy process at most
     * one or two are ever pending. The bound is for the unhealthy one: a
     * long-lived worker that dispatches jobs whose records never arrive must
     * not grow a map for the life of the process. The oldest is dropped, which
     * is the entry least likely to still be claimed.
     */
    private const LIMIT = 64;

    /**
     * Payloads by job id, insertion-ordered (PHP arrays are), which is what
     * makes "drop the oldest" a single `array_key_first`.
     *
     * @var array<string, string>
     */
    private array $payloads = [];

    public function __construct(
        private readonly Repository $config,
        private readonly Scrubber $scrubber,
    ) {}

    /**
     * Hold one job's payload, as the framework serialised it.
     *
     * Guarded end to end: encoding a client-supplied structure is not worth a
     * host's request or a worker's job, and a failure leaves nothing held,
     * which reads on the screen exactly as "no payload was captured" — the
     * honest answer.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function record(array $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        $key = self::keyFor($payload);

        if ($key === '') {
            return;
        }

        try {
            $held = $this->cap($this->serialise($payload));
        } catch (Throwable) {
            return;
        }

        if ($held === '') {
            return;
        }

        $this->payloads[$key] = $held;

        while (count($this->payloads) > self::LIMIT) {
            $oldest = array_key_first($this->payloads);

            if ($oldest === null) {
                break;
            }

            unset($this->payloads[$oldest]);
        }
    }

    /**
     * The payload held for one job, released as it is answered.
     *
     * Null rather than an empty string when there is none, so the ingest can
     * leave the key off the event entirely and the column keeps its own
     * `DEFAULT ''` rather than being told an empty payload was observed.
     */
    public function take(string $key): ?string
    {
        if ($key === '' || ! isset($this->payloads[$key])) {
            return null;
        }

        $payload = $this->payloads[$key];

        unset($this->payloads[$key]);

        return $payload;
    }

    /** Forget everything held. */
    public function reset(): void
    {
        $this->payloads = [];
    }

    /**
     * The id both of upstream's job sensors key a record by —
     * `payload['nightwatch']['job_id'] ?? payload['uuid']`, restated so the two
     * sides cannot drift.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function keyFor(array $payload): string
    {
        /** @var mixed $nightwatch */
        $nightwatch = $payload['nightwatch'] ?? null;

        if (is_array($nightwatch) && (is_string($nightwatch['job_id'] ?? null) || is_int($nightwatch['job_id'] ?? null))) {
            /** @var string|int $id */
            $id = $nightwatch['job_id'];

            return (string) $id;
        }

        /** @var mixed $uuid */
        $uuid = $payload['uuid'] ?? null;

        return is_string($uuid) || is_int($uuid) ? (string) $uuid : '';
    }

    /**
     * The payload as the column stores it: scrubbed by key and re-encoded.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function serialise(array $payload): string
    {
        $json = json_encode(
            $this->scrubber->scrub($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return is_string($json) ? $json : '';
    }

    /**
     * Make a payload safe to insert and bound its cost.
     *
     * {@see Text::clean()} first, because the cap is in bytes and the cut has
     * to happen on a string whose bytes are already valid UTF-8.
     */
    private function cap(string $value): string
    {
        $value = Text::clean($value);

        if ($value === '') {
            return '';
        }

        $max = $this->maxBytes();

        if ($max <= 0 || strlen($value) <= $max) {
            return $value;
        }

        return Text::truncate($value, $max).Text::TRUNCATED;
    }

    /** `prism.job.capture_payload`, read per event so `config:cache` is the only cache. */
    private function enabled(): bool
    {
        return (bool) $this->config->get('prism.job.capture_payload', true);
    }

    /** `prism.job.max_payload`, in bytes. `0` or less means no cap. */
    private function maxBytes(): int
    {
        /** @var mixed $max */
        $max = $this->config->get('prism.job.max_payload', 65536);

        return is_numeric($max) ? (int) $max : 65536;
    }
}
