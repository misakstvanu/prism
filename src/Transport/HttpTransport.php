<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Misakstvanu\Prism\Support\Recursion;
use Throwable;

/**
 * Ships batches over HTTP to the Prism ingest endpoint (US-038).
 *
 * The payload is JSON-encoded, gzipped and POSTed with the bearer ingest token
 * under a short timeout (default 2s). Three properties make it safe on a hot
 * path:
 *
 *   - Connection reuse. The Guzzle client is built once and held for the life
 *     of the process; the transport is a container singleton, so a long-lived
 *     runtime (Octane, a queue worker) reuses the same keep-alive connection
 *     pool across every flush instead of opening a fresh socket each time.
 *
 *   - It never throws. A non-2xx status, a timeout or any transport error is
 *     caught, counted and logged to the host's own log at debug level — a
 *     telemetry client that took down the app it monitors would be worse than
 *     useless. Guzzle's own 4xx/5xx exceptions are disabled (`http_errors`
 *     off) so the status is inspected rather than thrown.
 *
 *   - Short timeout. The request and connect timeouts are bounded (config
 *     `prism.batch.timeout`, default 2s) so a slow or unreachable ingest host
 *     can never stall the process for long, even off the response's critical
 *     path.
 */
final class HttpTransport implements Transport
{
    /** Number of batches sent successfully (2xx) this process. */
    private int $sent = 0;

    /** Number of batches that failed to send (non-2xx, timeout, error). */
    private int $failed = 0;

    public function __construct(
        private readonly Repository $config,
        private ?ClientInterface $client = null,
    ) {}

    public function send(array $envelope): bool
    {
        try {
            $body = json_encode($envelope, JSON_THROW_ON_ERROR);

            $gzipped = gzencode($body, 6);

            if ($gzipped === false) {
                return $this->fail('Prism could not gzip the batch payload.');
            }

            $response = $this->client()->request('POST', $this->endpoint(), [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$this->token(),
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                    'Accept' => 'application/json',
                    'Connection' => 'keep-alive',
                    // Marks this POST as Prism's own so the client's HTTP capture
                    // (US-046) skips it rather than recording the ingest send as
                    // an outgoing request and shipping it in turn (US-039 AC1).
                    Recursion::MARKER_HEADER => '1',
                ],
                RequestOptions::BODY => $gzipped,
                RequestOptions::TIMEOUT => $this->timeout(),
                RequestOptions::CONNECT_TIMEOUT => $this->timeout(),
                RequestOptions::HTTP_ERRORS => false,
            ]);

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                $this->sent++;

                return true;
            }

            return $this->fail("Prism ingest responded with HTTP {$status}.");
        } catch (Throwable $e) {
            return $this->fail('Prism batch send failed: '.$e->getMessage());
        }
    }

    /** Count a failure, log it at debug level, and report the send as failed. */
    private function fail(string $message): bool
    {
        $this->failed++;

        Log::debug($message);

        return false;
    }

    /**
     * The persistent Guzzle client. Built lazily on first send and reused for
     * the life of the process so connections are pooled across flushes.
     */
    private function client(): ClientInterface
    {
        return $this->client ??= new Client([
            'handler' => HandlerStack::create(),
            RequestOptions::TIMEOUT => $this->timeout(),
            RequestOptions::CONNECT_TIMEOUT => $this->timeout(),
        ]);
    }

    private function endpoint(): string
    {
        return (string) $this->config->get('prism.endpoint');
    }

    private function token(): string
    {
        return (string) $this->config->get('prism.token');
    }

    private function timeout(): float
    {
        return (float) $this->config->get('prism.batch.timeout', 2.0);
    }

    /** Batches sent successfully since the process started. */
    public function sent(): int
    {
        return $this->sent;
    }

    /** Batches that failed to send since the process started. */
    public function failed(): int
    {
        return $this->failed;
    }
}
