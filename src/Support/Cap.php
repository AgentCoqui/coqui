<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * CAP protocol constants.
 *
 * `PROTOCOL_VERSION` is the semver of the CAP wire contract this instance
 * implements — the value served as `InstanceInfo.protocol_version`. It is
 * deliberately distinct from {@see AppVersion} (the product/build version) and
 * from the MCP wire version (`McpClient::PROTOCOL_VERSION`); a client reads this
 * to negotiate the CAP surface independently of the coqui release it is talking to.
 */
final class Cap
{
    /**
     * Semver of the CAP protocol implemented by this instance.
     */
    public const string PROTOCOL_VERSION = '0.5.0';
}
