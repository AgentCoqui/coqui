<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Toolkit\FileSystemToolkit;
use CoquiBot\Coqui\Toolkit\ShellToolkit;
use CoquiBot\Coqui\Toolkit\WebToolkit;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ScriptSanitizer;
use CoquiBot\Coqui\Toolkit\MemoryToolkit;
use CoquiBot\Coqui\Toolkit\CoquiSourceToolkit;
use CoquiBot\Coqui\Tool\PhpExecuteTool;

/**
 * Lightweight tool resolver and executor for background tool tasks.
 *
 * Builds the same toolkits as OrchestratorAgent but without creating
 * an agent or involving an LLM. Finds a tool by name from all registered
 * toolkits (core + discovered packages) and calls execute() directly.
 *
 * Used by TaskRunCommand when a task record has tool_name set.
 */
final class BackgroundToolExecutor
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function __construct(
        private readonly BootManager $boot,
        private readonly string $projectRoot,
        private readonly bool $unsafeMode = false,
    ) {
        $this->buildToolIndex();
    }

    /**
     * Execute a tool by name with the given arguments.
     *
     * Returns the ToolResult from the tool's execute() method, or an
     * error result if the tool is not found.
     *
     * @param array<string, mixed> $arguments
     */
    public function execute(string $toolName, array $arguments): ToolResult
    {
        $tool = $this->tools[$toolName] ?? null;

        if ($tool === null) {
            $available = array_keys($this->tools);
            sort($available);
            $suggestion = $this->findClosestMatch($toolName, $available);
            $message = sprintf('Tool "%s" not found.', $toolName);

            if ($suggestion !== null) {
                $message .= sprintf(' Did you mean "%s"?', $suggestion);
            }

            return ToolResult::error($message);
        }

        return $tool->execute($arguments);
    }

    /**
     * Check whether a tool exists in the index.
     */
    public function hasTool(string $toolName): bool
    {
        return isset($this->tools[$toolName]);
    }

    /**
     * Build the tool index from all available toolkits.
     *
     * Mirrors OrchestratorAgent's toolkit construction but collects
     * tools into a flat name-indexed map instead of registering them
     * on an agent.
     */
    private function buildToolIndex(): void
    {
        $config = $this->boot->config();
        $workspacePath = $this->boot->workspacePath();

        // Filesystem toolkit — sandboxed to workspace
        $this->registerToolkit(new FileSystemToolkit(
            workspacePath: $workspacePath,
            allowedPaths: $this->boot->mountManager()->allowedPaths(),
        ));

        // Shell toolkit — runs in project root.
        // In unsafe mode, bypass all command restrictions.
        $shellAllowed = $this->unsafeMode ? [] : $this->resolveShellAllowedCommands($config);
        $shellDenied = $this->unsafeMode ? [] : $this->resolveShellDeniedCommands($config);
        $this->registerToolkit(new ShellToolkit(
            workDir: $this->projectRoot,
            allowedCommands: $shellAllowed,
            deniedCommands: $shellDenied,
            timeout: 60,
            unsafe: $this->unsafeMode,
        ));

        // Web toolkit — HTTP requests with SSRF protection
        $this->registerToolkit(new WebToolkit());

        // Memory toolkit
        $memoryStore = $this->boot->memoryStore();
        $this->registerToolkit(new MemoryToolkit($memoryStore));

        // Project source toolkit
        $this->registerToolkit(new CoquiSourceToolkit(projectRoot: $this->projectRoot));

        // PHP execution tool
        $sanitizer = new ScriptSanitizer(
            unsafe: $this->unsafeMode,
            blacklist: $this->boot->blacklist(),
        );
        $phpTool = new PhpExecuteTool(
            projectRoot: $this->projectRoot,
            workspacePath: $workspacePath,
            sanitizer: $sanitizer,
            mountManager: $this->boot->mountManager(),
        );
        $this->tools[$phpTool->name()] = $phpTool;

        // Auto-discovered toolkits from installed packages
        foreach ($this->boot->discovery()->instantiateRegistered() as $toolkit) {
            $this->registerToolkit($toolkit);
        }
    }

    /**
     * Register all tools from a toolkit into the index.
     */
    private function registerToolkit(\CarmeloSantana\PHPAgents\Contract\ToolkitInterface $toolkit): void
    {
        foreach ($toolkit->tools() as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * Resolve shell allowed commands from config.
     *
     * Returns empty array (all commands allowed) unless explicitly configured.
     *
     * @return string[]
     */
    private function resolveShellAllowedCommands(ConfigInterface $config): array
    {
        $configured = $config->get('agents.defaults.shellAllowedCommands');

        if (is_array($configured) && !empty($configured)) {
            return array_values(array_filter($configured, 'is_string'));
        }

        return [];
    }

    /**
     * Resolve shell denied commands from config.
     *
     * @return string[]
     */
    private function resolveShellDeniedCommands(ConfigInterface $config): array
    {
        $allowSudo = filter_var(
            $config->get('agents.defaults.allowSudo', false),
            FILTER_VALIDATE_BOOLEAN,
        );

        return $allowSudo ? [] : ['sudo'];
    }

    /**
     * Find the closest matching tool name using Levenshtein distance.
     *
     * @param string[] $candidates
     */
    private function findClosestMatch(string $input, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($input, $candidate);

            if ($distance < $bestDistance && $distance <= 5) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}
