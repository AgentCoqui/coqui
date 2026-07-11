<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Contract\ToolkitCommandExample;
use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\Coqui\Contract\ToolkitCommandHelp;
use CoquiBot\Coqui\Contract\ToolkitCommandHelpEntry;
use CoquiBot\Coqui\Contract\ToolkitCommandHelpProvider;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Repl\ReplCommandCatalog;
use CoquiBot\Coqui\Repl\SlashCommandRouter;
use CoquiBot\Coqui\Repl\ToolkitCommandCandidate;
use CoquiBot\Coqui\Repl\Handler\BackstoryHandler;
use CoquiBot\Coqui\Repl\Handler\BudgetHandler;
use CoquiBot\Coqui\Repl\Handler\ConfigHandler;
use CoquiBot\Coqui\Repl\Handler\ConversationHandler;
use CoquiBot\Coqui\Repl\Handler\GroupHandler;
use CoquiBot\Coqui\Repl\Handler\LoopHandler;
use CoquiBot\Coqui\Repl\Handler\ProfileHandler;
use CoquiBot\Coqui\Repl\Handler\ProjectHandler;
use CoquiBot\Coqui\Repl\Handler\RoleHandler;
use CoquiBot\Coqui\Repl\Handler\ScheduleHandler;
use CoquiBot\Coqui\Repl\Handler\SessionHandler;
use CoquiBot\Coqui\Repl\Handler\TaskHandler;
use CoquiBot\Coqui\Repl\Handler\ThinkingHandler;
use CoquiBot\Coqui\Repl\Handler\ToolkitVisibilityHandler;
use CoquiBot\Coqui\Support\ImagePreviewService;
use CoquiBot\Coqui\Support\PromptInspectionService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createSlashCommandRouterForHelpTest(): SlashCommandRouter
{
    return createSlashCommandRouterForToolkitTest();
}

/**
 * @param list<ToolkitCommandHandler> $toolkitCommandHandlers
 */
function createSlashCommandRouterForToolkitTest(array $toolkitCommandHandlers = []): SlashCommandRouter
{
    $instantiate = static function (string $class): object {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    };

    $output = new BufferedOutput();

    return new SlashCommandRouter(
        $instantiate(SessionHandler::class),
        $instantiate(TaskHandler::class),
        $instantiate(ScheduleHandler::class),
        $instantiate(BudgetHandler::class),
        $instantiate(ProjectHandler::class),
        $instantiate(RoleHandler::class),
        $instantiate(GroupHandler::class),
        $instantiate(ProfileHandler::class),
        $instantiate(ToolkitVisibilityHandler::class),
        $instantiate(ConfigHandler::class),
        $instantiate(ThinkingHandler::class),
        $instantiate(ConversationHandler::class),
        $instantiate(LoopHandler::class),
        $instantiate(BackstoryHandler::class),
        $instantiate(AgentRunner::class),
        $instantiate(PromptInspectionService::class),
        $output,
        sys_get_temp_dir(),
        null,
        static function (): void {},
        static function (?bool $enable = null): void {},
        $toolkitCommandHandlers,
    );
}

function createSlashRouterImagePreviewService(string $workspace): ImagePreviewService
{
    return new ImagePreviewService(
        $workspace,
        static fn(string $path, int $width): array => [
            'preview' => 'PREVIEW:' . basename($path) . ':' . $width,
            'preview_format' => 'ansi_blocks',
            'unavailable_reason' => null,
        ],
    );
}

test('slash command router renders the shared help table end to end', function (): void {
    $router = createSlashCommandRouterForHelpTest();
    $output = new BufferedOutput();
    $io = new SymfonyStyle(new ArrayInput([]), $output);

    $result = $router->route('/help', SystemRole::Orchestrator->value, 'session-1', $io);
    $display = $output->fetch();

    expect($result->shouldContinue)->toBeTrue();

    foreach (ReplCommandCatalog::helpSections() as $section => $rows) {
        expect($display)->toContain($section);

        foreach ($rows as [$usage, $description]) {
            expect($display)->toContain($usage);

            if ($usage === '/quit') {
                expect($display)->toContain('Aliases: /exit, /q');
            }
        }
    }

    expect($display)->toContain('Advanced automation commands remain available');
});

