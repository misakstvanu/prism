<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RedactRules;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\Text;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The request and response bodies of the execution being recorded.
 *
 * **The capture engine has no answer here, which is why this class exists.**
 * Nightwatch's request record carries a `payload` field, but it is serialised
 * only when the response was a **500**, only for a supported content type, and
 * it lands in no Prism column — and there is no response body in its model at
 * all: a `RequestRecord` carries `responseSize`, an integer, and nothing else
 * about what was sent back. Both halves of "what did this request actually
 * exchange" therefore have to be captured by Prism, which is what
 * {@see Middleware\CaptureHttpBodies} does and what this holds.
 *
 * It is a **holder, not a listener**: the middleware fills it while the
 * response is in hand and {@see PrismIngest}
 * reads it back when the engine finally writes its `request` record, which
 * happens from `terminate()` — long after the middleware stack has unwound.
 * A singleton rather than a scoped binding for the same reason the event buffer
 * is one: a long-lived runtime (Octane, a queue worker) keeps the container
 * between executions, so what makes this safe is that {@see reset()} runs at
 * the *start* of every request rather than that the object is thrown away at
 * the end of one. A worker never runs the middleware at all and so never has a
 * body to report, which is correct — a job is not an HTTP exchange.
 *
 * Four rules decide what is recorded, and each of them is a way of not lying:
 *
 *   - **Structured bodies are recorded as scrubbed JSON, not as raw bytes.** A
 *     JSON or form body is decoded, run through the {@see Scrubber} by key —
 *     the same object, and so the same `prism.scrub` list, that redacts a
 *     header or an outgoing query string — and re-encoded. Recording the raw
 *     bytes instead would mean scrubbing a secret out of text with a regex,
 *     which is the weaker rule; here the key is a key.
 *   - **Anything else is free text, and meets the free-text rule.** A
 *     `text/plain` or XML body has no keys to walk, so it goes through
 *     {@see RedactRules::redactText()} — the same `name = value` matcher a SQL
 *     statement and an artisan command line meet, so a host that adds a key to
 *     `prism.scrub` gets all of them for it.
 *   - **A content type nobody can read is not recorded.** The allow list
 *     (`prism.request.body_content_types`) is what keeps a rendered image, a
 *     PDF export or a gzip stream out of a `String` column, and what keeps the
 *     default from quietly storing every HTML document the application renders.
 *   - **A body is capped in bytes and says when it was cut.** Truncation is
 *     {@see Text::truncate()} so the cut never lands mid-character, and the
 *     marker is appended so a reader is never shown a JSON document that merely
 *     *looks* malformed.
 *
 * Uploaded **file contents are never recorded**. A multipart request is
 * recorded as its non-file fields; the files are named, sized and typed in a
 * `_files` entry beside them, because a body that silently omitted the half of
 * the request that mattered would be worse than one that says what it left out.
 */
final class BodyRecorder
{
    /** Appended to a body the cap cut, so a reader knows the document is partial. */
    public const TRUNCATED = '… [truncated]';

    /**
     * The key an uploaded file's metadata is recorded under, inside the
     * recorded body. Prefixed like upstream's own `_nightwatch_files` so it
     * cannot be mistaken for a field the client actually sent.
     */
    private const FILES_KEY = '_prism_files';

    /**
     * Content types whose bodies are worth recording, when the host has not
     * said otherwise. Matched against the media type alone (parameters such as
     * `charset=` are ignored) with `+json` / `+xml` suffixes recognised, so
     * `application/problem+json` is a JSON body.
     *
     * `text/html` is deliberately **absent**: an HTML response is the rendered
     * page, which is the largest and least diagnostic thing an application
     * produces, and storing 64 KB of markup per request would price the whole
     * feature out. A host that wants it adds it.
     *
     * @var list<string>
     */
    public const CONTENT_TYPES = [
        'application/json',
        'application/x-www-form-urlencoded',
        'multipart/form-data',
        'application/xml',
        'text/xml',
        'text/plain',
    ];

