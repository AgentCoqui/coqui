<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

/**
 * Discovers and registers ToolkitInterface implementations from installed composer packages.
 *
 * After a package is installed via ComposerTool, this class scans the package's
 * autoloaded namespace for classes implementing ToolkitInterface. Discovered
 * toolkits are persisted in a registry file (toolkits.json) so they survive
 * across sessions and can be auto-loaded by OrchestratorAgent on startup.
 */
final class ToolkitDiscovery
{
    private string $registryPath;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $workspacePath,
    ) {
        $this->registryPath = rtrim($this->workspacePath, '/') . '/toolkits.json';
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
     * Attempts no-arg construction first, then tries passing workspacePath.
     * Silently skips classes that cannot be instantiated.
     *
     * @return ToolkitInterface[]
     */
    public function instantiateRegistered(): array
    {
        $registry = $this->loadRegistry();
        $toolkits = [];

        foreach ($registry as $classes) {
            foreach ($classes as $className) {
                $toolkit = $this->tryInstantiate($className);
                if ($toolkit !== null) {
                    $toolkits[] = $toolkit;
                }
            }
        }

        return $toolkits;
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
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * Attempt to instantiate a toolkit class.
     *
     * Tries strategies in order:
     * 1. Static factory method fromEnv() — toolkit reads config from environment
     * 2. No constructor / all-optional params — no-arg construction
     * 3. First required param is string — pass workspacePath
     */
    private function tryInstantiate(string $className): ?ToolkitInterface
    {
        if (!class_exists($className, true)) {
            return null;
        }

        try {
            /** @var \ReflectionClass<ToolkitInterface> $reflection */
            $reflection = new \ReflectionClass($className);

            if (!$reflection->implementsInterface(ToolkitInterface::class)
                || $reflection->isAbstract()
                || $reflection->isInterface()) {
                return null;
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
        } catch (\Throwable) {
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
}
