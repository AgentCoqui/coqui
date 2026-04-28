<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;

use CarmeloSantana\PHPAgents\Contract\PackageEventListenerInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Contract\CredentialRequirement;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Contract\ReplCommandProvider;
use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use CoquiBot\Coqui\Repl\ToolkitCommandCandidate;
use CoquiBot\Coqui\Tool\CredentialGuardToolkit;

/**
 * Discovers and registers ToolkitInterface implementations from installed composer packages.
 *
 * After a Composer package is installed, this class scans the package's
 * autoloaded namespace for classes implementing ToolkitInterface. Discovered
 * toolkits are persisted in a registry file (toolkits.json) so they survive
 * across sessions and can be auto-loaded by OrchestratorAgent on startup.
 */
final class ToolkitDiscovery implements PackageEventListenerInterface
{
    private string $registryPath;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly ?CredentialResolverInterface $credentialResolver = null,
        private readonly ?ToolkitVisibilityRegistry $visibilityRegistry = null,
    ) {
        $this->registryPath = PathHelper::trimTrailingSlash($this->workspacePath) . '/toolkits.json';
    }

    /**
     * Scan a newly installed package for ToolkitInterface implementations.
     *
     * Checks two sources:
     * 1. The package's composer.json extra.php-agents.toolkits (explicit declaration)
     * 2. Filesystem scanning of PSR-4 autoloaded namespaces (fallback discovery)
     *
     * @return string[] Fully-qualified class names of discovered toolkits
     */
    public function discover(string $packageName): array
    {
        // First: check explicit declarations in composer.json extra
        $declared = $this->findDeclaredToolkits($packageName);
        if (!empty($declared)) {
            // Verify each declared class actually implements ToolkitInterface
            $validated = array_filter($declared, fn(string $class) => $this->isToolkit($class));

            if (!empty($validated)) {
                $this->register($packageName, $validated);
                return $validated;
            }
        }

        // Fallback: scan the package's autoloaded namespaces
        $installedPath = $this->projectRoot . '/vendor/composer/installed.json';

        if (!file_exists($installedPath)) {
            return [];
        }

        $installedData = json_decode((string) file_get_contents($installedPath), true);
        if (!is_array($installedData)) {
            return [];
        }

        // Composer 2.x wraps packages in a 'packages' key
        $packages = $installedData['packages'] ?? $installedData;
        if (!is_array($packages)) {
            return [];
        }

        $autoloadMap = $this->findPackageAutoload($packages, $packageName);
        if (empty($autoloadMap)) {
            return [];
        }

        $discovered = [];

        foreach ($autoloadMap as $namespace => $directory) {
            $fullDir = $this->projectRoot . '/vendor/' . $this->normalizeVendorPath($packageName) . '/' . $directory;

            if (!is_dir($fullDir)) {
                continue;
            }

            $phpFiles = $this->findPhpFiles($fullDir);

            foreach ($phpFiles as $file) {
                $className = $this->resolveClassName($file, $fullDir, $namespace);

                if ($className === null) {
                    continue;
                }

                if ($this->isToolkit($className)) {
                    $discovered[] = $className;
                }
            }
        }

        // Persist to registry
        if (!empty($discovered)) {
            $this->register($packageName, $discovered);
        }

        return $discovered;
    }

    /**
     * Scan ALL installed Composer packages for toolkit declarations.
     *
     * Reads vendor/composer/installed.json and checks each package for
     * extra.php-agents.toolkits. Compares against the persisted registry
     * and updates it with any newly discovered or removed packages.
     *
     * Called at boot to ensure the registry is always in sync with
     * actually installed packages — no manual setup needed.
     *
     * @return string[] Newly discovered toolkit class names (not previously registered)
     */
    public function discoverAll(): array
    {
        $installedPath = $this->projectRoot . '/vendor/composer/installed.json';

        if (!file_exists($installedPath)) {
            return [];
        }

        $installedData = json_decode((string) file_get_contents($installedPath), true);
        if (!is_array($installedData)) {
            return [];
        }

        $packages = $installedData['packages'] ?? $installedData;
        if (!is_array($packages)) {
            return [];
        }

        $currentRegistry = $this->loadRegistry();
        $discoveredRegistry = [];
        $newlyDiscovered = [];

        foreach ($packages as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }

            $packageName = $pkg['name'] ?? '';
            if ($packageName === '') {
                continue;
            }

            // Check for explicit toolkit declarations in the package's extra key
            $toolkitClasses = $this->findDeclaredToolkitsFromInstalled($pkg);

            if (empty($toolkitClasses)) {
                // Fallback: check the vendor-local composer.json
                $toolkitClasses = $this->findDeclaredToolkits($packageName);
            }

            if (empty($toolkitClasses)) {
                continue;
            }

            // Validate each declared class implements ToolkitInterface
            $validated = array_filter($toolkitClasses, fn(string $class) => $this->isToolkit($class));

            if (empty($validated)) {
                continue;
            }

            $discoveredRegistry[$packageName] = array_values($validated);

            // Track what's new compared to the current registry
            if (!isset($currentRegistry[$packageName])) {
                foreach ($validated as $className) {
                    $newlyDiscovered[] = $className;
                }
            }
        }

        // Also scan workspace packages if the workspace has its own vendor
        $workspaceInstalledPath = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/composer/installed.json';
        if (file_exists($workspaceInstalledPath)) {
            $workspaceData = json_decode((string) file_get_contents($workspaceInstalledPath), true);
            if (is_array($workspaceData)) {
                $workspacePackages = $workspaceData['packages'] ?? $workspaceData;
                if (is_array($workspacePackages)) {
                    foreach ($workspacePackages as $pkg) {
                        if (!is_array($pkg)) {
                            continue;
                        }

                        $packageName = $pkg['name'] ?? '';
                        if ($packageName === '' || isset($discoveredRegistry[$packageName])) {
                            continue;
                        }

                        $toolkitClasses = $this->findDeclaredToolkitsFromInstalled($pkg);

                        if (empty($toolkitClasses)) {
                            $vendorComposer = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/' . $packageName . '/composer.json';
                            if (file_exists($vendorComposer)) {
                                $data = json_decode((string) file_get_contents($vendorComposer), true);
                                $toolkitClasses = is_array($data) ? ($data['extra']['php-agents']['toolkits'] ?? []) : [];
                                if (!is_array($toolkitClasses)) {
                                    $toolkitClasses = [];
                                }
                            }
                        }

                        if (empty($toolkitClasses)) {
                            continue;
                        }

                        $validated = array_filter($toolkitClasses, fn(string $class) => $this->isToolkit($class));

                        if (!empty($validated)) {
                            $discoveredRegistry[$packageName] = array_values($validated);
                            if (!isset($currentRegistry[$packageName])) {
                                foreach ($validated as $className) {
                                    $newlyDiscovered[] = $className;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Persist the full updated registry
        $this->saveRegistry($discoveredRegistry);

        return $newlyDiscovered;
    }

    /**
     * Extract toolkit class declarations from an installed.json package entry.
     *
     * @param array<string, mixed> $packageEntry A single package entry from installed.json
     * @return string[]
     */
    private function findDeclaredToolkitsFromInstalled(array $packageEntry): array
    {
        $extra = $packageEntry['extra']['php-agents']['toolkits'] ?? null;

        return is_array($extra) ? $extra : [];
    }

    /**
     * Get all registered toolkit classes from the registry.
     *
     * @return array<string, string[]> Package name => array of class names
     */
    public function loadRegistry(): array
    {
        if (!file_exists($this->registryPath)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->registryPath), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Instantiate all registered toolkits that can be constructed.
     *
     * Skips disabled packages (when a ToolkitVisibilityRegistry is provided).
     * Attempts no-arg construction first, then tries passing workspacePath.
     * Silently skips classes that cannot be instantiated.
     * Wraps toolkits in CredentialGuardToolkit when credential requirements are declared.
     *
     * @param array<string, mixed> $context
     * @return ToolkitInterface[]
     */
    public function instantiateRegistered(array $context = []): array
    {
        return array_column($this->instantiateRegisteredGrouped(context: $context), 'toolkit');
    }

    /**
     * Instantiate all registered toolkits and return them with their package names.
     *
     * Skips packages whose visibility is Disabled.
     * Returns an array of ['package' => string, 'toolkit' => ToolkitInterface].
     *
     * @param bool $childMode When true, wraps credential guards with child-aware error messages
     * @param array<string, mixed> $context Additional runtime context for toolkits that support context-aware factories
     * @return array<int, array{package: string, toolkit: ToolkitInterface}>
     */
    public function instantiateRegisteredGrouped(bool $childMode = false, array $context = []): array
    {
        $registry = $this->loadRegistry();
        $result = [];

        foreach ($registry as $packageName => $classes) {
            // Skip disabled packages entirely
            if ($this->visibilityRegistry !== null) {
                $vis = $this->visibilityRegistry->getPackageVisibility($packageName);
                if ($vis === ToolkitVisibility::Disabled) {
                    continue;
                }
            }

            $requirements = $this->loadCredentialRequirements($packageName);

            foreach ($classes as $className) {
                $toolkit = $this->tryInstantiate($className, [
                    ...$context,
                    'workspacePath' => $this->workspacePath,
                    'childMode' => $childMode,
                    'packageName' => $packageName,
                ]);
                if ($toolkit === null) {
                    continue;
                }

                // Wrap with credential guard if the package declares requirements
                if (!empty($requirements) && $this->credentialResolver !== null) {
                    $toolkit = new CredentialGuardToolkit(
                        inner: $toolkit,
                        requirements: $requirements,
                        resolver: $this->credentialResolver,
                        childMode: $childMode,
                    );
                }

                $result[] = ['package' => $packageName, 'toolkit' => $toolkit];
            }
        }

        return $result;
    }

    /**
     * Return all registered packages with their current visibility.
     *
     * Used by the toolkit management API endpoint.
     *
     * @return array<int, array{package: string, classes: string[], visibility: string}>
     */
    public function allWithVisibility(): array
    {
        $registry = $this->loadRegistry();
        $result = [];

        foreach ($registry as $packageName => $classes) {
            $vis = $this->visibilityRegistry?->getPackageVisibility($packageName)
                ?? ToolkitVisibility::Enabled;
            $result[] = [
                'package'    => $packageName,
                'classes'    => $classes,
                'visibility' => $vis->value,
            ];
        }

        return $result;
    }

    /**
     * Register discovered toolkits for a package.
     *
     * @param string[] $classNames
     */
    public function register(string $packageName, array $classNames): void
    {
        $registry = $this->loadRegistry();
        $registry[$packageName] = $classNames;
        $this->saveRegistry($registry);
    }

    /**
     * Remove a package's toolkits from the registry.
     */
    public function unregister(string $packageName): void
    {
        $registry = $this->loadRegistry();
        unset($registry[$packageName]);
        $this->saveRegistry($registry);
    }

    #[\Override]
    public function onPackageInstalled(string $packageName): void
    {
        $this->discover($packageName);
    }

    #[\Override]
    public function onPackageRemoved(string $packageName): void
    {
        $this->unregister($packageName);
    }

    /**
     * Check a package's composer.json for explicitly declared toolkits.
     *
     * Looks for: extra.php-agents.toolkits => ["Vendor\\Toolkit\\MyToolkit"]
     *
     * @return string[]
     */
    private function findDeclaredToolkits(string $packageName): array
    {
        $composerJson = $this->projectRoot . '/vendor/' . $packageName . '/composer.json';

        if (!file_exists($composerJson)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($composerJson), true);
        if (!is_array($data)) {
            return [];
        }

        $toolkits = $data['extra']['php-agents']['toolkits'] ?? null;

        return is_array($toolkits) ? $toolkits : [];
    }

    /**
     * Find PSR-4 autoload mappings for a specific package.
     *
     * @param array<int, mixed> $packages
     * @return array<string, string> namespace => directory
     */
    private function findPackageAutoload(array $packages, string $packageName): array
    {
        foreach ($packages as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }

            $name = $pkg['name'] ?? '';
            if ($name !== $packageName) {
                continue;
            }

            $autoload = $pkg['autoload']['psr-4'] ?? [];

            return is_array($autoload) ? $autoload : [];
        }

        return [];
    }

    /**
     * Normalize a package name to its vendor directory path.
     */
    private function normalizeVendorPath(string $packageName): string
    {
        // vendor/package maps to vendor/vendor/package/
        return $packageName;
    }

    /**
     * Recursively find all .php files in a directory.
     *
     * @return string[]
     */
    private function findPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Resolve a PHP file to its fully-qualified class name.
     */
    private function resolveClassName(string $filePath, string $baseDir, string $namespace): ?string
    {
        $relativePath = str_replace($baseDir, '', $filePath);
        $relativePath = ltrim($relativePath, '/\\');

        // Remove .php extension and convert directory separators to namespace separators
        $classPart = str_replace(['/', '\\'], '\\', substr($relativePath, 0, -4));

        $fqcn = rtrim($namespace, '\\') . '\\' . $classPart;

        // Verify the class can be loaded
        if (!class_exists($fqcn, true)) {
            return null;
        }

        return $fqcn;
    }

    /**
     * Check if a class implements ToolkitInterface and is instantiable.
     */
    private function isToolkit(string $className): bool
    {
        try {
            if (!class_exists($className, true) && !interface_exists($className, true)) {
                return false;
            }

            /** @var class-string $className */
            $reflection = new \ReflectionClass($className);

            return $reflection->implementsInterface(ToolkitInterface::class)
                && !$reflection->isAbstract()
                && !$reflection->isInterface();
        } catch (\Throwable) {
            // Catches \ParseError (broken vendor files), \ReflectionException, and any other failure.
            return false;
        }
    }

    /**
        * @param array<string, mixed> $context
     * Attempt to instantiate a toolkit class.
     *
     * Tries strategies in order:
     * 0. Static factory method fromCoquiContext(array $context)
     * 1. Static factory method fromEnv() — toolkit reads config from environment
     * 2. No constructor / all-optional params — no-arg construction
     * 3. First required param is string — pass workspacePath
     */
    private function tryInstantiate(string $className, array $context = []): ?ToolkitInterface
    {
        try {
            if (!class_exists($className, true)) {
                return null;
            }
            /** @var \ReflectionClass<ToolkitInterface> $reflection */
            $reflection = new \ReflectionClass($className);

            if (!$reflection->implementsInterface(ToolkitInterface::class)
                || $reflection->isAbstract()
                || $reflection->isInterface()) {
                return null;
            }

            // Strategy 0: static fromCoquiContext(array $context) factory method
            if ($reflection->hasMethod('fromCoquiContext')) {
                $factory = $reflection->getMethod('fromCoquiContext');
                if ($factory->isStatic() && $factory->isPublic()
                    && $factory->getNumberOfRequiredParameters() <= 1) {
                    $instance = $factory->invoke(null, $context);
                    if ($instance instanceof ToolkitInterface) {
                        return $instance;
                    }
                }
            }

            // Strategy 1: static fromEnv() factory method
            if ($reflection->hasMethod('fromEnv')) {
                $factory = $reflection->getMethod('fromEnv');
                if ($factory->isStatic() && $factory->isPublic()
                    && $factory->getNumberOfRequiredParameters() === 0) {
                    $instance = $factory->invoke(null);
                    if ($instance instanceof ToolkitInterface) {
                        return $instance;
                    }
                }
            }

            $constructor = $reflection->getConstructor();

            // Strategy 2: no constructor or all parameters optional
            if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                /** @var ToolkitInterface */
                return $reflection->newInstance();
            }

            // Strategy 3: first required param is string — pass workspacePath
            $params = $constructor->getParameters();
            $firstParam = $params[0] ?? null;

            if ($firstParam !== null) {
                $type = $firstParam->getType();
                if ($type instanceof \ReflectionNamedType && $type->getName() === 'string') {
                    /** @var ToolkitInterface */
                    return $reflection->newInstance($this->workspacePath);
                }
            }

            return null;
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[ToolkitDiscovery] Failed to instantiate %s: %s in %s:%d',
                $className,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
            return null;
        }
    }

    /**
     * @param array<string, string[]> $registry
     */
    private function saveRegistry(array $registry): void
    {
        $dir = dirname($this->registryPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->registryPath,
            json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /**
     * Load credential requirements declared in a package's composer.json.
     *
     * Reads extra.php-agents.credentials from the package's composer.json:
     * ```json
     * {
     *   "extra": {
     *     "php-agents": {
     *       "credentials": {
     *         "BRAVE_SEARCH_API_KEY": "Brave Search API key — free at https://brave.com/search/api/"
     *       }
     *     }
     *   }
     * }
     * ```
     *
     * @return CredentialRequirement[]
     */
    public function loadCredentialRequirements(string $packageName): array
    {
        $requirements = [];

        // Check project vendor first
        $composerJson = $this->projectRoot . '/vendor/' . $packageName . '/composer.json';

        if (!file_exists($composerJson)) {
            // Fallback: check workspace vendor
            $composerJson = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/' . $packageName . '/composer.json';
        }

        if (!file_exists($composerJson)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($composerJson), true);
        if (!is_array($data)) {
            return [];
        }

        $credentials = $data['extra']['php-agents']['credentials'] ?? null;
        if (!is_array($credentials)) {
            return [];
        }

        foreach ($credentials as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            // Support both string shorthand and object format:
            //   "KEY": "description"                         (required)
            //   "KEY": {"description": "...", "optional": true}  (optional)
            if (is_string($value)) {
                $requirements[] = new CredentialRequirement(
                    name: $name,
                    description: $value,
                );
            } elseif (is_array($value) && isset($value['description']) && is_string($value['description'])) {
                $requirements[] = new CredentialRequirement(
                    name: $name,
                    description: $value['description'],
                    optional: (bool) ($value['optional'] ?? false),
                );
            }
        }

        return $requirements;
    }

    /**
     * Load role-scope declaration from a package's composer.json.
     *
     * Reads extra.php-agents.role-scope — an array of role names that
     * this toolkit is designed for. When set, the toolkit is only
     * instantiated for the listed roles (unless overridden by the role's
     * toolkits frontmatter).
     *
     * @return string[]|null Role names, or null if unrestricted
     */
    public function loadRoleScope(string $packageName): ?array
    {
        $composerJson = $this->projectRoot . '/vendor/' . $packageName . '/composer.json';

        if (!file_exists($composerJson)) {
            $composerJson = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/' . $packageName . '/composer.json';
        }

        if (!file_exists($composerJson)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($composerJson), true);
        if (!is_array($data)) {
            return null;
        }

        $roleScope = $data['extra']['php-agents']['role-scope'] ?? null;
        if (!is_array($roleScope)) {
            return null;
        }

        $filtered = array_values(array_filter($roleScope, 'is_string'));

        return $filtered !== [] ? $filtered : null;
    }

    /**
     * Collect role-scope declarations from all registered packages.
     *
     * @return array<string, string[]> Package name => allowed role names
     */
    public function collectAllRoleScopes(): array
    {
        $registry = $this->loadRegistry();
        $scopes = [];

        foreach (array_keys($registry) as $packageName) {
            $scope = $this->loadRoleScope($packageName);
            if ($scope !== null) {
                $scopes[$packageName] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * Load gated tool declarations from a package's composer.json.
     *
     * Reads extra.php-agents.gated — a map of tool names to gating rules.
     * Each rule is one of:
     *   - `"*"` — gate all invocations of the tool
     *   - A string — gate when the `action`/`command` argument matches this value
     *   - An object `{"arg": value}` — gate when argument equals value (bool, string)
     *   - An object `{"arg": "*"}` — gate when argument is present and truthy
     *
     * Example composer.json:
     * ```json
     * {
     *   "extra": {
     *     "php-agents": {
     *       "gated": {
     *         "git_push": ["*"],
     *         "git_branch": ["delete"],
     *         "git_commit": [{"amend": true}]
     *       }
     *     }
     *   }
     * }
     * ```
     *
     * @return array<string, list<string|array<string, mixed>>> Tool name => gating rules
     */
    public function loadGatedTools(string $packageName): array
    {
        // Check project vendor first
        $composerJson = $this->projectRoot . '/vendor/' . $packageName . '/composer.json';

        if (!file_exists($composerJson)) {
            // Fallback: check workspace vendor
            $composerJson = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/' . $packageName . '/composer.json';
        }

        if (!file_exists($composerJson)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($composerJson), true);
        if (!is_array($data)) {
            return [];
        }

        $gated = $data['extra']['php-agents']['gated'] ?? null;
        if (!is_array($gated)) {
            return [];
        }

        $result = [];

        foreach ($gated as $toolName => $rules) {
            if (!is_string($toolName) || !is_array($rules)) {
                continue;
            }

            /** @var list<string|array<string, mixed>> $rules */
            $result[$toolName] = $rules;
        }

        return $result;
    }

    /**
     * Collect gated tool declarations from all registered packages.
     *
     * Iterates every registered toolkit package and merges their
     * extra.php-agents.gated declarations into a single map.
     * When multiple packages gate the same tool, their rules are merged.
     *
     * @return array<string, list<mixed>> Tool name => merged gating rules
     */
    public function collectAllGatedTools(): array
    {
        $registry = $this->loadRegistry();
        $merged = [];

        foreach (array_keys($registry) as $packageName) {
            $gated = $this->loadGatedTools($packageName);

            foreach ($gated as $toolName => $rules) {
                if (!isset($merged[$toolName])) {
                    $merged[$toolName] = $rules;
                } else {
                    // If either set contains a wildcard, keep the wildcard
                    if ($rules === ['*'] || $merged[$toolName] === ['*']) {
                        $merged[$toolName] = ['*'];
                    } else {
                        $merged[$toolName] = array_merge($merged[$toolName], $rules);
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * Collect all credential requirements declared across all registered packages.
     *
     * Iterates every registered package and merges their credential requirements
     * into a single map keyed by credential name. First-seen wins for duplicates.
     *
     * @return array<string, CredentialRequirement> Keyed by credential name
     */
    public function collectAllCredentialRequirements(): array
    {
        $registry = $this->loadRegistry();
        $merged = [];

        foreach (array_keys($registry) as $packageName) {
            $requirements = $this->loadCredentialRequirements($packageName);

            foreach ($requirements as $requirement) {
                // First-seen wins — don't overwrite if already registered
                if (!isset($merged[$requirement->name])) {
                    $merged[$requirement->name] = $requirement;
                }
            }
        }

        return $merged;
    }

    /**
     * Discover all package-bundled skill directories from registered packages.
     *
     * Reads extra.php-agents.skills from each package's composer.json. The value
     * is a relative path (e.g. "skills") pointing to a directory that contains
     * skill subdirectories with SKILL.md files.
     *
     * @return string[] Absolute paths to skill directories within packages
     */
    public function discoverPackageSkillPaths(): array
    {
        $registry = $this->loadRegistry();
        $paths = [];

        foreach (array_keys($registry) as $packageName) {
            $skillPath = $this->loadSkillPath($packageName);
            if ($skillPath !== null) {
                $paths[] = $skillPath;
            }
        }

        return $paths;
    }

    /**
     * Load the skill directory path declared in a package's composer.json.
     *
     * Reads extra.php-agents.skills — a string relative path to the directory
     * containing skill subdirectories within the package.
     */
    private function loadSkillPath(string $packageName): ?string
    {
        // Check project vendor first
        $composerJson = $this->projectRoot . '/vendor/' . $packageName . '/composer.json';
        $vendorRoot = $this->projectRoot . '/vendor/' . $packageName;

        if (!file_exists($composerJson)) {
            // Fallback: check workspace vendor
            $composerJson = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/' . $packageName . '/composer.json';
            $vendorRoot = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/' . $packageName;
        }

        if (!file_exists($composerJson)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($composerJson), true);
        if (!is_array($data)) {
            return null;
        }

        $skillsDir = $data['extra']['php-agents']['skills'] ?? null;
        if (!is_string($skillsDir) || $skillsDir === '') {
            return null;
        }

        $fullPath = $vendorRoot . '/' . ltrim($skillsDir, '/');

        return is_dir($fullPath) ? realpath($fullPath) ?: $fullPath : null;
    }

    /**
     * Discover role directory paths declared by registered toolkit packages.
     *
     * Reads extra.php-agents.roles from each registered package's composer.json.
     *
     * @return string[] Absolute paths to role directories.
     */
    public function discoverPackageRolePaths(): array
    {
        $registry = $this->loadRegistry();
        $paths = [];

        foreach (array_keys($registry) as $packageName) {
            $rolePath = $this->loadRolePath($packageName);
            if ($rolePath !== null) {
                $paths[] = $rolePath;
            }
        }

        return $paths;
    }

    /**
     * Discover loop definition directory paths declared by registered toolkit packages.
     *
     * Reads extra.php-agents.loops from each registered package's composer.json.
     *
     * @return string[] Absolute paths to loop definition directories.
     */
    public function discoverPackageLoopPaths(): array
    {
        $registry = $this->loadRegistry();
        $paths = [];

        foreach (array_keys($registry) as $packageName) {
            $loopPath = $this->loadLoopPath($packageName);
            if ($loopPath !== null) {
                $paths[] = $loopPath;
            }
        }

        return $paths;
    }

    /**
     * Load the role directory path declared in a package's composer.json.
     *
     * Reads extra.php-agents.roles — a string relative path to the directory
     * containing role .md files within the package.
     */
    private function loadRolePath(string $packageName): ?string
    {
        $composerJson = $this->projectRoot . '/vendor/' . $packageName . '/composer.json';
        $vendorRoot = $this->projectRoot . '/vendor/' . $packageName;

        if (!file_exists($composerJson)) {
            $composerJson = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/' . $packageName . '/composer.json';
            $vendorRoot = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/' . $packageName;
        }

        if (!file_exists($composerJson)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($composerJson), true);
        if (!is_array($data)) {
            return null;
        }

        $rolesDir = $data['extra']['php-agents']['roles'] ?? null;
        if (!is_string($rolesDir) || $rolesDir === '') {
            return null;
        }

        $fullPath = $vendorRoot . '/' . ltrim($rolesDir, '/');

        return is_dir($fullPath) ? realpath($fullPath) ?: $fullPath : null;
    }

    /**
     * Load the loop definition directory path declared in a package's composer.json.
     *
     * Reads extra.php-agents.loops — a string relative path to the directory
     * containing loop .json files within the package.
     */
    private function loadLoopPath(string $packageName): ?string
    {
        $composerJson = $this->projectRoot . '/vendor/' . $packageName . '/composer.json';
        $vendorRoot = $this->projectRoot . '/vendor/' . $packageName;

        if (!file_exists($composerJson)) {
            $composerJson = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/' . $packageName . '/composer.json';
            $vendorRoot = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/' . $packageName;
        }

        if (!file_exists($composerJson)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($composerJson), true);
        if (!is_array($data)) {
            return null;
        }

        $loopsDir = $data['extra']['php-agents']['loops'] ?? null;
        if (!is_string($loopsDir) || $loopsDir === '') {
            return null;
        }

        $fullPath = $vendorRoot . '/' . ltrim($loopsDir, '/');

        return is_dir($fullPath) ? realpath($fullPath) ?: $fullPath : null;
    }

    /**
     * Discover and return REPL command handlers from all enabled toolkits.
     *
     * Iterates over instantiated toolkits and collects handlers from those
     * implementing ReplCommandProvider. Only Enabled-visibility toolkits
     * contribute commands. Commands from CredentialGuardToolkit wrappers
     * are collected from the inner toolkit if it's a ReplCommandProvider.
     *
     * @param array<string, mixed> $context Runtime context passed to toolkit factories
     * @return list<ToolkitCommandHandler>
     */
    public function commandHandlers(array $context = []): array
    {
        return array_map(
            static fn(ToolkitCommandCandidate $candidate): ToolkitCommandHandler => $candidate->handler,
            $this->commandHandlerCandidates($context),
        );
    }

    /**
     * Discover and return package-aware REPL command registration candidates.
     *
     * @param array<string, mixed> $context Runtime context passed to toolkit factories
     * @return list<ToolkitCommandCandidate>
     */
    public function commandHandlerCandidates(array $context = []): array
    {
        $handlers = [];

        foreach ($this->instantiateRegisteredGrouped(context: $context) as $entry) {
            // Only enabled toolkits may register REPL commands
            if ($this->visibilityRegistry !== null) {
                $vis = $this->visibilityRegistry->getPackageVisibility($entry['package']);
                if ($vis !== ToolkitVisibility::Enabled) {
                    continue;
                }
            }

            $toolkit = $entry['toolkit'];

            // Unwrap credential guard to check the inner toolkit
            if ($toolkit instanceof CredentialGuardToolkit) {
                $toolkit = $toolkit->innerToolkit();
            }

            if ($toolkit instanceof ReplCommandProvider) {
                foreach ($toolkit->commandHandlers() as $handler) {
                    $handlers[] = new ToolkitCommandCandidate($entry['package'], $handler);
                }
            }
        }

        return $handlers;
    }
}
