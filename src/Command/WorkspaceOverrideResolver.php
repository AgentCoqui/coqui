<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use Symfony\Component\Console\Input\InputInterface;

final class WorkspaceOverrideResolver
{
    public static function resolve(InputInterface $input): ?string
    {
        $option = $input->getOption('workspace');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $env = getenv('COQUI_WORKSPACE');

        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }
}