<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

/**
 * Optional contract for toolkits that expose runtime child toolkits.
 *
 * Child toolkits are fed through Coqui's normal budget/deferment pipeline so
 * dynamic sources can participate in eager/deferred loading without a second
 * budgeting system.
 */
interface CompositeToolkitProvider
{
    /**
     * @return list<ToolkitInterface>
     */
    public function childToolkits(): array;
}