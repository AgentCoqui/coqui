<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Export;

use CoquiBot\Coqui\Contract\RoleProperties;

/**
 * Projects parsed role-file frontmatter ({@see RoleProperties}) to a CAP 0.5.0
 * `role.json` wire object.
 *
 * Roles are file-based capability profiles. The producer emits the schema's
 * closed property set; coqui's comma-separated `toolkits` string is split into
 * the schema's string list (`null` when unset). `RoleProperties` already carries
 * a `version` (defaulting to 1), so no synthetic stamp is needed.
 */
final class RoleProducer
{
    /**
     * @return array<string, mixed>
     */
    public static function toWire(RoleProperties $role): array
    {
        $toolkits = null;
        if (is_string($role->toolkits) && trim($role->toolkits) !== '') {
            $toolkits = array_values(array_filter(
                array_map('trim', explode(',', $role->toolkits)),
                static fn(string $t): bool => $t !== '',
            ));
        }

        return [
            'name' => $role->name,
            'access_level' => $role->accessLevel,
            'version' => max(1, $role->version),
            'model' => $role->model,
            'toolkits' => $toolkits,
            'max_iterations' => $role->maxIterations,
        ];
    }
}
