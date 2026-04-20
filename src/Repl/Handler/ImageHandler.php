<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CarmeloSantana\CoquiToolkitImages\Support\ImageCommandParser;
use CarmeloSantana\CoquiToolkitImages\Support\OllamaModelPullHelper;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use CoquiBot\Coqui\Observer\AnimatedTickCallback;
use CoquiBot\Coqui\Repl\InterruptiblePrompt;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class ImageHandler
{
    private const string GENERATE_OUTPUT_FORMAT = 'json';

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

        $input['output_format'] = self::GENERATE_OUTPUT_FORMAT;
        $progress = $this->createGenerateProgress($io, $resolvedModel);
        if ($progress !== null) {
            $input['__progress_callback'] = $progress['callback'];
        } elseif ($resolvedModel !== null) {
            $io->text(sprintf('<fg=gray>Generating image with %s. This may take a while.</>', $resolvedModel));
        } else {
            $io->text('<fg=gray>Generating image. This may take a while.</>');
        }

        try {
            return $tools['image_generate']->execute($input);
        } finally {
            if ($progress !== null) {
                $progress['spinner']->stop();
            }
        }
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
        $input = $this->extractInput($io);
        if (!$input instanceof InputInterface) {
            return false;
        }

        if (!$input->isInteractive()) {
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

        if ($command === 'generate') {
            $this->renderGenerateResult($io, $result);
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

    private function renderGenerateResult(SymfonyStyle $io, ToolResult $result): void
    {
        $payload = json_decode($result->content, true);
        if (!is_array($payload)) {
            $io->write(explode("\n", $result->content));
            $io->newLine();

            return;
        }

        $message = is_string($payload['message'] ?? null)
            ? $payload['message']
            : 'Image generated successfully.';
        $record = is_array($payload['record'] ?? null) ? $payload['record'] : [];
        $path = is_string($payload['saved_path'] ?? null)
            ? $payload['saved_path']
            : (is_string($record['path'] ?? null) ? $record['path'] : null);
        $preview = is_string($payload['preview'] ?? null) ? $payload['preview'] : null;
        $previewReason = is_string($payload['preview_unavailable_reason'] ?? null) ? $payload['preview_unavailable_reason'] : null;
        $metadataReason = is_string($payload['metadata_unavailable_reason'] ?? null) ? $payload['metadata_unavailable_reason'] : null;

        $io->success($message);

        $lines = [];
        if ($path !== null) {
            $lines[] = '<fg=gray>Saved path:</> ' . $path;
            $lines[] = '<fg=gray>Open:</> file://' . $path;
        }

        if (is_string($record['id'] ?? null)) {
            $lines[] = '<fg=gray>Record ID:</> ' . $record['id'];
        }

        if (is_string($record['vendor'] ?? null) && is_string($record['model'] ?? null)) {
            $lines[] = '<fg=gray>Model:</> ' . $record['vendor'] . '/' . $record['model'];
        }

        if (is_string($record['format'] ?? null) && $record['format'] !== '') {
            $lines[] = '<fg=gray>Format:</> ' . strtoupper((string) $record['format']);
        }

        $lines[] = '<fg=gray>Metadata embedded:</> ' . ((($record['metadata_embedded'] ?? false) === true) ? 'yes' : 'no');

        if ($metadataReason !== null) {
            $lines[] = '<fg=gray>Metadata note:</> ' . $metadataReason;
        }

        $io->text($lines);

        if ($preview !== null && trim($preview) !== '') {
            $io->newLine();
            $io->section('Preview');
            $io->write(explode("\n", $preview));
            $io->newLine();

            return;
        }

        if ($previewReason !== null) {
            $io->newLine();
            $io->text('<fg=gray>Preview unavailable:</> ' . $previewReason);
        }
    }

    /**
     * @return array{spinner: AnimatedTickCallback, callback: \Closure(string): void}|null
     */
    private function createGenerateProgress(SymfonyStyle $io, ?string $resolvedModel): ?array
    {
        if (!$this->isInteractiveTerminal($io)) {
            return null;
        }

        $output = $this->extractOutput($io);
        if (!$output instanceof OutputInterface || !$output->isDecorated()) {
            return null;
        }

        $spinner = new AnimatedTickCallback($output);
        $spinner->start($this->defaultGenerateContext($resolvedModel));

        $callback = function (string $status) use ($spinner): void {
            $spinner->setContext($this->formatGenerateProgressContext($status));
            $spinner->tick();
        };

        return [
            'spinner' => $spinner,
            'callback' => $callback,
        ];
    }

    private function defaultGenerateContext(?string $resolvedModel): string
    {
        if ($resolvedModel === null) {
            return 'image generation';
        }

        return 'image generation with ' . $resolvedModel;
    }

    private function formatGenerateProgressContext(string $status): string
    {
        $normalized = trim(strtolower($status));

        return match ($normalized) {
            'contacting-openai' => 'contacting OpenAI',
            'writing-image' => 'writing generated image',
            'finalizing-image' => 'finalizing generated image',
            default => preg_replace('/\s+/', ' ', str_replace('-', ' ', $normalized)) ?? 'image generation',
        };
    }

    private function extractOutput(SymfonyStyle $io): ?OutputInterface
    {
        $output = $this->readStyleProperty($io, 'output');

        return $output instanceof OutputInterface ? $output : null;
    }

    private function extractInput(SymfonyStyle $io): ?InputInterface
    {
        $input = $this->readStyleProperty($io, 'input');

        return $input instanceof InputInterface ? $input : null;
    }

    private function readStyleProperty(SymfonyStyle $io, string $property): mixed
    {
        $reflection = new \ReflectionObject($io);

        while ($reflection !== false) {
            if ($reflection->hasProperty($property)) {
                $resolvedProperty = $reflection->getProperty($property);
                $resolvedProperty->setAccessible(true);

                return $resolvedProperty->getValue($io);
            }

            $reflection = $reflection->getParentClass();
        }

        return null;
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