<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Whether this process holds enough credentials to capture, and how it should
 * authenticate when it ships (US-003).
 *
 * `PRISM_TOKEN` is what binds a batch to a workspace, so out of the box a blank
 * one means the client has nowhere to send anything and no-ops. There is one
 * exception, and it is symmetrical with the hub's own: a Prism hub running in
 * the `local` environment accepts a token-less batch and attributes it to a
 * default workspace (US-002), so a client on a `local` host is allowed to ship
 * without a credential — which is what lets `composer require` plus `PRISM_APP`
 * fill a development console with nothing minted, copied or pasted.
 *
 * The rule lives here rather than at its four call sites — the boot gate, the
 * span-processor gate, `prism:check`'s validation and the transport's own
 * header — because "is a blank token fatal" and "is this send authenticated"
 * are one question, and an install that captured but shipped a `Bearer ` header
 * (or the reverse) would fail in a place that says nothing about the rule.
 */
final class Credentials
{
    /**
     * The one host environment on which a blank `PRISM_TOKEN` is allowed. It
     * must match the hub's own environment: a `local` client pointed at a
     * production hub is refused there, which is the contract holding rather
     * than breaking.
     */
    public const LOCAL_ENVIRONMENT = 'local';

    /**
     * Whether capture may be wired at all: a token is configured, or this is a
     * `local` host feeding a local hub.
     */
    public static function usable(Repository $config, string $environment): bool
    {
        return filled($config->get('prism.token')) || $environment === self::LOCAL_ENVIRONMENT;
    }

    /**
     * Whether this process captures *and* ships unauthenticated — the
     * token-less local path, and the one case where a send carries no
     * `Authorization` header.
     */
    public static function tokenless(Repository $config, string $environment): bool
    {
        return self::usable($config, $environment) && blank($config->get('prism.token'));
    }
}
