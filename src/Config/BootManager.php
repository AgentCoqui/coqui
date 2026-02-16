<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Config\OpenClawConfig;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles the Coqui boot sequence: config loading, workspace initialization,
 * credential resolution, and toolkit discovery.
 *
 * Extracted from RunCommand to enforce single-responsibility.
 */
final class BootManager
{
    private OpenClawConfig $config;
    private string $workspacePath;
    private CredentialResolver $credentialResolver;
    private ToolkitDiscovery $discovery;
    private SkillDiscovery $skillDiscovery;
    private RoleResolver $roleResolver;
    private CatastrophicBlacklist $blacklist;
    private DefaultsLoader $defaultsLoader;

    public function __construct(
        private readonly string $workDir,
    ) {
        $this->defaultsLoader = new DefaultsLoader();
    }

    /**
     * Run the full boot sequence.
     *
     * @param OutputInterface|SymfonyStyle|null $io  Pass SymfonyStyle for interactive mode,
     *                                               OutputInterface for verbose logging,
     *                                               or null for headless/API mode.
     * @return bool True if boot succeeded, false if it should abort.
     */
    public function boot(OutputInterface|SymfonyStyle|null $io = null, ?string $configPath = null): bool
    {
        $this->loadConfig($io, $configPath);
        $this->blacklist = CatastrophicBlacklist::fromConfig($this->config);
        $this->roleResolver = new RoleResolver($this->config, $this->defaultsLoader);
        $this->initializeWorkspace();
        $this->initializeCredentials();
        $this->discoverSkills();
        $this->discoverToolkits($io);

        return true;
    }

    public function config(): OpenClawConfig
    {
        return $this->config;
    }

    public function workspacePath(): string
    {
        return $this->workspacePath;
    }

    public function credentialResolver(): CredentialResolver
    {
        return $this->credentialResolver;
    }

    public function discovery(): ToolkitDiscovery
    {
        return $this->discovery;
    }

    public function roleResolver(): RoleResolver
    {
        return $this->roleResolver;
    }

    public function blacklist(): CatastrophicBlacklist
    {
        return $this->blacklist;
    }

    public function defaultsLoader(): DefaultsLoader
    {
        return $this->defaultsLoader;
    }

    public function skillDiscovery(): SkillDiscovery
    {
        return $this->skillDiscovery;
    }

    /**
     * Reload config after setup wizard — updates resolver and workspace.
     */
    public function reloadConfig(string $configPath): void
    {
        $this->config = OpenClawConfig::fromFile($configPath);
        $this->roleResolver = new RoleResolver($this->config, $this->defaultsLoader);

        $workspaceResolver = new WorkspaceResolver($this->config, $this->workDir);
        $this->workspacePath = $workspaceResolver->resolve();
    }

    private function loadConfig(OutputInterface|SymfonyStyle|null $io, ?string $configPath): void
    {
        $configPath ??= $this->workDir . '/openclaw.json';

        if (!file_exists($configPath)) {
            $configPath = dirname(__DIR__, 2) . '/openclaw.json';
        }

        if (file_exists($configPath)) {
            $this->config = OpenClawConfig::fromFile($configPath);
            return;
        }

        // Interactive setup wizard — only available with SymfonyStyle
        if ($io instanceof SymfonyStyle) {
            $io->warning('No openclaw.json configuration found.');
            $io->text([
                'Coqui needs an openclaw.json file to know which AI providers and models to use.',
                'Without it, you may see connection errors like "404 Not Found".',
                '',
            ]);

            if ($io->confirm('Would you like to run the setup wizard now?', true)) {
                $outputPath = $this->workDir . '/openclaw.json';
                $wizard = new SetupWizard($io, $this->defaultsLoader);
                $saved = $wizard->runAndSave($outputPath);

                if ($saved && file_exists($outputPath)) {
                    $this->config = OpenClawConfig::fromFile($outputPath);
                    return;
                }
            }

            $defaultModel = $this->defaultsLoader->defaultModel();
            $io->text("<fg=gray>Using defaults (model: {$defaultModel}). Run <fg=cyan>coqui setup</> to configure.</>");
        }

        $this->config = $this->buildDefaultConfig();
    }

    private function initializeWorkspace(): void
    {
        $workspaceResolver = new WorkspaceResolver($this->config, $this->workDir);
        $this->workspacePath = $workspaceResolver->resolve();

        $workspaceComposer = new WorkspaceComposerManager($this->workspacePath);
        $workspaceComposer->initialize();
        $workspaceComposer->loadAutoloader();
    }

    private function initializeCredentials(): void
    {
        $this->credentialResolver = new CredentialResolver(workspacePath: $this->workspacePath);
        $this->credentialResolver->loadIntoProcessEnv();
    }

    private function discoverSkills(): void
    {
        $this->skillDiscovery = new SkillDiscovery($this->workspacePath);
        $this->skillDiscovery->ensureSkillsDir();
    }

    private function discoverToolkits(OutputInterface|SymfonyStyle|null $io): void
    {
        $this->discovery = new ToolkitDiscovery($this->workDir, $this->workspacePath, $this->credentialResolver);
        $newToolkits = $this->discovery->discoverAll();

        if (!empty($newToolkits) && $io !== null && $io->isVerbose()) {
            $io->writeln('Discovered new toolkits: ' . implode(', ', $newToolkits));
        }
    }

    private function buildDefaultConfig(): OpenClawConfig
    {
        $defaultModel = $this->defaultsLoader->defaultModel();

        return OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => $defaultModel],
                    'roles' => ['orchestrator' => $defaultModel],
                ],
            ],
        ]);
    }
}
