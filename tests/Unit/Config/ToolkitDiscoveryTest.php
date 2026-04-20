<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Config\ToolkitDiscovery;

final class ContextAwareTestToolkit implements ToolkitInterface
{
    /** @var array<string, mixed> */
    public array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(array $context)
    {
        $this->context = $context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fromCoquiContext(array $context): self
    {
        return new self($context);
    }

    public function guidelines(): string
    {
        return 'Test instructions';
    }

    public function tools(): array
    {
        return [];
    }
}

test('toolkit discovery prefers fromCoquiContext and passes runtime context', function (): void {
    $workspacePath = sys_get_temp_dir() . '/coqui-toolkit-discovery-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);

    try {
        $discovery = new ToolkitDiscovery(dirname(__DIR__, 3), $workspacePath);
        $discovery->register('acme/context-aware-toolkit', [ContextAwareTestToolkit::class]);

        $entries = $discovery->instantiateRegisteredGrouped(context: [
            'config' => 'config-object',
            'activeProfile' => 'caelum',
            'sessionId' => 'session-1',
        ]);

        expect($entries)->toHaveCount(1);
        expect($entries[0]['toolkit'])->toBeInstanceOf(ContextAwareTestToolkit::class);
        expect($entries[0]['toolkit']->context['config'])->toBe('config-object');
        expect($entries[0]['toolkit']->context['activeProfile'])->toBe('caelum');
        expect($entries[0]['toolkit']->context['sessionId'])->toBe('session-1');
        expect($entries[0]['toolkit']->context['workspacePath'])->toBe($workspacePath);
        expect($entries[0]['toolkit']->context['packageName'])->toBe('acme/context-aware-toolkit');
    } finally {
        cleanupTestTree($workspacePath);
    }
});