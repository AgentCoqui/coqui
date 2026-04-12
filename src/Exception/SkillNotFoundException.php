<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

final class SkillNotFoundException extends \RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(sprintf(
            'Skill "%s" not found. Use coqui_skills(action: "list") to see available skills.',
            $name,
        ));
    }
}
