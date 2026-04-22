<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Repl\ReplCommandCatalog;
use CoquiBot\Coqui\Repl\ReplCommandSpec;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Runtime slash-command catalog for API clients.
 */
final class CommandCatalogHandler
{
    public function get(ServerRequestInterface $request): Response
    {
        $commands = array_map(
            static fn(ReplCommandSpec $spec): array => [
                'name' => $spec->name,
                'usage' => $spec->usage,
                'description' => $spec->description,
                'help_description' => $spec->helpDescription(),
                'aliases' => $spec->aliases,
                'first_arguments' => $spec->firstArguments,
                'section' => $spec->section,
            ],
            ReplCommandCatalog::all(),
        );

        $sections = [];
        foreach ($commands as $command) {
            $sectionName = $command['section'];
            if (!isset($sections[$sectionName])) {
                $sections[$sectionName] = [
                    'name' => $sectionName,
                    'commands' => [],
                ];
            }

            $sections[$sectionName]['commands'][] = $command;
        }

        return Router::jsonResponse([
            'sections' => array_values($sections),
            'commands' => $commands,
            'count' => count($commands),
        ]);
    }
}