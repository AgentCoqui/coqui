<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CarmeloSantana\CoquiToolkitImages\Support\ImageCommandParser;
use CarmeloSantana\CoquiToolkitImages\Support\OllamaModelPullHelper;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use CoquiBot\Coqui\Repl\InterruptiblePrompt;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class ImageHandler
{
    private ImageCommandParser $parser;

    public function __construct(
        private BootManager $boot,
    ) {
        $this->parser = new ImageCommandParser();
    }

    public function handle(SymfonyStyle $io, string $arg, ?string $activeProfile, string $sessionId): void
    {
        $arg = trim($arg);

        if ($arg === '' || $arg === 'help') {
            $this->renderHelp($io);
            return;
        }

        [$command, $rest] = array_pad(explode(' ', $arg, 2), 2, '');
        $tools = $this->resolveImageTools($activeProfile, $sessionId);

        if ($tools === null) {
            $io->warning([
                'The image toolkit is not currently available.',
                'Install or enable `carmelosantana/coqui-toolkit-images`, then restart Coqui.',
            ]);
            return;
        }

        $result = match ($command) {
            'generate' => $this->generate($io, $rest, $tools),
            'list' => $this->dispatchParsed($this->parser->parseListInput($rest), $tools['image_library']),
            'search' => $this->dispatchParsed($this->parser->parseSearchInput($rest), $tools['image_library']),
            'get' => $this->dispatchParsed($this->parser->parseGetInput($rest), $tools['image_library']),
            'tag' => $this->dispatchParsed($this->parser->parseTagInput($rest), $tools['image_library']),
            'delete' => $this->dispatchParsed($this->parser->parseDeleteInput($rest), $tools['image_library']),
            'config' => $tools['image_config']->execute([]),
            default => ToolResult::error('Unknown /image subcommand: ' . $command),
        };

        $this->renderResult($io, $result, $command);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function dispatchParsed(array $input, ToolInterface $tool): ToolResult
    {
        if (isset($input['__error'])) {
            return ToolResult::error((string) $input['__error']);
        }

        return $tool->execute($input);
    }

    /**
     * @return array{image_generate: ToolInterface, image_library: ToolInterface, image_config: ToolInterface, image_preflight?: ToolInterface|null}|null
     */
    private function resolveImageTools(?string $activeProfile, string $sessionId): ?array
    {
        foreach ($this->boot->discovery()->instantiateRegisteredGrouped(context: [
            'config' => $this->boot->config(),
            'activeProfile' => $activeProfile,
            'sessionId' => $sessionId,
        ]) as $entry) {
            if ($this->boot->visibilityRegistry()->getPackageVisibility($entry['package']) !== ToolkitVisibility::Enabled) {
                continue;
            }

            $resolved = [];
            foreach ($entry['toolkit']->tools() as $tool) {
                $resolved[$tool->name()] = $tool;
            }

            if (isset($resolved['image_generate'], $resolved['image_library'], $resolved['image_config'])) {
                return [
                    'image_preflight' => $resolved['image_preflight'] ?? null,
                    'image_generate' => $resolved['image_generate'],
                    'image_library' => $resolved['image_library'],
                    'image_config' => $resolved['image_config'],
                ];
            }
        }

        return null;
    }

    /**
     * @param array{image_generate: ToolInterface, image_library: ToolInterface, image_config: ToolInterface, image_preflight?: ToolInterface|null} $tools
     */
    private function generate(SymfonyStyle $io, string $arg, array $tools): ToolResult
    {
        $input = $this->parser->parseGenerateInput($arg);
        if (isset($input['__error'])) {
            return ToolResult::error((string) $input['__error']);
        }

        $preflightTool = $tools['image_preflight'] ?? null;
        $resolvedModel = null;

        if ($preflightTool instanceof ToolInterface) {
            $preflight = $this->runGeneratePreflight($io, $preflightTool, $input);
            if ($preflight instanceof ToolResult) {
                return $preflight;
            }

            if (isset($preflight['vendor'], $preflight['model']) && is_string($preflight['vendor']) && is_string($preflight['model'])) {
                $resolvedModel = $preflight['vendor'] . '/' . $preflight['model'];
            }
        }

        if ($resolvedModel !== null) {
            $io->text(sprintf('<fg=gray>Generating image with %s. This may take a while.</>', $resolvedModel));
        } else {
            $io->text('<fg=gray>Generating image. This may take a while.</>');
        }

        return $tools['image_generate']->execute($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|ToolResult
     */
    private function runGeneratePreflight(SymfonyStyle $io, ToolInterface $tool, array $input): array|ToolResult
    {
        $preflightResult = $tool->execute($input);
        if ($preflightResult->status->value === 'error') {
            return $preflightResult;
        }

        $payload = json_decode($preflightResult->content, true);
        if (!is_array($payload)) {
            return ToolResult::error('Image preflight returned an invalid response.');
        }

        $downloadRequired = ($payload['download_required'] ?? false) === true;
        $canGenerate = ($payload['can_generate'] ?? false) === true;

        if (!$downloadRequired) {
            if (!$canGenerate) {
                return ToolResult::error((string) ($payload['reason'] ?? 'Image generation prerequisites are not satisfied.'));
            }

            return $payload;
        }

        $model = is_string($payload['model'] ?? null) ? $payload['model'] : null;
        if ($model === null || $model === '') {
            return ToolResult::error('Image preflight did not report which Ollama model needs to be downloaded.');
        }

        if (!$this->isInteractiveTerminal($io)) {
            return ToolResult::error(sprintf(
                'Ollama image model "%s" is not available locally. Pull it first with `%s`, then retry `/image generate`.',
                $model,
                $payload['download_command'] ?? ('ollama pull ' . $model),
            ));
        }

        $prompt = new InterruptiblePrompt($io);
        $confirm = $prompt->confirm(
            sprintf('Ollama image model "%s" is not installed locally. Download it now?', $model),
            false,
        );

        if (!$confirm) {
            return ToolResult::success('Image generation cancelled before downloading the required Ollama model.');
        }

        $pullHelper = new OllamaModelPullHelper();
        $pullResult = $pullHelper->pull($io, $model);
        if ($pullResult->status->value === 'error') {
            return $pullResult;
        }

        return $payload;
    }

    private function isInteractiveTerminal(SymfonyStyle $io): bool
    {
        $inputReader = \Closure::bind(
            function (): bool {
                return $this->input->isInteractive();
            },
            $io,
            SymfonyStyle::class,
        );

        if (!$inputReader instanceof \Closure) {
            return false;
        }

        $inputIsInteractive = $inputReader();

        if (!$inputIsInteractive) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDIN);
        }

        return false;
    }

    private function renderResult(SymfonyStyle $io, ToolResult $result, string $command): void
    {
        if ($result->status->value === 'error') {
            $io->error($result->content);
            if (str_starts_with($result->content, 'Usage: /image')) {
                $this->renderHelp($io);
            }
            return;
        }

        if (in_array($command, ['list', 'search', 'get', 'tag', 'delete', 'config'], true)) {
            $io->write($result->content);
            $io->newLine();
            return;
        }

        $io->write(explode("\n", $result->content));
        $io->newLine();
    }

    private function renderHelp(SymfonyStyle $io): void
    {
        $io->section('/image');
        $io->listing([
            '/image generate <prompt> [--model=vendor/model] [--vendor=openai|ollama] [--tags=a,b] [--category=name]',
            '/image list [--profile=name] [--vendor=openai|ollama]',
            '/image search <query> [--category=name]',
            '/image get <record-id>',
            '/image tag <record-id> <tag1,tag2> [--category=name]',
            '/image delete <record-id>',
            '/image config',
        ]);
    }
}