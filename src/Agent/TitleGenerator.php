<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;

/**
 * Generates a concise title for a session based on the first user prompt.
 *
 * Uses the title_generator role instructions and a lightweight model.
 * Designed as a non-blocking single-shot call — no tool-use loop.
 */
final class TitleGenerator
{
    private const string ROLE = 'title-generator';

    private const string FALLBACK_INSTRUCTIONS = <<<'PROMPT'
        Generate a concise title (3-7 words) that captures the essence of the user's request.
        Return ONLY the title text — no quotes, no punctuation, no explanation.
        PROMPT;

    public function __construct(
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly ?RoleDiscovery $roleDiscovery = null,
        private readonly ?ProviderFactory $providerFactory = null,
    ) {}

    /**
     * Generate a title from the first user prompt.
     *
     * Returns null if title generation fails for any reason — this is
     * intentionally best-effort and must never block the main response.
     */
    public function generate(string $userPrompt): ?string
    {
        try {
            $instructions = $this->resolveInstructions();
            $provider = $this->resolveProvider();

            $response = $provider->chat([
                new SystemMessage($instructions),
                new UserMessage($userPrompt),
            ]);

            $title = trim($response->content);

            // Strip surrounding quotes if present
            $title = trim($title, '"\'');

            // Sanity check — reject empty or absurdly long titles
            if ($title === '' || mb_strlen($title) > 100) {
                return null;
            }

            return $title;
        } catch (\Throwable $e) {
            // Title generation is best-effort — never propagate errors
            error_log(sprintf(
                '[Coqui] Title generation failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));

            return null;
        }
    }

    private function resolveInstructions(): string
    {
        if ($this->roleDiscovery !== null) {
            try {
                return $this->roleDiscovery->readInstructions(self::ROLE);
            } catch (\Throwable) {
                // Fall through
            }
        }

        return self::FALLBACK_INSTRUCTIONS;
    }

    private function resolveProvider(): \CarmeloSantana\PHPAgents\Contract\ProviderInterface
    {
        $modelString = $this->roleResolver->resolveUtility();

        $factory = $this->providerFactory ?? new ProviderFactory($this->config);

        return $factory->create($modelString);
    }
}
