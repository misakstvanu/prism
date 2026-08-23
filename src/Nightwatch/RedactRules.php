<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Nightwatch;

use Illuminate\Contracts\Config\Repository;
use Laravel\Nightwatch\Records\CacheEvent;
use Laravel\Nightwatch\Records\Command;
use Laravel\Nightwatch\Records\Exception;
use Laravel\Nightwatch\Records\Mail;
use Laravel\Nightwatch\Records\OutgoingRequest;
use Laravel\Nightwatch\Records\Query;
use Laravel\Nightwatch\Records\Request;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Scrubber;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * `prism.scrub` said in the capture engine's vocabulary (US-011).
 *
 * A host writes one list of sensitive field names under `prism.scrub`, and
 * {@see Scrubber} is what decides whether a name is one of them. Where that
 * list is applied is here: `laravel/nightwatch` builds a typed record per
 * signal and offers a `redact*` callback per record type, so this class is the
 * one place the translation happens — one list, every signal, one file to read
 * when a value turns up in the console that should never have left the
 * process.
 *
 * Unlike the reject callbacks ({@see RejectRules}) these **always run** and
 * they **mutate rather than short-circuit**: the record is still recorded, and
 * only the field carrying the secret is replaced. That is why redaction has
 * none of rejection's counter problem — nothing is dropped, so no per-execution
 * figure moves.
 *
 * Two halves, for the same reason every other `nightwatch.*` derivation in this
 * package has two:
 *
 *   - **The config keys** `redact_payload_fields` and `redact_headers` are
 *     derived from `prism.scrub` ({@see payloadFields()}, {@see headerNames()}),
 *     which is what upstream's own request sensor reads. Those are snapshotted
 *     into a `SensorManager` during Nightwatch's `register()`, so in a real
 *     install — where `laravel/nightwatch` sorts first — the write alone is read
 *     by nobody, and {@see PrismServiceProvider} pushes them
 *     onto the built sensor as well.
 *   - **The callbacks** below cover everything that is not a request field, and
 *     cover the request again from a seam no provider ordering can miss.
 *
 * Three shapes do all the work, and every signal is one of them:
 *
 *   - **A structure with keys** — a request's payload bag and its headers. The
 *     {@see Scrubber} handles these directly and case-insensitively, which is
 *     stricter than upstream's exact `in_array` comparison on the payload.
 *   - **A URL** — a request's own and an outgoing call's. Only the query string
 *     can carry a secret, and it is rewritten pair by pair so an untouched
 *     parameter survives byte for byte.
 *   - **Free text holding `name = value` pairs** — a SQL statement, an artisan
 *     command line, a mail subject, an exception message and a cache key are all
 *     this. {@see redactPairs()} is the one implementation, so `--password=x`,
 *     `password = 'x'` and `token:x` are answered by the same rule and a host
 *     that adds a key to `prism.scrub` gets all five for it.
 *
 * Nightwatch's job records — `queued-job` and `job-attempt` — carry no payload
 * at all (their fields are the job's name, id, queue, connection and outcome),
 * so a secret dispatched inside a job never reaches a record to be redacted
 * from. That is covered by absence rather than by a callback, and asserted as
 * such: there is no `redactQueuedJobs()` upstream because there is nothing
 * there to redact.
 */
final class RedactRules
{
    /**
     * Upstream's own `redact_payload_fields` default, kept underneath Prism's
     * list rather than replaced by it — a host relying on `_token` being
     * redacted did not ask to stop by installing Prism.
     *
     * @var list<string>
     */
    public const PAYLOAD_FIELDS = ['_token', 'password', 'password_confirmation'];

    /**
     * Upstream's own `redact_headers` default, kept for the same reason. These
     * four get value-shape-aware treatment upstream (an `Authorization` keeps
     * its scheme, a `Cookie` keeps its names), which is worth preserving.
     *
     * @var list<string>
     */
    public const HEADERS = ['Authorization', 'Cookie', 'Proxy-Authorization', 'X-XSRF-TOKEN'];

    /**
     * Values that look like a `name = value` pair but are not one: a SQL
     * placeholder. `where password = ?` carries no secret, and replacing the
     * `?` would take the statement's shape away from the query screen for
     * nothing.
     */
    private const PLACEHOLDER = '/^[?:$@][\w]*$/';

    /**
     * The compiled `name = value` matcher, or null when the scrub list is empty.
     *
     * Built once, at boot, because it is consulted on the hot path — every
     * query, every cache key — and the alternation is the whole scrub list.
     */
    private readonly ?string $pairPattern;

    /**
     * @param  Scrubber  $scrubber  The redactor for keyed structures.
     * @param  list<string>  $keys  The same list, flat, for the config keys
     *                              upstream reads and the free-text matcher.
     */
    public function __construct(
        private readonly Scrubber $scrubber,
        private readonly array $keys = [],
    ) {
        $this->pairPattern = $this->compilePairPattern($keys);
    }

    /**
     * Resolve the list once, at boot, so nothing re-reads config while a request
     * is being served — the same stance as {@see RejectRules::fromConfig()}.
     *
     * It builds its own {@see Scrubber} rather than taking the container's,
     * because this runs during `register()`, before the singleton exists.
     */
    public static function fromConfig(Repository $config): self
    {
        $configured = $config->get('prism.scrub', []);

        $keys = [];

        foreach (is_iterable($configured) ? $configured : [] as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return new self(new Scrubber($keys), array_values(array_unique($keys)));
    }

    /**
     * `nightwatch.redact_payload_fields` — the request body fields upstream's
     * own sensor blanks while it serialises them.
     *
     * @return list<string>
     */
    public function payloadFields(): array
    {
        return $this->union(self::PAYLOAD_FIELDS);
    }

    /**
     * `nightwatch.redact_headers` — the header names upstream's own sensor
     * blanks while it serialises them.
     *
     * @return list<string>
     */
    public function headerNames(): array
    {
        return $this->union(self::HEADERS);
    }

    /**
     * A request record: its headers, its body and its URL.
     *
     * The headers and the body are keyed structures, so the {@see Scrubber}
     * answers them — including the case-insensitivity upstream's
     * `in_array($key, $fields, true)` does not have. The URL is rewritten
     * because a token in a query string is a secret in the one field every
     * screen prints.
     */
    public function redactRequest(Request $record): void
    {
        $this->redactHeaderBag($record->headers);

        /** @var array<array-key, mixed> $payload */
        $payload = $record->payload->all();

        $record->payload->replace($this->scrubber->scrub($payload));

        $record->url = $this->redactUrl($record->url);
    }

    /**
     * A query record: its SQL.
     *
     * Nightwatch captures no bindings, so what is left to redact is a value
     * written into the statement itself, which is what a raw `DB::statement()`
     * produces. A `?` or `:name` placeholder is deliberately left alone.
     */
    public function redactQuery(Query $record): void
    {
        $record->sql = $this->redactPairs($record->sql);
    }

    /** An outgoing call: a credential passed in its query string. */
    public function redactOutgoingRequest(OutgoingRequest $record): void
    {
        $record->url = $this->redactUrl($record->url);
    }

    /**
     * A cache event: its key.
     *
     * A cache key is a `name:value` pair often enough that the free-text rule is
     * the right one — `password-reset:<token>` loses the token and keeps the
     * name, so the key stays legible on the screen while the secret does not
     * leave. A key that is *only* a name (`api_key`) has no value part and is
     * left exactly as it was: the name of a cache entry is not a secret.
     */
    public function redactCacheEvent(CacheEvent $record): void
    {
        $record->key = $this->redactPairs($record->key);
    }

    /** A mail record: its subject, which is the only free text it carries. */
    public function redactMail(Mail $record): void
    {
        $record->subject = $this->redactPairs($record->subject);
    }

    /**
     * A command record: its full command line, where a secret arrives as an
     * option — `user:create --password=hunter2`.
     */
    public function redactCommand(Command $record): void
    {
        $record->command = $this->redactPairs($record->command);
    }

    /**
     * An exception record: its message.
     *
     * Safe to rewrite because nothing groups on it. Upstream hashes its
     * `_group` from class, code, file and line, and Prism's own fingerprint is
     * the class plus the topmost application frame (US-029) with the message
     * explicitly not an input — so redacting here cannot move an error group,
     * orphan an issue or undo a triage decision.
     */
    public function redactException(Exception $record): void
    {
        $record->message = $this->redactPairs($record->message);
    }

    /**
     * Replace the value of every sensitive header, in place.
     *
     * A `HeaderBag` lower-cases its own keys, so this is case-insensitive on
     * both sides. Upstream then applies its own value-shape-aware redaction to
     * whatever names reached `redact_headers`; running after this one it merely
     * re-blanks an already-blank value.
     */
    private function redactHeaderBag(HeaderBag $headers): void
    {
        foreach (array_keys($headers->all()) as $name) {
            if (is_string($name) && $this->scrubber->isSensitive($name)) {
                $headers->set($name, Scrubber::REDACTED);
            }
        }
    }

    /**
     * The free-text rule, for a caller that holds a string rather than one of
     * the engine's record objects.
     *
     * {@see PrismSpanProcessor} is that caller (US-018): once the span lane
     * owns the query signal, the SQL Prism ships is an OpenTelemetry span
     * attribute rather than the `Query` record {@see redactQuery()} rewrites,
     * and a `prism.scrub` list that stopped applying the day the lane landed
     * would be the quietest possible way to start shipping credentials.
     */
    public function redactText(string $text): string
    {
        return $this->redactPairs($text);
    }

    /**
     * Rewrite a URL's query string, pair by pair.
     *
     * Public for {@see redactText()}'s reason: the span lane reports an
     * outgoing call as a span carrying `url.full`, and that URL has to meet the
     * same list the `OutgoingRequest` record's does.
     *
     * Deliberately not `parse_str()` + `http_build_query()`: that round trip
     * re-encodes every value, collapses a repeated key into an array and drops
     * a valueless flag, so a URL with nothing sensitive in it would still come
     * back changed. Splitting on the separators leaves every untouched pair
     * byte for byte as it arrived.
     */
    public function redactUrl(string $url): string
    {
        $mark = strpos($url, '?');

        if ($mark === false) {
            return $url;
        }

        $query = substr($url, $mark + 1);
        $fragment = '';

        $hash = strpos($query, '#');

        if ($hash !== false) {
            $fragment = substr($query, $hash);
            $query = substr($query, 0, $hash);
        }

        if ($query === '') {
            return $url;
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            $split = strpos($pair, '=');

            if ($split === false) {
                $pairs[] = $pair;

                continue;
            }

            $name = substr($pair, 0, $split);

            $pairs[] = $this->scrubber->isSensitive(urldecode($name))
                ? $name.'='.rawurlencode(Scrubber::REDACTED)
                : $pair;
        }

        return substr($url, 0, $mark + 1).implode('&', $pairs).$fragment;
    }

    /**
     * Replace the value half of every `name = value` pair whose name is on the
     * scrub list, wherever it appears in free text.
     *
     * One implementation for five signals, because they are the same problem
     * written five ways: `--password=hunter2` on a command line, `password =
     * 'hunter2'` in a statement, `"password":"hunter2"` in a serialised body,
     * `token:abc` in a cache key. The separator may be `=`, `:` or `=>`; the
     * value may be quoted, and its quotes are preserved so the text stays
     * well-formed after the value inside them is gone.
     */
    private function redactPairs(string $text): string
    {
        if ($this->pairPattern === null || $text === '') {
            return $text;
        }

        $redacted = preg_replace_callback(
            $this->pairPattern,
            static function (array $matches): string {
                $value = $matches[3];

                // A SQL placeholder is not a value; leaving it alone keeps the
                // statement's shape readable and costs nothing.
                if (preg_match(self::PLACEHOLDER, $value) === 1) {
                    return $matches[0];
                }

                $quote = str_contains('\'"`', $value[0] ?? ' ') ? $value[0] : '';

                return $matches[1].$matches[2].$quote.Scrubber::REDACTED.$quote;
            },
            $text,
        );

        return $redacted ?? $text;
    }

    /**
     * Compile the scrub list into the pair matcher, or null when there is
     * nothing to match.
     *
     * The name may wear a `--` (an artisan option) or a quote (a serialised
     * body); the lookarounds keep `password` from matching inside
     * `password_strength`, which is the same over-redaction the {@see Scrubber}
     * avoids by comparing whole keys.
     *
     * @param  list<string>  $keys
     */
    private function compilePairPattern(array $keys): ?string
    {
        if ($keys === []) {
            return null;
        }

        $names = implode('|', array_map(
            static fn (string $key): string => preg_quote($key, '/'),
            $keys,
        ));

        return '/(?<![\w-])((?:--)?(?:'.$names.'))(?![\w-])(["\']?\s*(?:=>|=|:)\s*)'
            .'(\'[^\']*\'|"[^"]*"|`[^`]*`|[^\s,;)\]}]+)/i';
    }

    /**
     * Upstream's own defaults with `prism.scrub` on top, de-duplicated
     * case-insensitively so a host repeating `password` does not send it twice.
     *
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private function union(array $defaults): array
    {
        $merged = [];

        foreach ([...$defaults, ...$this->keys] as $key) {
            $merged[strtolower($key)] ??= $key;
        }

        return array_values($merged);
    }
}
