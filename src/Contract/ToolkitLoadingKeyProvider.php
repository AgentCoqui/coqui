<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Optional contract for toolkits that need a stable loading-registry key that
 * is different from their PHP class basename.
 */
interface ToolkitLoadingKeyProvider
{
    public function toolkitLoadingKey(): string;
}