<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Export;

use CoquiBot\Coqui\Contract\LoopDefinition;
use CoquiBot\Coqui\Contract\LoopRoleDefinition;

/**
 * Projects a file-based {@see LoopDefinition} to a CAP 0.5.0 `loop-definition.json`
 * wire object.
 *
 * coqui loop definitions are FILE-based (config/loops/*.json + workspace/loops/*.json)
 * and parsed by {@see LoopDefinition}; there is no `loop_definitions` DB table. The
 * contract carries no optimistic-concurrency token, but the schema REQUIRES `version`
 * (integer, minimum 1) — file definitions are therefore stamped `version: 1`
 * (server-assigned baseline), overridable by the caller when a store later tracks it.
 *
 * The role-stage projection emits exactly the schema's closed property set
 * (`name`, `role`, `prompt`, `order`, `gate`, `artifact_required`, `memory_required`)
 * and DROPS the coqui-only `skills`/`max_iterations` overrides, which the
 * `additionalProperties:false` role item does not allow. `order` is derived from the
 * ordered position because {@see LoopRoleDefinition} does not persist it.
 * `termination_condition` is emitted by {@see \CoquiBot\Coqui\Contract\TerminationCondition::toArray()},
 * which already produces the discriminated `{type, value}` shape keyed by type.
 */
final class LoopDefinitionProducer
{
    /**
     * @return array<string, mixed>
     */
    public static function toWire(LoopDefinition $definition, int $version = 1): array
    {
        $roles = [];
        foreach ($definition->roles as $index => $role) {
            $roles[] = self::roleToWire($role, $index);
        }

        $wire = [
            'name' => $definition->name,
            'version' => max(1, $version),
            'description' => $definition->description,
            'roles' => $roles,
            'termination_condition' => $definition->terminationCondition->toArray(),
        ];

        if ($definition->parameters !== []) {
            $wire['parameters'] = array_map(
                static fn($parameter) => $parameter->toArray(),
                $definition->parameters,
            );
        }

        return $wire;
    }

    /**
     * @return array<string, mixed>
     */
    private static function roleToWire(LoopRoleDefinition $role, int $order): array
    {
        return [
            'role' => $role->role,
            'prompt' => $role->prompt,
            'order' => $order,
            'gate' => $role->gate,
            'artifact_required' => $role->artifactRequired,
            'memory_required' => $role->memoryRequired,
        ];
    }
}
