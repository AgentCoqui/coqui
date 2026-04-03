<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\PackageEventListenerInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Tool\ComposerTool;

/**
 * System toolkit for workspace Composer package management.
 *
 * Always loaded — never budget-gated or deferred. Provides the single
 * `composer` tool with 12 subcommands for full workspace dependency
 * management.
 */
final class ComposerToolkit implements ToolkitInterface
{
    private readonly ComposerTool $tool;

    public function __construct(
        string $workspacePath,
        ?PackageEventListenerInterface $listener = null,
    ) {
        $this->tool = new ComposerTool($workspacePath, $listener);
    }

    public function tools(): array
    {
        return [$this->tool];
    }

    public function guidelines(): string
    {
        return <<<'GUIDE'
            ## Composer & Package Management

            Use the `composer` tool to manage workspace dependencies. The workspace has its own
            `composer.json` isolated from the host project — you can freely install, update, and
            remove packages without affecting the host.

            **Best practices:**
            - Always use `packagist` to search and evaluate packages before installing.
            - For local packages under development, use `repository_type: "path"` with the
              absolute path to the package directory and `version: "@dev"`.
            - Run `composer(action: "doctor")` when diagnosing dependency issues.
            - Run `composer(action: "validate")` after manual edits to composer.json.
            - The `add` action automatically backs up composer.json/lock before changes.
            - After installing a package with declared toolkits, call `restart_coqui` to
              activate the new toolkit.

            **Framework packages are blocked** — full frameworks (Laravel, Symfony framework
            bundle, Laminas, Yii, CakePHP, Slim) cannot be installed. Use individual
            Symfony/PSR components instead.
            GUIDE;
    }
}