    /** The recorded request body, or '' when there was none to record. */
    private string $requestBody = '';

    /** The recorded response body, or '' when there was none to record. */
    private string $responseBody = '';

    public function __construct(
        private readonly Repository $config,
        private readonly Scrubber $scrubber,
        private readonly RedactRules $redact,
    ) {}

    /**
     * Forget the previous execution's bodies.
     *
     * Called at the *start* of a request rather than the end of one, which is
     * what makes a stale body impossible in a long-lived runtime: a request
     * that never reaches {@see record()} — one that died mid-stack — leaves
     * nothing behind for the next request to report as its own.
     */
    public function reset(): void
    {
        $this->requestBody = '';
        $this->responseBody = '';
    }

    /**
     * Record what this exchange carried, subject to the two switches, the
     * content-type allow list and the byte cap.
     *
     * Every step is guarded: reading a body means touching a stream, decoding
     * client-supplied bytes and encoding whatever came back, and none of that
     * is worth a host's request. A failure leaves the body empty, which reads
     * on the screen exactly as "nothing was captured" — the honest answer.
     */
    public function record(Request $request, Response $response): void
    {
        if ($this->enabled('capture_body')) {
            $this->requestBody = $this->guard(fn (): string => $this->readRequest($request));
        }

        if ($this->enabled('capture_response')) {
            $this->responseBody = $this->guard(fn (): string => $this->readResponse($response));
        }
    }

    /**
     * The two bodies as the `requests` columns name them, or null when neither
     * was captured.
     *
     * Null rather than a pair of empty strings so the ingest can leave the
     * payload alone entirely for an execution that is not an HTTP request —
     * a command, a job — rather than stamping two blanks onto every one of
     * them.
     *
     * @return array{request_body: string, response_body: string}|null
     */
    public function captured(): ?array
    {
        if ($this->requestBody === '' && $this->responseBody === '') {
            return null;
        }

        return [
            'request_body' => $this->requestBody,
            'response_body' => $this->responseBody,
        ];
    }

    /**
     * The request body: its parsed fields where it has any, its raw text where
     * it does not.
     *
     * `$request->request` is the POST bag, and Laravel points it at the decoded
     * JSON document for a JSON request (`Request::createFromBase()`), so one
     * read covers both of the shapes a client actually sends structured data
     * in. That is also what makes the strong scrub available: the fields are
     * keys before they are text.
     */
    private function readRequest(Request $request): string
    {
        if (! $this->recordable($request->headers->get('content-type'))) {
            return '';
        }

        /** @var array<array-key, mixed> $fields */
        $fields = $request->request->all();
        $files = $this->files($request);

        if ($fields !== [] || $files !== []) {
            $body = $this->scrubber->scrub($fields);

            if ($files !== []) {
                $body[self::FILES_KEY] = $files;
            }

            return $this->cap($this->encode($body));
        }

        $content = $request->getContent();

        return is_string($content) ? $this->cap($this->redact->redactText($content)) : '';
    }

