<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Agent\VisionAnalyzer;

/**
 * Agent-facing tool for image analysis.
 *
 * Wraps VisionAnalyzer to expose a `vision_analyze` tool that accepts an
 * image source (file path, URL, or base64 data URI) and an optional prompt.
 * The heavy lifting — model resolution, provider routing, multimodal message
 * construction — is delegated to VisionAnalyzer.
 */
final class VisionTool implements ToolInterface
{
    public function __construct(
        private readonly VisionAnalyzer $analyzer,
    ) {}

    public function name(): string
    {
        return 'vision_analyze';
    }

    public function description(): string
    {
        return <<<'DESC'
            Analyze an image using a vision-capable model. Accepts file paths,
            URLs, or base64 data URIs. Returns a detailed structured description
            of the image content.

            Use this when:
            - The user shares an image and asks what it contains
            - You need to extract text from a screenshot
            - You need to understand a diagram, chart, or UI mockup
            - You need to describe or classify visual content
            DESC;
    }

    /**
     * @return \CarmeloSantana\PHPAgents\Tool\Parameter\Parameter[]
     */
    public function parameters(): array
    {
        return [
            new StringParameter(
                name: 'image',
                description: 'Image source: an absolute file path, an HTTP(S) URL, or a base64 data URI (data:image/...;base64,...)',
                required: true,
            ),
            new StringParameter(
                name: 'prompt',
                description: 'Optional prompt to guide the analysis (e.g. "Extract the text from this screenshot")',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $image = $input['image'] ?? '';
        if (!is_string($image) || $image === '') {
            return ToolResult::error('The `image` parameter is required and must be a non-empty string.');
        }

        $prompt = isset($input['prompt']) && is_string($input['prompt']) && $input['prompt'] !== ''
            ? $input['prompt']
            : 'Analyze this image.';

        $result = $this->analyzer->analyze($image, $prompt);

        // VisionAnalyzer returns error strings prefixed with "Error: "
        if (str_starts_with($result, 'Error: ')) {
            return ToolResult::error($result);
        }

        return ToolResult::success($result);
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'image' => [
                            'type' => 'string',
                            'description' => 'Image source: an absolute file path, an HTTP(S) URL, or a base64 data URI (data:image/...;base64,...)',
                        ],
                        'prompt' => [
                            'type' => 'string',
                            'description' => 'Optional prompt to guide the analysis',
                        ],
                    ],
                    'required' => ['image'],
                ],
            ],
        ];
    }
}
