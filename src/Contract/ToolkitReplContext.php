<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use CoquiBot\Coqui\Observer\AnimatedTickCallback;
use CoquiBot\Coqui\Repl\InterruptiblePrompt;
use CoquiBot\Coqui\Support\ToolkitDatabaseFactory;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Services object passed to toolkit REPL command handlers.
 *
 * Provides access to terminal I/O, interactive prompts, workspace context,
     * fullscreen screen hosting, and factories for spinners and databases. This is the single service
 * entry point — toolkits do not need to import or depend on internal
 * Coqui classes beyond this contract.
 */
final readonly class ToolkitReplContext
{
    public function __construct(
        /** Styled terminal output for tables, formatting, and rendering. */
        public SymfonyStyle $io,

        /** Interactive prompt with ESC cancellation support. */
        public InterruptiblePrompt $prompt,

        /** Absolute path to the active workspace directory. */
        public string $workspacePath,

        /** Currently active personality profile name (null if none). */
        public ?string $activeProfile,

        /** Current session identifier. */
        public string $sessionId,

        /** Raw output interface for low-level terminal writes. */
        private OutputInterface $output,

        /** Database factory for toolkit-owned SQLite databases. */
        private ToolkitDatabaseFactory $databaseFactory,

        /** Optional fullscreen host for toolkit-owned interactive screens. */
        private ?ToolkitScreenHostInterface $screenHost = null,
    ) {}

    /**
     * Create a new animated spinner for long-running operations.
     *
     * Call start() to begin, stop() when done. The spinner writes to
     * the terminal status line and automatically clears itself.
     *
     * Usage:
     *   $spinner = $context->createSpinner('generating image');
     *   $spinner->start('generating image');
     *   // ... do work ...
     *   $spinner->stop();
     */
    public function createSpinner(string $context = ''): AnimatedTickCallback
    {
        $spinner = new AnimatedTickCallback($this->output);
        if ($context !== '') {
            $spinner->setContext($context);
        }

        return $spinner;
    }

    /**
     * Open (or create) a toolkit-owned SQLite database.
     *
     * Databases are stored at `{workspacePath}/{name}.db` with WAL mode.
     * Toolkit databases are independent from the core Coqui database.
     *
     * @param string $name Database name (alphanumeric, hyphens, underscores)
     */
    public function openDatabase(string $name): \PDO
    {
        return $this->databaseFactory->open($name);
    }

    /**
     * Returns true when the active REPL terminal can host a fullscreen toolkit screen.
     */
    public function isInteractiveTerminal(): bool
    {
        return $this->screenHost?->isInteractiveTerminal() ?? false;
    }

    /**
     * Run a toolkit-owned fullscreen screen in the current REPL terminal.
     */
    public function runScreen(ToolkitScreenInterface $screen): void
    {
        $this->screenHost?->runScreen($screen);
    }
}
