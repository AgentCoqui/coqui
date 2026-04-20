<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Repl\ReplCommandCatalog;
use CoquiBot\Coqui\Repl\SlashCommandRouter;
use CoquiBot\Coqui\Repl\Handler\BackstoryHandler;
use CoquiBot\Coqui\Repl\Handler\BudgetHandler;
use CoquiBot\Coqui\Repl\Handler\ConfigHandler;
use CoquiBot\Coqui\Repl\Handler\ConversationHandler;
use CoquiBot\Coqui\Repl\Handler\EvaluationHandler;
use CoquiBot\Coqui\Repl\Handler\LoopHandler;
use CoquiBot\Coqui\Repl\Handler\ProfileHandler;
use CoquiBot\Coqui\Repl\Handler\ProjectHandler;
use CoquiBot\Coqui\Repl\Handler\QualityHandler;
use CoquiBot\Coqui\Repl\Handler\RoleHandler;
use CoquiBot\Coqui\Repl\Handler\ScheduleHandler;
use CoquiBot\Coqui\Repl\Handler\SessionHandler;
use CoquiBot\Coqui\Repl\Handler\SpaceHandler;
use CoquiBot\Coqui\Repl\Handler\TaskHandler;
use CoquiBot\Coqui\Repl\Handler\TodoHandler;
use CoquiBot\Coqui\Repl\Handler\ToolkitVisibilityHandler;
use CoquiBot\Coqui\Repl\Handler\WebhookHandler;
use CoquiBot\Coqui\Support\PromptInspectionService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createSlashCommandRouterForHelpTest(): SlashCommandRouter
{
    $instantiate = static function (string $class): object {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    };

    $output = new BufferedOutput();

    return new SlashCommandRouter(
        $instantiate(SessionHandler::class),
        $instantiate(TaskHandler::class),
        $instantiate(TodoHandler::class),
        $instantiate(ScheduleHandler::class),
        $instantiate(BudgetHandler::class),
        $instantiate(QualityHandler::class),
        $instantiate(ProjectHandler::class),
        $instantiate(RoleHandler::class),
        $instantiate(ProfileHandler::class),
        $instantiate(ToolkitVisibilityHandler::class),
        $instantiate(SpaceHandler::class),
        $instantiate(ConfigHandler::class),
        $instantiate(ConversationHandler::class),
        $instantiate(WebhookHandler::class),
        $instantiate(EvaluationHandler::class),
        $instantiate(LoopHandler::class),
        $instantiate(BackstoryHandler::class),
        $instantiate(AgentRunner::class),
        $instantiate(PromptInspectionService::class),
        $output,
        sys_get_temp_dir(),
        static function (): void {},
        static function (?bool $enable = null): void {},
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