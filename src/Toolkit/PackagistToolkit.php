<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Tool\PackagistTool;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * System toolkit providing Packagist package discovery and evaluation.
 *
 * Always loaded — gives the agent first-class package search capabilities
 * so it can find existing solutions before building from scratch.
 */
final class PackagistToolkit implements ToolkitInterface
{
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'headers' => [
                'User-Agent' => 'Coqui/1.0 (https://github.com/AgentCoqui/coqui)',
            ],
        ]);
    }

    public function tools(): array
    {
        return [
            new PackagistTool(httpClient: $this->httpClient),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <PACKAGIST-TOOLKIT-GUIDELINES>
            ## Package Discovery

            Use the `packagist` tool to discover and evaluate PHP packages BEFORE
            building anything from scratch. There is almost always an existing package
            that solves your problem.

            **Recommended workflow:**
            1. `packagist(action: "search", query: "...")` → find candidate packages
            2. `packagist(action: "details", package: "vendor/name")` → evaluate downloads, maintainers, security
            3. `composer(action: "add", package: "vendor/name")` → install the vetted package

            **When to search Packagist:**
            - Before implementing any non-trivial functionality
            - When asked to integrate with an external service or API
            - When you need data parsing, formatting, or transformation utilities
            - When you need HTTP clients, database drivers, or other infrastructure

            All endpoints are anonymous — no authentication required.
            </PACKAGIST-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
