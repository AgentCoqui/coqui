<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Toolkit visibility management endpoints.
 *
 * GET  /api/v1/toolkits            — list all registered toolkits with visibility
 * POST /api/v1/toolkits/visibility — set visibility for a package or standalone tool
 */
final readonly class ToolkitHandler
{
    public function __construct(
        private ToolkitDiscovery $discovery,
        private ToolkitVisibilityRegistry $visibilityRegistry,
        private ?AgentRunner $agentRunner = null,
    ) {}

    /**
     * GET /api/v1/toolkits
     *
     * Returns all registered packages with their current visibility plus any
     * explicitly-set standalone tool visibilities.
     */
    public function list(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $persona = isset($params['persona']) && trim((string) $params['persona']) !== ''
            ? strtolower(trim((string) $params['persona']))
            : null;
        $packages = $this->discovery->allWithVisibility();

        // Build token breakdown index by class FQCN
        $preview = $this->agentRunner?->buildPromptPreview(persona: $persona) ?? [
            'toolkit_breakdown' => [],
            'prompt_tokens'     => 0,
            'tool_tokens'       => 0,
            'total_tokens'      => 0,
            'persona_policy'    => null,
        ];
        $tokensByClass = [];
        foreach ($preview['toolkit_breakdown'] as $entry) {
            $tokensByClass[$entry['class']] = $entry;
        }

        // Enrich packages with per-toolkit token counts
        foreach ($packages as &$pkg) {
            $pkgTokens = 0;
            foreach ($pkg['classes'] as $cls) {
                if (isset($tokensByClass[$cls])) {
                    $pkgTokens += $tokensByClass[$cls]['total_tokens'];
                }
            }
            $pkg['tokens'] = $pkgTokens;
        }
        unset($pkg);

        $state = $this->visibilityRegistry->all();

        $tools = [];

        foreach ($state['tools'] as $toolName => $visValue) {
            $protection = match (true) {
                ToolkitVisibility::isAlwaysEnabled($toolName)                         => 'always_enabled',
                !ToolkitVisibility::canDisable($toolName)                             => 'cannot_disable',
                default                                                               => null,
            };

            $toolEntry = ['name' => $toolName, 'visibility' => $visValue];

            if ($protection !== null) {
                $toolEntry['protected'] = $protection;
            }

            $tools[] = $toolEntry;
        }

        return Router::jsonResponse([
            'toolkits'      => $packages,
            'tools'         => $tools,
            'persona'       => $persona,
            'persona_policy'=> $preview['persona_policy'],
            'prompt_tokens' => $preview['prompt_tokens'],
            'tool_tokens'   => $preview['tool_tokens'],
            'total_tokens'  => $preview['total_tokens'],
        ]);
    }

    /**
     * POST /api/v1/toolkits/visibility
     *
     * Body: { "target": "package"|"tool", "name": "vendor/pkg", "visibility": "stub" }
     *
     * Returns 400 with a descriptive error if a protection guard is violated.
     */
    public function setVisibility(ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Request body must be a JSON object.');
        }

        $target     = isset($body['target']) ? (string) $body['target'] : '';
        $name       = isset($body['name']) ? (string) $body['name'] : '';
        $visValue   = isset($body['visibility']) ? (string) $body['visibility'] : '';

        if ($target === '' || $name === '' || $visValue === '') {
            return Router::errorResponse(
                ApiErrorCode::MISSING_FIELD,
                'Required fields: "target" (package|tool), "name", "visibility" (enabled|stub|disabled).',
            );
        }

        $visibility = ToolkitVisibility::tryFrom($visValue);

        if ($visibility === null) {
            return Router::errorResponse(
                ApiErrorCode::INVALID_FORMAT,
                'Invalid visibility value. Must be one of: enabled, stub, disabled.',
            );
        }

        if (!in_array($target, ['package', 'tool'], strict: true)) {
            return Router::errorResponse(
                ApiErrorCode::INVALID_FORMAT,
                'Invalid target. Must be "package" or "tool".',
            );
        }

        try {
            if ($target === 'tool') {
                $this->visibilityRegistry->setToolVisibility($name, $visibility);
            } else {
                $this->visibilityRegistry->setPackageVisibility($name, $visibility);
            }
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::FORBIDDEN, $e->getMessage());
        }

        return Router::jsonResponse([
            'target'     => $target,
            'name'       => $name,
            'visibility' => $visibility->value,
            'note'       => 'Restart the Coqui server to apply visibility changes.',
        ]);
    }
}
