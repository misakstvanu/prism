<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Tests;

use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery\CurlClient;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery\DiscoveryInterface;
use Psr\Http\Client\ClientInterface;

/**
 * A PSR-18 client discoverer that records every time the OpenTelemetry SDK
 * reaches for an HTTP client.
 *
 * This is the OpenTelemetry half of the trick the ingest-swap suite plays
 * with a counting stream factory: "no OTLP request is attempted" is a property
 * that cannot be asserted by watching the network, because a collector that is
 * not running fails in exactly the way a working install looks from here. What
 * CAN be watched is the one funnel every OTLP transport goes through —
 * `PsrTransportFactory::create()` calls `Discovery::find()` before it builds
 * anything — so an empty recorder means no transport was ever constructed, and
 * therefore that nothing could have been sent.
 *
 * It records and then declines, so the SDK falls through to its own defaults
 * and a legitimate discovery elsewhere still works.
 */
final class RecordingClientDiscovery implements DiscoveryInterface
{
    /** @var list<array<string, mixed>> */
    public static array $found = [];

    public static function reset(): void
    {
        self::$found = [];
    }

    public function available(): bool
    {
        self::$found[] = [];

        return false;
    }

    public function create(mixed $options): ClientInterface
    {
        self::$found[] = is_array($options) ? $options : [];

        return (new CurlClient)->create($options);
    }
}
