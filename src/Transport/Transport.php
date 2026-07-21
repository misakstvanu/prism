<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Transport;

/**
 * Ships a wire envelope to the Prism ingest endpoint (US-038).
 *
 * The interface exists so the flush pipeline depends on the act of sending,
 * not on HTTP — a queued job, a test double or an alternate transport can all
 * stand in for {@see HttpTransport}. Implementations MUST NOT throw: a failed
 * or timed-out send is the norm for a fire-and-forget telemetry client, and it
 * must never surface to the host application.
 */
interface Transport
{
    /**
     * Send one envelope. Returns true on a 2xx response, false on any failure
     * (a non-2xx status, a timeout, a transport error). Never throws.
     *
     * @param  array<string, mixed>  $envelope  The versioned batch envelope.
     */
    public function send(array $envelope): bool;
}
