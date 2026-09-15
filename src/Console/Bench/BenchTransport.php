<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Console\Bench;

use Misakstvanu\Prism\Transport\HttpTransport;
use Misakstvanu\Prism\Transport\Transport;

/**
 * The transport the capture benchmark runs against (US-023): it counts what a
 * run would have shipped and sends nothing.
 *
 * Letting {@see HttpTransport} do its job would time a network round trip,
 * whose latency dwarfs the whole capture path and varies more between two runs
 * than the difference being measured. Not a shortcut: the question is what
 * capture costs the *host's request*, and delivery is deliberately off that
 * path already (the flush runs in a `terminating` callback, after the response
 * has gone).
 *
 * Counting the events is the other half. "Events emitted per request" is one of
 * the three figures the benchmark reports, and the envelope arriving here is
 * the only place the number is true for a whole execution — the buffer has been
 * drained by then, and the per-signal counters upstream keeps describe records,
 * not the Prism events they translated into.
 */
final class BenchTransport implements Transport
{
    private int $envelopes = 0;

    private int $events = 0;

    /**
     * Count one envelope and its events, then report success — a `false` would
     * be read as a delivery failure by anything watching, and nothing failed.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function send(array $envelope): bool
    {
        $this->envelopes++;

        $events = $envelope['events'] ?? null;

        if (is_array($events)) {
            $this->events += count($events);
        }

        return true;
    }

    /**
     * Drop everything counted so far. Called between the warm-up and the timed
     * run: warm-up executions ship real batches too, and counting them would
     * inflate the per-request figure by however many warm-up iterations were
     * asked for.
     */
    public function reset(): void
    {
        $this->envelopes = 0;
        $this->events = 0;
    }

    public function envelopes(): int
    {
        return $this->envelopes;
    }

    public function events(): int
    {
        return $this->events;
    }
}
