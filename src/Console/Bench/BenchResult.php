<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Console\Bench;

/**
 * One configuration's measurement (US-023) — what a child process reports back
 * to the process that spawned it, and the row the report prints.
 *
 * It is a plain value object with an explicit array shape on both sides because
 * it crosses a process boundary as JSON: the four configurations cannot be
 * compared inside one process (which engines are listening is decided during
 * `register()`, long before a command runs), so each is measured in a
 * subprocess of its own and its answer is parsed back here.
 *
 * A **skipped** result is a first-class outcome rather than a zero. The old
 * client is materialised out of git, which is not something every install can
 * do — and a zero row would read as "the old client cost nothing", which is the
 * opposite of what a missing measurement means.
 */
final class BenchResult
{
    /**
     * @param  string  $profile  The configuration's key (`off`, `legacy`, …).
     * @param  string  $label  Its human-readable name, as printed.
     * @param  bool  $measured  False when the configuration could not be run.
     * @param  string|null  $skipReason  Why, when it was not.
     * @param  int  $requests  Timed request lifecycles behind these figures.
     * @param  float  $meanUs  Mean wall-clock per request, microseconds.
     * @param  float  $medianUs  Median wall-clock per request, microseconds.
     * @param  float  $p95Us  95th percentile wall-clock per request, microseconds.
     * @param  int  $peakMemoryBytes  Highest per-request peak memory observed.
     * @param  float  $eventsPerRequest  Prism events shipped per request.
     * @param  int  $bufferDropped  Events the buffer refused for capacity.
     * @param  int  $translateDropped  Records the ingest could not translate.
     * @param  int  $bufferCapacity  `prism.batch.size` in the measured process.
     * @param  bool  $protobufTouched  Whether any protobuf class was loaded.
     */
    public function __construct(
        public readonly string $profile,
        public readonly string $label,
        public readonly bool $measured,
        public readonly ?string $skipReason = null,
        public readonly int $requests = 0,
        public readonly float $meanUs = 0.0,
        public readonly float $medianUs = 0.0,
        public readonly float $p95Us = 0.0,
        public readonly int $peakMemoryBytes = 0,
        public readonly float $eventsPerRequest = 0.0,
        public readonly int $bufferDropped = 0,
        public readonly int $translateDropped = 0,
        public readonly int $bufferCapacity = 0,
        public readonly bool $protobufTouched = false,
    ) {}

    /** A configuration that could not be measured, and the reason it could not. */
    public static function skipped(string $profile, string $label, string $reason): self
    {
        return new self($profile, $label, measured: false, skipReason: $reason);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'label' => $this->label,
            'measured' => $this->measured,
            'skip_reason' => $this->skipReason,
            'requests' => $this->requests,
            'mean_us' => $this->meanUs,
            'median_us' => $this->medianUs,
            'p95_us' => $this->p95Us,
            'peak_memory_bytes' => $this->peakMemoryBytes,
            'events_per_request' => $this->eventsPerRequest,
            'buffer_dropped' => $this->bufferDropped,
            'translate_dropped' => $this->translateDropped,
            'buffer_capacity' => $this->bufferCapacity,
            'protobuf_touched' => $this->protobufTouched,
        ];
    }

    /**
     * Rebuild a result from the JSON a child process printed.
     *
     * Every field is read defensively: the child is a separate PHP process and
     * a malformed answer must come back as a skipped row naming the problem,
     * never as a plausible-looking zero.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            profile: is_string($data['profile'] ?? null) ? $data['profile'] : 'unknown',
            label: is_string($data['label'] ?? null) ? $data['label'] : 'unknown',
            measured: (bool) ($data['measured'] ?? false),
            skipReason: is_string($data['skip_reason'] ?? null) ? $data['skip_reason'] : null,
            requests: (int) ($data['requests'] ?? 0),
            meanUs: (float) ($data['mean_us'] ?? 0),
            medianUs: (float) ($data['median_us'] ?? 0),
            p95Us: (float) ($data['p95_us'] ?? 0),
            peakMemoryBytes: (int) ($data['peak_memory_bytes'] ?? 0),
            eventsPerRequest: (float) ($data['events_per_request'] ?? 0),
            bufferDropped: (int) ($data['buffer_dropped'] ?? 0),
            translateDropped: (int) ($data['translate_dropped'] ?? 0),
            bufferCapacity: (int) ($data['buffer_capacity'] ?? 0),
            protobufTouched: (bool) ($data['protobuf_touched'] ?? false),
        );
    }
}
