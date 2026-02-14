<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Manages a separate Composer project inside the workspace directory.
 *
 * The workspace has its own composer.json and vendor/ so the bot can
 * self-install packages without touching the user's main project.
 * This provides full autonomy for extending capabilities at runtime.
 */
final class WorkspaceComposerManager
{
    private readonly string $composerJsonPath;

    public function __construct(
        private readonly string $workspacePath,
    ) {
        $this->composerJsonPath = rtrim($this->workspacePath, '/') . '/composer.json';
    }

    /**
     * Initialize the workspace Composer project if it doesn't exist.
     *
     * Creates a minimal composer.json with autoloading configured.
     */
    public function initialize(): void
    {
        if (file_exists($this->composerJsonPath)) {
            return;
        }

        $scaffold = [
            'name' => 'coqui/workspace',
            'description' => 'Coqui workspace — bot-managed dependencies',
            'type' => 'project',
            'license' => 'MIT',
            'require' => new \stdClass(),
            'autoload' => [
                'psr-4' => [
                    'CoquiWorkspace\\' => 'src/',
                ],
            ],
            'config' => [
                'optimize-autoloader' => true,
                'sort-packages' => true,
                'allow-plugins' => [
                    'pestphp/pest-plugin' => true,
                ],
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];

        $dir = dirname($this->composerJsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Create src/ directory for potential workspace code
        $srcDir = $dir . '/src';
        if (!is_dir($srcDir)) {
            mkdir($srcDir, 0755, true);
            file_put_contents($srcDir . '/.gitkeep', '');
        }

        file_put_contents(
            $this->composerJsonPath,
            json_encode($scaffold, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /**
     * Load the workspace autoloader if it exists.
     *
     * Returns true if the autoloader was loaded, false if not available.
     */
    public function loadAutoloader(): bool
    {
        $autoloader = rtrim($this->workspacePath, '/') . '/vendor/autoload.php';

        if (!file_exists($autoloader)) {
            return false;
        }

        require_once $autoloader;

        return true;
    }

    /**
     * Check whether the workspace has a Composer project initialized.
     */
    public function isInitialized(): bool
    {
        return file_exists($this->composerJsonPath);
    }

    /**
     * Get the path to the workspace's composer.json.
     */
    public function composerJsonPath(): string
    {
        return $this->composerJsonPath;
    }

    /**
     * Get the workspace root path (where composer.json lives).
     */
    public function workspacePath(): string
    {
        return $this->workspacePath;
    }
}
