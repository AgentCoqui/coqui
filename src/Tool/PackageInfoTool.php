<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Introspects installed Composer packages on demand.
 *
 * Allows the agent to read a package's README, list its classes and methods
 * via reflection, or read specific source files. This lets the agent learn
 * how to use an SDK without us pre-injecting documentation into every prompt.
 */
final class PackageInfoTool implements ToolInterface
{
    private const MAX_README_BYTES = 8192;
    private const MAX_SOURCE_BYTES = 12288;

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function name(): string
    {
        return 'package_info';
    }

    public function description(): string
    {
        return <<<'DESC'
            Inspect an installed composer package to learn how to use it.
            
            Available actions:
            - readme: Read the package's README.md (truncated to ~8KB)
            - classes: List all classes in the package with their public method signatures
            - methods: Deep-inspect a single class — all public methods with parameter types, return types
            - source: Read a specific source file from the package
            
            Use this tool BEFORE writing code that uses an installed SDK, so you understand
            the API surface and correct usage patterns.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The inspection action to perform',
                values: ['readme', 'classes', 'methods', 'source'],
                required: true,
            ),
            new StringParameter(
                name: 'package',
                description: 'Package name (vendor/package). Required for all actions.',
                required: true,
            ),
            new StringParameter(
                name: 'class',
                description: 'Fully-qualified class name. Required for the methods action.',
                required: false,
            ),
            new StringParameter(
                name: 'file',
                description: 'Relative file path within the package. Required for the source action.',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';
        $package = $input['package'] ?? '';
        $class = $input['class'] ?? '';
        $file = $input['file'] ?? '';

        if ($package === '') {
            return ToolResult::error('Package name is required.');
        }

        $packageDir = $this->resolvePackageDir($package);
        if ($packageDir === null) {
            return ToolResult::error("Package '{$package}' not found in vendor directory.");
        }

        return match ($action) {
            'readme' => $this->readReadme($package, $packageDir),
            'classes' => $this->listClasses($package, $packageDir),
            'methods' => $this->inspectClass($class),
            'source' => $this->readSource($package, $packageDir, $file),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function readReadme(string $package, string $packageDir): ToolResult
    {
        $candidates = ['README.md', 'README.rst', 'README.txt', 'README', 'readme.md'];

        foreach ($candidates as $candidate) {
            $path = $packageDir . '/' . $candidate;
            if (file_exists($path)) {
                $content = file_get_contents($path);
                if ($content === false) {
                    continue;
                }

                if (strlen($content) > self::MAX_README_BYTES) {
                    $content = substr($content, 0, self::MAX_README_BYTES)
                        . "\n\n--- truncated (use `source` action to read specific files) ---";
                }

                return ToolResult::success("## README for {$package}\n\n{$content}");
            }
        }

        return ToolResult::error("No README found for package '{$package}'.");
    }

    private function listClasses(string $package, string $packageDir): ToolResult
    {
        $autoloadMap = $this->getPackageAutoload($package);

        if (empty($autoloadMap)) {
            return ToolResult::error("No PSR-4 autoload mapping found for '{$package}'.");
        }

        $output = "## Classes in {$package}\n\n";
        $classCount = 0;

        foreach ($autoloadMap as $namespace => $directory) {
            $fullDir = $packageDir . '/' . rtrim($directory, '/');

            if (!is_dir($fullDir)) {
                continue;
            }

            $phpFiles = $this->findPhpFiles($fullDir);

            foreach ($phpFiles as $filePath) {
                $className = $this->resolveClassName($filePath, $fullDir, $namespace);

                if ($className === null) {
                    continue;
                }

                if (!class_exists($className, true) && !interface_exists($className, true) && !enum_exists($className)) {
                    continue;
                }

                try {
                    $reflection = new \ReflectionClass($className);

                    // Skip internal/abstract unless it's an interface
                    $kind = match (true) {
                        $reflection->isInterface() => 'interface',
                        $reflection->isEnum() => 'enum',
                        $reflection->isTrait() => 'trait',
                        $reflection->isAbstract() => 'abstract class',
                        default => 'class',
                    };

                    $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
                    $ownMethods = array_filter(
                        $methods,
                        fn(\ReflectionMethod $m) => $m->getDeclaringClass()->getName() === $className,
                    );

                    $methodNames = array_map(
                        fn(\ReflectionMethod $m) => $m->getName() . '()',
                        $ownMethods,
                    );

                    $methodList = empty($methodNames)
                        ? ''
                        : ' — ' . implode(', ', array_slice($methodNames, 0, 8));

                    if (count($methodNames) > 8) {
                        $methodList .= ', ...';
                    }

                    $output .= "- **{$kind}** `{$className}`{$methodList}\n";
                    $classCount++;
                } catch (\ReflectionException) {
                    continue;
                }
            }
        }

        if ($classCount === 0) {
            return ToolResult::error("No classes found in '{$package}'.");
        }

        $output .= "\nTotal: {$classCount} types. Use `methods` action to inspect a specific class.";

        return ToolResult::success($output);
    }

    private function inspectClass(string $className): ToolResult
    {
        if ($className === '') {
            return ToolResult::error('Class name is required for methods action.');
        }

        if (!class_exists($className, true) && !interface_exists($className, true) && !enum_exists($className)) {
            return ToolResult::error("Class '{$className}' not found. Check the fully-qualified name.");
        }

        $reflection = new \ReflectionClass($className);

        $kind = match (true) {
            $reflection->isInterface() => 'interface',
            $reflection->isEnum() => 'enum',
            $reflection->isTrait() => 'trait',
            $reflection->isAbstract() => 'abstract class',
            $reflection->isFinal() => 'final class',
            default => 'class',
        };

        $output = "## {$kind} {$className}\n\n";

        // Parent class
        $parent = $reflection->getParentClass();
        if ($parent !== false) {
            $output .= "**Extends:** `{$parent->getName()}`\n";
        }

        // Interfaces
        $interfaces = $reflection->getInterfaceNames();
        if (!empty($interfaces)) {
            $output .= '**Implements:** ' . implode(', ', array_map(fn($i) => "`{$i}`", $interfaces)) . "\n";
        }

        $output .= "\n### Public Methods\n\n";

        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        if (empty($methods)) {
            $output .= "No public methods.\n";
        }

        foreach ($methods as $method) {
            // Skip inherited magic methods
            if (str_starts_with($method->getName(), '__') && $method->getName() !== '__construct') {
                if ($method->getDeclaringClass()->getName() !== $className) {
                    continue;
                }
            }

            $params = [];
            foreach ($method->getParameters() as $param) {
                $paramType = $param->getType();
                $typeStr = $paramType !== null ? $this->formatType($paramType) . ' ' : '';
                $default = '';

                if ($param->isDefaultValueAvailable()) {
                    try {
                        $defaultValue = $param->getDefaultValue();
                        $default = ' = ' . $this->formatDefaultValue($defaultValue);
                    } catch (\ReflectionException) {
                        $default = ' = ...';
                    }
                }

                $variadic = $param->isVariadic() ? '...' : '';
                $nullable = ($param->allowsNull() && $paramType !== null && !str_contains((string) $paramType, '?'))
                    ? '?' : '';

                $params[] = "{$nullable}{$typeStr}{$variadic}\${$param->getName()}{$default}";
            }

            $returnType = $method->getReturnType();
            $returnStr = $returnType !== null ? ': ' . $this->formatType($returnType) : '';
            $static = $method->isStatic() ? 'static ' : '';
            $declaring = $method->getDeclaringClass()->getName() !== $className
                ? " *(from {$method->getDeclaringClass()->getName()})*" : '';

            $paramStr = implode(', ', $params);
            $output .= "- `{$static}{$method->getName()}({$paramStr}){$returnStr}`{$declaring}\n";
        }

        // Constants (for enums)
        if ($reflection->isEnum()) {
            $output .= "\n### Cases\n\n";
            $constants = $reflection->getConstants();
            foreach ($constants as $name => $value) {
                if ($value instanceof \UnitEnum) {
                    $backing = $value instanceof \BackedEnum ? " = {$value->value}" : '';
                    $output .= "- `{$name}{$backing}`\n";
                }
            }
        }

        return ToolResult::success($output);
    }

    private function readSource(string $package, string $packageDir, string $file): ToolResult
    {
        if ($file === '') {
            return ToolResult::error('File path is required for source action.');
        }

        // Prevent directory traversal
        $normalizedFile = str_replace('\\', '/', $file);
        if (str_contains($normalizedFile, '..')) {
            return ToolResult::error('Directory traversal is not allowed.');
        }

        $fullPath = $packageDir . '/' . ltrim($normalizedFile, '/');

        // Verify the resolved path is inside the package directory
        $realPath = realpath($fullPath);
        $realPackageDir = realpath($packageDir);

        if ($realPath === false || $realPackageDir === false) {
            return ToolResult::error("File not found: {$file}");
        }

        if (!str_starts_with($realPath, $realPackageDir)) {
            return ToolResult::error('Access denied: path is outside the package directory.');
        }

        $content = file_get_contents($realPath);
        if ($content === false) {
            return ToolResult::error("Could not read file: {$file}");
        }

        if (strlen($content) > self::MAX_SOURCE_BYTES) {
            $content = substr($content, 0, self::MAX_SOURCE_BYTES)
                . "\n\n--- truncated ---";
        }

        return ToolResult::success("## {$package}/{$file}\n\n```php\n{$content}\n```");
    }

    private function resolvePackageDir(string $package): ?string
    {
        $dir = $this->projectRoot . '/vendor/' . $package;

        return is_dir($dir) ? $dir : null;
    }

    /**
     * Get PSR-4 autoload map for a package from installed.json.
     *
     * @return array<string, string>
     */
    private function getPackageAutoload(string $package): array
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

        foreach ($packages as $pkg) {
            if (!is_array($pkg) || ($pkg['name'] ?? '') !== $package) {
                continue;
            }

            $autoload = $pkg['autoload']['psr-4'] ?? [];

            return is_array($autoload) ? $autoload : [];
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function findPhpFiles(string $directory): array
    {
        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\UnexpectedValueException) {
            // Directory not readable
        }

        return $files;
    }

    private function resolveClassName(string $filePath, string $baseDir, string $namespace): ?string
    {
        $relativePath = str_replace($baseDir, '', $filePath);
        $relativePath = ltrim($relativePath, '/\\');

        $classPart = str_replace(['/', '\\'], '\\', substr($relativePath, 0, -4));
        $fqcn = rtrim($namespace, '\\') . '\\' . $classPart;

        return class_exists($fqcn, true) || interface_exists($fqcn, true) || enum_exists($fqcn) ? $fqcn : null;
    }

    private function formatType(\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionNamedType) {
            return ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '') . $type->getName();
        }

        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map(fn($t) => $this->formatType($t), $type->getTypes()));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(fn($t) => $this->formatType($t), $type->getTypes()));
        }

        return (string) $type;
    }

    private function formatDefaultValue(mixed $value): string
    {
        return match (true) {
            is_null($value) => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => "'" . addslashes($value) . "'",
            is_array($value) => '[]',
            default => (string) $value,
        };
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The inspection action to perform',
                            'enum' => ['readme', 'classes', 'methods', 'source'],
                        ],
                        'package' => [
                            'type' => 'string',
                            'description' => 'Package name (vendor/package)',
                        ],
                        'class' => [
                            'type' => 'string',
                            'description' => 'Fully-qualified class name (for methods action)',
                        ],
                        'file' => [
                            'type' => 'string',
                            'description' => 'Relative file path within the package (for source action)',
                        ],
                    ],
                    'required' => ['action', 'package'],
                ],
            ],
        ];
    }
}
