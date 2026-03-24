<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

/**
 * Toolkit that is restricted to specific agent roles.
 *
 * When a toolkit implements this interface, OrchestratorAgent checks
 * the active role against roleScope() before registering it. Toolkits
 * whose scope does not match the active role are silently skipped.
 *
 * Return null from roleScope() to indicate no restriction (toolkit is
 * available to all roles — equivalent to not implementing this interface).
 */
interface RoleScopedToolkitInterface extends ToolkitInterface
{
    /**
     * The role name(s) this toolkit is restricted to.
     *
     * @return string|list<string>|null Role name, array of names, or null for unrestricted.
     */
    public function roleScope(): string|array|null;
}
