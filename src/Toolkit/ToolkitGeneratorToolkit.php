<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Config\PathHelper;
use CoquiBot\Coqui\Support\StringHelper;

/**
 * Generates complete toolkit package scaffolds for Coqui.
 *
 * Provides tools to create new toolkit packages with correct structure,
 * add tools to existing toolkits, and list workspace toolkit packages.
 * All generated packages follow Coqui conventions and are auto-discoverable.
 *
     * Scaffolded packages are created in workspace/packages/{name}/ and include
 * a composer.json with extra.php-agents declarations, a PSR-4 autoloaded
 * toolkit class implementing ToolkitInterface, and a README.md.
 */
final class ToolkitGeneratorToolkit implements ToolkitInterface
{
    private const string PACKAGES_DIR = 'packages';

    public function __construct(
        private readonly string $workspacePath,
    ) {}

    /**
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        return [
            $this->createTool(),
            $this->addToolTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <TOOLKIT-GENERATOR-GUIDELINES>
            Use these tools to create and manage toolkit packages for Coqui:

            ## Workflow
            1. `coqui_toolkit_create` — scaffold a new toolkit with composer.json, source, and README
            2. `coqui_toolkit_add` — add tools to an existing toolkit
            3. `coqui_toolkits` — list and manage all installed toolkits (standalone system tool, always available)

            ## After creating a toolkit
            1. Use `composer` tool (action: require, target: workspace) to install it
            2. Use `restart_coqui` to reload — the toolkit is auto-discovered on boot

            ## Tips
            - Pass `dependencies` as comma-separated "vendor/package:^version" strings
            - Pass `credentials` as JSON: {"API_KEY": "Description of the key"}
            - Tool names must be snake_case and unique across all toolkits
            - Generated toolkits follow all Coqui conventions automatically
            </TOOLKIT-GENERATOR-GUIDELINES>
            GUIDELINES;
    }

    private function createTool(): ToolInterface
    {
        return new Tool(
            name: 'coqui_toolkit_create',
            description: <<<'DESC'
                Scaffold a new toolkit package in the workspace. Creates composer.json,
                main toolkit class, and README.md. Optionally includes dependencies and
                credential declarations. Runs `composer install` automatically.

                Example: coqui_toolkit_create(name: "my-api", description: "My API toolkit",
                  dependencies: "guzzlehttp/guzzle:^7.0,symfony/http-client:^7.0",
                  credentials: '{"MY_API_KEY": "API key from https://example.com/keys"}')
                DESC,
            parameters: [
                new StringParameter(
                    'name',
                    'Package name in kebab-case (e.g. "my-toolkit"). Will be prefixed with coquibot/.',
                ),
                new StringParameter(
                    'description',
                    'Short description of what the toolkit does',
                ),
                new StringParameter(
                    'namespace',
                    'PHP namespace for the toolkit (default: auto-generated from name, e.g. CoquiBot\Toolkits\MyToolkit)',
                    required: false,
                ),
                new StringParameter(
                    'dependencies',
                    'Comma-separated list of Composer dependencies with version constraints (e.g. "guzzlehttp/guzzle:^7.0,symfony/http-client:^7.0")',
                    required: false,
                ),
                new StringParameter(
                    'credentials',
                    'JSON object mapping credential key names to descriptions (e.g. \'{"API_KEY": "Description"}\')',
                    required: false,
                ),
            ],
            callback: fn(array $input): ToolResult => $this->executeCreate($input),
        );
    }

    private function addToolTool(): ToolInterface
    {
        return new Tool(
            name: 'coqui_toolkit_add',
            description: <<<'DESC'
                Add a new tool to an existing toolkit package. Inserts a tool definition
                into the toolkit class's tools() array and creates the builder method.

                Example: coqui_toolkit_add(toolkit_name: "my-toolkit", tool_name: "fetch_data",
                  tool_description: "Fetch data from the API",
                  parameters: '[{"name": "url", "type": "string", "description": "The URL to fetch", "required": true}]')
                DESC,
            parameters: [
                new StringParameter(
                    'toolkit_name',
                    'Package name of the toolkit (e.g. "my-toolkit", without coquibot/ prefix)',
                ),
                new StringParameter(
                    'tool_name',
                    'Snake_case name for the new tool (e.g. "fetch_data")',
                ),
                new StringParameter(
                    'tool_description',
                    'Description of what the tool does',
                ),
                new StringParameter(
                    'parameters',
                    'JSON array of parameter definitions: [{"name": "param", "type": "string|number|bool|enum", "description": "...", "required": true, "values": ["a","b"]}]',
                    required: false,
                ),
            ],
            callback: fn(array $input): ToolResult => $this->executeAddTool($input),
        );
    }

    // ── Create ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function executeCreate(array $input): ToolResult
    {
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');

        if ($name === '') {
            return ToolResult::error('Package name is required.');
        }

        if ($description === '') {
            return ToolResult::error('Description is required.');
        }

        // Sanitize name to kebab-case
        $name = $this->sanitizeName($name);

        if ($name === '') {
            return ToolResult::error('Package name contains no valid characters after sanitization.');
        }

        $packageDir = $this->packagesDir() . '/' . $name;

        if (is_dir($packageDir)) {
            return ToolResult::error("Package directory already exists: packages/{$name}/");
        }

        // Resolve namespace
        $namespace = trim($input['namespace'] ?? '');
        if ($namespace === '') {
            $namespace = 'CoquiBot\\Toolkits\\' . $this->toPascalCase($name);
        }
        $namespace = rtrim($namespace, '\\');

        // Parse dependencies
        $dependencies = $this->parseDependencies($input['dependencies'] ?? '');

        // Parse credentials
        $credentials = $this->parseCredentials($input['credentials'] ?? '');

        // Create directory structure
        mkdir($packageDir . '/src', 0755, true);

        // Determine class name
        $className = $this->extractClassName($namespace);
        $toolkitClassName = $className . 'Toolkit';
        $fqcn = $namespace . '\\' . $toolkitClassName;

        // Generate files
        $composerJson = $this->generateComposerJson(
            $name,
            $description,
            $namespace,
            $fqcn,
            $dependencies,
            $credentials,
        );

        $toolkitPhp = $this->generateToolkitClass(
            $namespace,
            $toolkitClassName,
            $name,
            $credentials,
        );

        $readme = $this->generateReadme(
            $name,
            $description,
            $credentials,
        );

        file_put_contents($packageDir . '/composer.json', $composerJson);
        file_put_contents($packageDir . '/src/' . $toolkitClassName . '.php', $toolkitPhp);
        file_put_contents($packageDir . '/README.md', $readme);

        // Run composer install
        $installOutput = $this->runComposerInstall($packageDir);

        $output = "## Toolkit Created: coquibot/{$name}\n\n";
        $output .= "**Directory:** packages/{$name}/\n\n";
        $output .= "### Files Created\n";
        $output .= "- `composer.json` — package manifest with auto-discovery\n";
        $output .= "- `src/{$toolkitClassName}.php` — main toolkit class\n";
        $output .= "- `README.md` — documentation\n\n";

        if (!empty($dependencies)) {
            $output .= "### Dependencies\n";
            foreach ($dependencies as $pkg => $version) {
                $output .= "- `{$pkg}`: {$version}\n";
            }
            $output .= "\n";
        }

        if (!empty($credentials)) {
            $output .= "### Credentials\n";
            foreach ($credentials as $key => $desc) {
                $output .= "- `{$key}`: {$desc}\n";
            }
            $output .= "\n";
        }

        $output .= "### Composer Install\n";
        $output .= $installOutput . "\n\n";

        $output .= "### Next Steps\n";
        $output .= "1. Edit `src/{$toolkitClassName}.php` to add your tools\n";
        $output .= "2. Use `coqui_toolkit_add` to add more tools\n";
        $output .= "3. Install into workspace: `composer require coquibot/{$name}` (target: workspace)\n";
        $output .= "4. Restart Coqui to activate: `restart_coqui`\n";

        return ToolResult::success($output);
    }

    // ── Add Tool ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function executeAddTool(array $input): ToolResult
    {
        $toolkitName = $this->sanitizeName(trim($input['toolkit_name'] ?? ''));
        $toolName = trim($input['tool_name'] ?? '');
        $toolDescription = trim($input['tool_description'] ?? '');

        if ($toolkitName === '') {
            return ToolResult::error('Toolkit name is required.');
        }

        if ($toolName === '') {
            return ToolResult::error('Tool name is required.');
        }

        if ($toolDescription === '') {
            return ToolResult::error('Tool description is required.');
        }

        // Validate tool name is snake_case
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $toolName)) {
            return ToolResult::error("Tool name must be snake_case (e.g. 'fetch_data'). Got: '{$toolName}'");
        }

        $packageDir = $this->packagesDir() . '/' . $toolkitName;

        if (!is_dir($packageDir)) {
            return ToolResult::error("Toolkit package not found: packages/{$toolkitName}/");
        }

        // Find the toolkit class file
        $srcDir = $packageDir . '/src';
        $toolkitFile = $this->findToolkitFile($srcDir);

        if ($toolkitFile === null) {
            return ToolResult::error("No toolkit class file found in packages/{$toolkitName}/src/");
        }

        $content = file_get_contents($toolkitFile);
        if ($content === false) {
            return ToolResult::error("Cannot read toolkit file: {$toolkitFile}");
        }

        // Parse parameters
        $parameters = $this->parseToolParameters($input['parameters'] ?? '');

        // Generate the method name from tool name
        $methodName = $this->toolNameToMethod($toolName);

        // Check if tool already exists
        if (str_contains($content, "'{$toolName}'") || str_contains($content, "\"{$toolName}\"")) {
            return ToolResult::error("Tool '{$toolName}' already exists in this toolkit.");
        }

        // Generate the new tool method
        $toolMethod = $this->generateToolMethod($toolName, $toolDescription, $methodName, $parameters);

        // Insert the method before the final closing brace of the class
        $lastBracePos = strrpos($content, '}');
        if ($lastBracePos === false) {
            return ToolResult::error('Cannot find class closing brace in toolkit file.');
        }

        $content = substr($content, 0, $lastBracePos) . $toolMethod . "}\n";

        // Add the tool to the tools() return array
        $content = $this->insertToolIntoArray($content, $methodName);

        file_put_contents($toolkitFile, $content);

        $relativePath = basename($toolkitFile);

        return ToolResult::success(
            "## Tool Added: {$toolName}\n\n"
            . "Added `{$toolName}` tool to `src/{$relativePath}`.\n\n"
            . "- Method: `{$methodName}()`\n"
            . "- Parameters: " . (empty($parameters) ? 'none' : count($parameters)) . "\n\n"
            . "The toolkit class has been updated. If already installed, restart Coqui to pick up changes.",
        );
    }

    // ── Generators ──────────────────────────────────────────────────────

    /**
     * @param array<string, string> $dependencies
     * @param array<string, string> $credentials
     */
    private function generateComposerJson(
        string $name,
        string $description,
        string $namespace,
        string $fqcn,
        array $dependencies,
        array $credentials,
    ): string {
        $composerData = [
            'name' => 'coquibot/coqui-toolkit-' . $name,
            'description' => $description,
            'type' => 'library',
            'license' => 'MIT',
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
            'require' => array_merge(
                [
                    'php' => '^8.4',
                    'carmelosantana/php-agents' => '^0.2 || @dev',
                ],
                $dependencies,
            ),
            'extra' => [
                'php-agents' => [
                    'toolkits' => [$fqcn],
                ],
            ],
            'config' => [
                'sort-packages' => true,
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];

        if (!empty($credentials)) {
            $composerData['extra']['php-agents']['credentials'] = $credentials;
        }

        return json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * @param array<string, string> $credentials
     */
    private function generateToolkitClass(
        string $namespace,
        string $className,
        string $packageName,
        array $credentials,
    ): string {
        $hasCredentials = !empty($credentials);
        $firstCredentialKey = $hasCredentials ? array_key_first($credentials) : null;

        $uses = [
            'use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;',
            'use CarmeloSantana\PHPAgents\Tool\Tool;',
            'use CarmeloSantana\PHPAgents\Tool\ToolResult;',
            'use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;',
        ];

        $constructorBlock = '';
        $factoryBlock = '';
        $resolverBlock = '';
        $credentialProperty = '';

        if ($hasCredentials && $firstCredentialKey !== null) {
            $credentialProperty = "\n    private readonly string \$apiKey;\n";

            $constructorBlock = <<<PHP

                public function __construct(
                    string \$apiKey = '',
                ) {
                    \$this->apiKey = \$apiKey;
                }

                /**
                 * Factory method for ToolkitDiscovery — reads credentials from environment.
                 */
                public static function fromEnv(): self
                {
                    \$apiKey = getenv('{$firstCredentialKey}');

                    return new self(apiKey: \$apiKey !== false ? \$apiKey : '');
                }

            PHP;

            $resolverBlock = <<<PHP

                /**
                 * Resolve the API key lazily — checks constructor value, then process environment.
                 *
                 * This enables hot-reload: after CredentialTool saves a key via putenv(),
                 * the next tool call picks it up without restarting.
                 */
                private function resolveApiKey(): string
                {
                    if (\$this->apiKey !== '') {
                        return \$this->apiKey;
                    }

                    \$env = getenv('{$firstCredentialKey}');

                    return \$env !== false ? \$env : '';
                }

            PHP;
        }

        $toolkitTag = strtoupper(str_replace('-', '_', $packageName));

        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n";
        $code .= implode("\n", $uses) . "\n\n";
        $code .= "final class {$className} implements ToolkitInterface\n{\n";

        if ($credentialProperty !== '') {
            $code .= $credentialProperty;
        }

        if ($constructorBlock !== '') {
            $code .= $constructorBlock;
        }

        $code .= <<<PHP
            /**
             * @return \\CarmeloSantana\\PHPAgents\\Contract\\ToolInterface[]
             */
            public function tools(): array
            {
                return [
                    \$this->exampleTool(),
                ];
            }

            public function guidelines(): string
            {
                return <<<'GUIDELINES'
                <{$toolkitTag}-GUIDELINES>
                Guidelines for using {$packageName} tools.
                </{$toolkitTag}-GUIDELINES>
                GUIDELINES;
            }

            private function exampleTool(): \\CarmeloSantana\\PHPAgents\\Contract\\ToolInterface
            {
                return new Tool(
                    name: '{$packageName}_example',
                    description: 'Example tool — replace this with your implementation.',
                    parameters: [
                        new StringParameter('input', 'Example input parameter', required: false),
                    ],
                    callback: function (array \$input): ToolResult {
                        \$value = trim((string) (\$input['input'] ?? 'hello'));

                        return ToolResult::success("Example output: {\$value}");
                    },
                );
            }

        PHP;

        if ($resolverBlock !== '') {
            $code .= $resolverBlock;
        }

        $code .= "}\n";

        return $code;
    }

    /**
     * @param array<string, string> $credentials
     */
    private function generateReadme(
        string $name,
        string $description,
        array $credentials,
    ): string {
        $title = $this->toPascalCase($name);

        $readme = "# {$title}\n\n{$description}\n\n";
        $readme .= "## Requirements\n\n- PHP 8.4+\n- [Coqui](https://github.com/coquibot/coqui)\n\n";

        $readme .= "## Installation\n\n";
        $readme .= "```bash\ncomposer require coquibot/{$name}\n```\n\n";
        $readme .= "When installed alongside Coqui, the toolkit is **auto-discovered** — no manual registration needed.\n\n";

        if (!empty($credentials)) {
            $readme .= "## Configuration\n\n";
            $readme .= "Set the required credentials as environment variables or in your `.env` file:\n\n";
            $readme .= "```\n";
            foreach ($credentials as $key => $desc) {
                $readme .= "{$key}=your-value-here\n";
            }
            $readme .= "```\n\n";
            $readme .= "| Key | Description |\n";
            $readme .= "|-----|-------------|\n";
            foreach ($credentials as $key => $desc) {
                $readme .= "| `{$key}` | {$desc} |\n";
            }
            $readme .= "\n";
        }

        $readme .= "## Tools Provided\n\n";
        $readme .= "<!-- Add tool documentation here -->\n\n";
        $readme .= "## Development\n\n";
        $readme .= "```bash\ncomposer install\n```\n\n";
        $readme .= "## License\n\nMIT\n";

        return $readme;
    }

    // ── Tool Method Generation ──────────────────────────────────────────

    /**
     * @param array<int, array{name: string, type: string, description: string, required: bool, values?: string[]}> $parameters
     */
    private function generateToolMethod(
        string $toolName,
        string $toolDescription,
        string $methodName,
        array $parameters,
    ): string {
        $paramCode = $this->generateParameterCode($parameters);
        $escapedDescription = addcslashes($toolDescription, "'");

        $method = "\n    private function {$methodName}(): \\CarmeloSantana\\PHPAgents\\Contract\\ToolInterface\n";
        $method .= "    {\n";
        $method .= "        return new Tool(\n";
        $method .= "            name: '{$toolName}',\n";
        $method .= "            description: '{$escapedDescription}',\n";
        $method .= "            parameters: [\n";
        $method .= $paramCode;
        $method .= "            ],\n";
        $method .= "            callback: function (array \$input): ToolResult {\n";
        $method .= "                // TODO: Implement {$toolName}\n";
        $method .= "                return ToolResult::success('{$toolName} executed successfully.');\n";
        $method .= "            },\n";
        $method .= "        );\n";
        $method .= "    }\n";

        return $method;
    }

    /**
     * @param array<int, array{name: string, type: string, description: string, required: bool, values?: string[]}> $parameters
     */
    private function generateParameterCode(array $parameters): string
    {
        if (empty($parameters)) {
            return '';
        }

        $code = '';

        foreach ($parameters as $param) {
            $name = $param['name'];
            $desc = addcslashes($param['description'], "'");
            $required = $param['required'] ? 'true' : 'false';

            $code .= match ($param['type']) {
                'number', 'integer' => "                new \\CarmeloSantana\\PHPAgents\\Tool\\Parameter\\NumberParameter('{$name}', '{$desc}', required: {$required}),\n",
                'bool', 'boolean' => "                new \\CarmeloSantana\\PHPAgents\\Tool\\Parameter\\BoolParameter('{$name}', '{$desc}', required: {$required}),\n",
                'enum' => $this->generateEnumParam($name, $desc, $required, $param['values'] ?? []),
                default => "                new StringParameter('{$name}', '{$desc}', required: {$required}),\n",
            };
        }

        return $code;
    }

    /**
     * @param string[] $values
     */
    private function generateEnumParam(string $name, string $desc, string $required, array $values): string
    {
        $valuesStr = implode("', '", $values);

        return "                new \\CarmeloSantana\\PHPAgents\\Tool\\Parameter\\EnumParameter('{$name}', '{$desc}', values: ['{$valuesStr}'], required: {$required}),\n";
    }

    private function insertToolIntoArray(string $content, string $methodName): string
    {
        // Find the tools() method return array and append
        $pattern = '/(return\s*\[)(.*?)(\];)/s';

        if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $returnStart = $matches[1][1];
            $arrayContent = $matches[2][0];
            $closingPos = $matches[3][1];

            // Check if array already has items
            $trimmedContent = trim($arrayContent);
            if ($trimmedContent !== '') {
                // Add after last item
                $insertion = "\n            \$this->{$methodName}(),";
                $content = substr($content, 0, $closingPos) . $insertion . "\n        " . substr($content, $closingPos);
            } else {
                // Empty array — add first item
                $content = substr($content, 0, $closingPos)
                    . "\n            \$this->{$methodName}(),\n        "
                    . substr($content, $closingPos);
            }
        }

        return $content;
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function packagesDir(): string
    {
        return PathHelper::trimTrailingSlash($this->workspacePath) . '/' . self::PACKAGES_DIR;
    }

    private function sanitizeName(string $name): string
    {
        // Strip coquibot/ prefix if provided
        if (str_starts_with($name, 'coquibot/')) {
            $name = substr($name, 9);
        }

        return StringHelper::slug($name);
    }

    private function toPascalCase(string $kebab): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $kebab)));
    }

    private function extractClassName(string $namespace): string
    {
        $parts = explode('\\', $namespace);

        return end($parts);
    }

    private function toolNameToMethod(string $toolName): string
    {
        // hello_world → helloWorldTool
        $parts = explode('_', $toolName);
        $camel = $parts[0] . implode('', array_map('ucfirst', array_slice($parts, 1)));

        return $camel . 'Tool';
    }

    /**
     * @return array<string, string>
     */
    private function parseDependencies(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $deps = [];
        $parts = explode(',', $raw);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, ':')) {
                [$pkg, $version] = explode(':', $part, 2);
                $deps[trim($pkg)] = trim($version);
            } else {
                $deps[trim($part)] = '*';
            }
        }

        return $deps;
    }

    /**
     * @return array<string, string>
     */
    private function parseCredentials(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $credentials = [];
        foreach ($decoded as $key => $desc) {
            if (is_string($key) && is_string($desc)) {
                $credentials[$key] = $desc;
            }
        }

        return $credentials;
    }

    /**
     * @return array<int, array{name: string, type: string, description: string, required: bool, values?: string[]}>
     */
    private function parseToolParameters(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $params = [];
        foreach ($decoded as $param) {
            if (!is_array($param) || !isset($param['name'])) {
                continue;
            }

            $entry = [
                'name' => (string) $param['name'],
                'type' => (string) ($param['type'] ?? 'string'),
                'description' => (string) ($param['description'] ?? ''),
                'required' => (bool) ($param['required'] ?? true),
            ];

            if (isset($param['values']) && is_array($param['values'])) {
                $entry['values'] = array_map('strval', $param['values']);
            }

            $params[] = $entry;
        }

        return $params;
    }

    private function findToolkitFile(string $srcDir): ?string
    {
        if (!is_dir($srcDir)) {
            return null;
        }

        $files = glob($srcDir . '/*Toolkit.php');

        return !empty($files) ? $files[0] : null;
    }

    private function runComposerInstall(string $packageDir): string
    {
        $composer = $this->resolveComposerBinary();
        $cmd = escapeshellarg($composer) . ' install --no-interaction --no-progress';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $packageDir);

        if (!is_resource($process)) {
            return '⚠ Failed to start Composer process.';
        }

        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            $result = trim($stderr . "\n" . $stdout);
            return "⚠ Composer install exited with code {$returnCode}:\n{$result}";
        }

        return "✓ Dependencies installed successfully.";
    }

    /**
     * Resolve the Composer binary path.
     */
    private function resolveComposerBinary(): string
    {
        $envBin = getenv('COMPOSER_BIN');
        if ($envBin !== false && $envBin !== '') {
            return $envBin;
        }

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [
                getenv('APPDATA') . '\\Composer\\vendor\\bin\\composer',
                getenv('USERPROFILE') . '\\AppData\\Roaming\\Composer\\vendor\\bin\\composer',
            ]
            : [
                '/opt/homebrew/bin/composer',
                '/usr/local/bin/composer',
                '/usr/bin/composer',
            ];

        foreach ($candidates as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return 'composer';
    }
}