    /**
     * The response body.
     *
     * A streamed response has no content to read — its body is produced by a
     * callback while it is being sent, i.e. after every middleware has
     * returned — and a file response's content is the file, which is exactly
     * what this must never put in a column. Both answer '' rather than
     * something that merely looks like a body.
     */
    private function readResponse(Response $response): string
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return '';
        }

        if (! $this->recordable($response->headers->get('content-type'))) {
            return '';
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return '';
        }

        return $this->cap($this->redactBody($content));
    }

    /**
     * A response body meets the keyed rule when it is JSON and the free-text
     * rule when it is not.
     *
     * The decode is what buys the strong scrub on the way *out* as well as the
     * way in: an API answering `{"token": "…"}` has a key to match, and a regex
     * over the serialised document would be answering a weaker question about
     * the same bytes. A document that does not decode to a structure — a bare
     * JSON scalar, malformed output, plain text — falls back rather than being
     * dropped.
     */
    private function redactBody(string $content): string
    {
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $this->encode($this->scrubber->scrub($decoded));
        }

        return $this->redact->redactText($content);
    }

    /**
     * Uploaded files as name, size and client-reported type — never contents.
     *
     * A field may hold many files (`documents[]`), and a nested one may hold
     * more, so this walks rather than reading the first: an upload that was
     * silently left out of the record is the half of the request a reader most
     * needs to know arrived.
     *
     * @param  array<array-key, mixed>|null  $files
     * @return array<string, array{name: string, size: int, type: string}>
     */
    private function files(Request $request, ?array $files = null, string $prefix = ''): array
    {
        $named = [];

        /** @var array<array-key, mixed> $files */
        $files ??= $request->allFiles();

        foreach ($files as $field => $file) {
            $key = $prefix === '' ? (string) $field : $prefix.'.'.$field;

            if (is_array($file)) {
                $named = [...$named, ...$this->files($request, $file, $key)];

                continue;
            }

            if (! $file instanceof UploadedFile) {
                continue;
            }

            $named[$key] = [
                'name' => Text::clean((string) $file->getClientOriginalName()),
                'size' => (int) $file->getSize(),
                'type' => Text::clean((string) $file->getClientMimeType()),
            ];
        }

        return $named;
    }

    /**
     * Whether a content type is one whose body is worth a column.
     *
     * The media type alone is compared — `application/json; charset=utf-8` is
     * `application/json` — and a `+json` / `+xml` suffix resolves to its base
     * type, so a vendor media type is recognised without the host having to
     * list every one it uses. A request that declared no type at all (a GET,
     * most beacons) has no body worth reading and is refused here rather than
     * further down, which is what keeps `getContent()` off the hot path for
     * the majority of requests.
     */
    private function recordable(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '') {
            return false;
        }

        $media = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($media === '') {
            return false;
        }

        if (str_ends_with($media, '+json')) {
            $media = 'application/json';
        } elseif (str_ends_with($media, '+xml')) {
            $media = 'application/xml';
        }

        return in_array($media, $this->contentTypes(), true);
    }

    /**
     * The allow list, lower-cased. A host that replaces it replaces it whole —
     * the config merge is shallow, which is the same rule every other list in
     * `config/prism.php` follows.
     *
     * @return list<string>
     */
    private function contentTypes(): array
    {
        $configured = $this->config->get('prism.request.body_content_types', self::CONTENT_TYPES);

        $types = [];

        foreach (is_iterable($configured) ? $configured : [] as $type) {
            if (is_string($type) && $type !== '') {
                $types[] = strtolower(trim($type));
            }
        }

        return $types === [] ? self::CONTENT_TYPES : array_values(array_unique($types));
    }

    /** One of the two capture switches, read per request so config:cache is the only cache. */
    private function enabled(string $key): bool
    {
        return (bool) $this->config->get('prism.request.'.$key, true);
    }

    /**
     * Make a string safe to insert, and bound its cost.
     *
     * {@see Text::clean} first, because the cap is in bytes and the cut has to
     * happen on a string whose bytes are already valid UTF-8; the marker is
     * appended after the cut rather than budgeted inside it, so the cap is a
     * cap on the *body* and reads as the number the host configured.
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

        return Text::truncate($value, $max).self::TRUNCATED;
    }

    /** `prism.request.max_body`, in bytes. `0` or less means no cap. */
    private function maxBytes(): int
    {
        $max = $this->config->get('prism.request.max_body', 65536);

        return is_numeric($max) ? (int) $max : 65536;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function encode(array $body): string
    {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return is_string($json) ? $json : '';
    }

    /**
     * @param  callable(): string  $read
     */
    private function guard(callable $read): string
    {
        try {
            return $read();
        } catch (Throwable) {
            return '';
        }
    }
}
