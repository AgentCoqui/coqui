<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CarmeloSantana\PHPAgents\Enum\ReasoningEffort;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Config\BootManager;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /thinking — show or set reasoning effort for the active model.
 *
 * Persists `reasoningEffort` on the model entry in openclaw.json and
 * applies it to the live config so the next turn picks it up without a
 * restart. `off` maps to Ollama's `reasoning_effort: "none"`, which
 * disables thinking on thinking-capable models.
 */
final readonly class ThinkingHandler
{
    private const string SETTING_KEY = 'reasoningEffort';

    public function __construct(
        private BootManager $boot,
    ) {}

    public function handle(SymfonyStyle $io, string $arg, string $activeRole, ?string $activePersona = null): void
    {
        $model = $this->boot->roleResolver()->resolve($activeRole, $activePersona);
        if ($model === '') {
            $io->error('No model resolved for the active role.');
            return;
        }

        [$provider, $modelId] = ProviderFactory::parseModelString($model);

        $arg = strtolower(trim($arg));
        if ($arg === '') {
            $this->showStatus($io, $model, $provider, $modelId);
            return;
        }

        if ($arg === 'clear') {
            $this->writeSetting($io, $model, $provider, $modelId, null);
            return;
        }

        $effort = ReasoningEffort::tryFrom($arg === 'off' ? 'none' : $arg);
        if ($effort === null) {
            $io->error('Usage: /thinking [off|low|medium|high|clear]');
            return;
        }

        $this->writeSetting($io, $model, $provider, $modelId, $effort->value);
    }

    private function showStatus(SymfonyStyle $io, string $model, string $provider, string $modelId): void
    {
        $definition = $this->boot->config()->getModelDefinition($model)
            ?? $this->boot->config()->getModelDefinition($modelId);

        $current = $definition?->extras[self::SETTING_KEY] ?? null;

        $io->section('Thinking');
        $io->definitionList(
            ['Model' => $model],
            ['Thinking-capable' => ($definition->thinking ?? false) ? 'yes' : 'unknown'],
            ['Reasoning effort' => is_string($current) ? $current : 'model default'],
        );
        $io->text('<fg=gray>Set with /thinking off|low|medium|high — /thinking clear restores the model default.</>');

        if ($provider !== 'ollama') {
            $io->text(sprintf('<fg=yellow>Note: reasoning effort is currently applied to Ollama models only (active provider: %s).</>', $provider));
        }
    }

    private function writeSetting(SymfonyStyle $io, string $model, string $provider, string $modelId, ?string $value): void
    {
        $configManager = $this->boot->configManager();
        $data = $configManager->toArray();

        $models = $data['models']['providers'][$provider]['models'] ?? null;
        $entryIndex = null;
        if (is_array($models)) {
            foreach ($models as $index => $modelData) {
                if (is_array($modelData) && ($modelData['id'] ?? null) === $modelId) {
                    $entryIndex = $index;
                    break;
                }
            }
        }

        if ($entryIndex === null) {
            $io->warning(sprintf(
                'Model %s has no entry under models.providers.%s.models in %s — add one to control thinking.',
                $model,
                $provider,
                $configManager->path(),
            ));
            return;
        }

        if ($value === null) {
            unset($data['models']['providers'][$provider]['models'][$entryIndex][self::SETTING_KEY]);
        } else {
            $data['models']['providers'][$provider]['models'][$entryIndex][self::SETTING_KEY] = $value;
        }

        $errors = $configManager->save($data);
        if ($errors !== []) {
            $io->error('Config validation failed: ' . implode('; ', $errors));
            return;
        }

        // Keep the shared runtime config in sync so the change applies on
        // the next turn without a restart.
        $this->boot->config()->applyModelSetting($provider, $modelId, self::SETTING_KEY, $value);

        $io->success($value === null
            ? sprintf('Reasoning effort cleared for %s — model default applies on your next message.', $model)
            : sprintf('Reasoning effort set to "%s" for %s — applies on your next message.', $value, $model));

        if ($provider !== 'ollama') {
            $io->text(sprintf('<fg=yellow>Note: reasoning effort is currently applied to Ollama models only (active provider: %s).</>', $provider));
        }
    }
}