test('slash command router renders shared toolkit help for no-arg toolkit commands', function (): void {
    $handler = new class implements ToolkitCommandHandler, ToolkitCommandHelpProvider
    {
        public function commandName(): string
        {
            return 'image';
        }

        public function subcommands(): array
        {
            return ['generate'];
        }

        public function usage(): string
        {
            return '/image [action]';
        }

        public function description(): string
        {
            return 'Generate and manage images.';
        }

        public function help(): ToolkitCommandHelp
        {
            return new ToolkitCommandHelp(
                title: 'Image Generation & Management',
                summary: 'Generate and manage workspace images from the REPL.',
                subcommands: [
                    new ToolkitCommandHelpEntry('generate', '/image generate <prompt>', 'Generate an image.'),
                ],
                examples: [
                    new ToolkitCommandExample('/image generate red fox', 'Create a sample image.'),
                ],
                notes: ['Images are saved in the workspace image library.'],
            );
        }

        public function handle(\CoquiBot\Coqui\Contract\ToolkitReplContext $context, string $arg): void
        {
            throw new RuntimeException('Toolkit handler should not run for no-arg help.');
        }
    };

    ReplCommandCatalog::registerToolkitHandlers([new ToolkitCommandCandidate('vendor/images', $handler)]);

    try {
        $router = createSlashCommandRouterForToolkitTest([$handler]);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $router->route('/image', SystemRole::Orchestrator->value, 'session-1', $io);
        $display = $output->fetch();

        expect($result->shouldContinue)->toBeTrue();
    expect($display)->toContain('Image Generation & Management');
        expect($display)->toContain('Usage:');
        expect($display)->toContain('/image generate <prompt>');
        expect($display)->toContain('Create a sample image.');
        expect($display)->toContain('Images are saved in the workspace image library.');
    } finally {
        ReplCommandCatalog::clearToolkitHandlers();
    }
});

test('slash command router renders local markdown previews through its markdown helper', function (): void {
    $workspace = sys_get_temp_dir() . '/coqui-slash-router-preview-' . bin2hex(random_bytes(8));
    $imagePath = $workspace . '/images/example.png';

    mkdir(dirname($imagePath), 0755, true);
    file_put_contents($imagePath, 'fixture');

    try {
        $instantiate = static function (string $class): object {
            return (new ReflectionClass($class))->newInstanceWithoutConstructor();
        };

        $output = new BufferedOutput();
        $router = new SlashCommandRouter(
            $instantiate(SessionHandler::class),
            $instantiate(TaskHandler::class),
            $instantiate(ScheduleHandler::class),
            $instantiate(BudgetHandler::class),
            $instantiate(ProjectHandler::class),
            $instantiate(RoleHandler::class),
            $instantiate(GroupHandler::class),
            $instantiate(ProfileHandler::class),
            $instantiate(ToolkitVisibilityHandler::class),
            $instantiate(ConfigHandler::class),
            $instantiate(ThinkingHandler::class),
            $instantiate(ConversationHandler::class),
            $instantiate(LoopHandler::class),
            $instantiate(BackstoryHandler::class),
            $instantiate(AgentRunner::class),
            $instantiate(PromptInspectionService::class),
            $output,
            $workspace,
            createSlashRouterImagePreviewService($workspace),
            static function (): void {},
            static function (?bool $enable = null): void {},
            [],
        );

        $method = new ReflectionMethod($router, 'renderMarkdown');
        $method->setAccessible(true);

        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $method->invoke($router, $io, '![Prompt Image](images/example.png)');

        $display = $output->fetch();
        $plain = preg_replace('/\e\[[\d;]*m/', '', $display) ?? $display;

        expect($plain)->toContain('[image preview: Prompt Image]');
        expect($plain)->toContain('PREVIEW:example.png:40');
    } finally {
        cleanupTestTree($workspace);
    }
});