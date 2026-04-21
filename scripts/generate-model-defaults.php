#!/usr/bin/env php
<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class ScriptConfig implements ConfigInterface
{
    /**
     * @param array<string, array<string, mixed>> $providerConfigs
     */
    public function __construct(
        private array $providerConfigs,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function resolveModel(string $modelOrAlias): string
    {
        return $modelOrAlias;
    }

    public function getPrimaryModel(): string
    {
        return '';
    }

    public function getImageModel(): ?string
    {
        return null;
    }

    /**
     * @return string[]
     */
    public function getFallbacks(): array
    {
        return [];
    }

    public function getProviderConfig(string $provider): array
    {
        return $this->providerConfigs[$provider] ?? [];
    }

    public function getModelDefinition(string $model): ?ModelDefinition
    {
        return null;
    }
}

$options = getopt('', [
    'write',
    'provider:',
    'defaults:',
    'report:',
    'env-file:',
    'workspace-env:',
    'strict',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
Usage: php scripts/generate-model-defaults.php [options]

Refresh provider-backed model catalogs in config/defaults.json.

Options:
  --write                 Persist changes back to config/defaults.json.
  --provider=NAME         Limit refresh to one provider. Repeatable.
  --defaults=PATH         Path to defaults.json. Default: config/defaults.json.
    --report=PATH           Write the refresh report to a JSON file. Default: BUILD/reports/model-defaults-report.json.
  --env-file=PATH         Load provider API keys from an explicit .env file.
  --workspace-env=PATH    Alias for --env-file.
  --strict                Exit non-zero if any selected provider fails discovery.
  --help                  Show this help text.

Examples:
  php scripts/generate-model-defaults.php --provider=openai
  php scripts/generate-model-defaults.php --write
    php scripts/generate-model-defaults.php --report BUILD/reports/models.json
  php scripts/generate-model-defaults.php --write --env-file ~/.coqui/.workspace/.env
TXT);
    exit(0);
}

$projectRoot = dirname(__DIR__);
$defaultsOption = $options['defaults'] ?? null;
$defaultsPath = resolvePath(is_string($defaultsOption) && $defaultsOption !== ''
    ? $defaultsOption
    : ($projectRoot . '/config/defaults.json'));
$reportOption = $options['report'] ?? null;
$reportPath = resolvePath(is_string($reportOption) && $reportOption !== ''
    ? $reportOption
    : ($projectRoot . '/BUILD/reports/model-defaults-report.json'));
$defaults = loadDefaultsFile($defaultsPath);
$envFile = resolveEnvFile($options, $defaults);

if ($envFile !== null) {
    loadEnvIntoProcess($envFile);
}

$providerOption = $options['provider'] ?? null;
$providers = resolveProviders(
    $defaults,
    is_string($providerOption) || is_array($providerOption) ? $providerOption : null,
);
$providerConfigs = buildProviderConfigs($defaults);
$factory = new ProviderFactory(new ScriptConfig($providerConfigs));

$failures = [];
$summaries = [];
$reportProviders = [];

foreach ($providers as $provider) {
    if (!isset($defaults['providers'][$provider]) || !is_array($defaults['providers'][$provider])) {
        $failures[$provider] = 'Provider is not defined in defaults.json.';
        continue;
    }

    $existingCurated = $defaults['providers'][$provider]['curatedModels'] ?? [];
    if (!is_array($existingCurated)) {
        $existingCurated = [];
    }

    try {
        $definitions = $factory->create($provider . '/__discovery__')->models();
    } catch (\Throwable $e) {
        $failures[$provider] = $e->getMessage();
        continue;
    }

    if ($definitions === []) {
        $failures[$provider] = 'Provider returned no models.';
        continue;
    }

    $existingById = indexCuratedModels($existingCurated);
    $existingIds = array_keys($existingById);
    $generated = [];
    $added = [];
    $heuristicOnly = [];

    foreach ($definitions as $definition) {
        $existing = $existingById[$definition->id] ?? [];
        $generatedModel = mergeDiscoveredModel($definition, $existing);
        $generated[] = $generatedModel;

        if (!in_array($definition->id, $existingIds, true)) {
            $added[] = $definition->id;
        }

        if (isHeuristicOnlyModel($generatedModel)) {
            $heuristicOnly[] = $definition->id;
        }

        unset($existingById[$definition->id]);
    }

    $removed = array_keys($existingById);

    $defaults['providers'][$provider]['curatedModels'] = $generated;
    $summaries[] = [
        'provider' => $provider,
        'discovered' => count($generated),
        'removed' => count($removed),
    ];
    $reportProviders[$provider] = [
        'discovered' => count($generated),
        'added' => $added,
        'removed' => $removed,
        'heuristicOnly' => $heuristicOnly,
    ];
}

foreach ($summaries as $summary) {
    $line = sprintf('[ok] %s: discovered %d model(s)', $summary['provider'], $summary['discovered']);

    if ($summary['removed'] > 0) {
        $line .= sprintf(', removed %d stale curated entry(s)', $summary['removed']);
    }

    fwrite(STDOUT, $line . PHP_EOL);
}

foreach ($failures as $provider => $message) {
    fwrite(STDERR, sprintf('[warn] %s: %s%s', $provider, $message, PHP_EOL));
}

writeReport($reportPath, [
    'generatedAt' => gmdate(DATE_ATOM),
    'defaultsPath' => $defaultsPath,
    'providers' => $reportProviders,
    'failures' => $failures,
]);

fwrite(STDOUT, 'Report: ' . $reportPath . PHP_EOL);

if (isset($options['strict']) && $failures !== []) {
    exit(1);
}

if (!isset($options['write'])) {
    fwrite(STDOUT, PHP_EOL . 'Dry run only. Pass --write to persist changes.' . PHP_EOL);
    exit(0);
}

$encoded = json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents($defaultsPath, $encoded . PHP_EOL);

fwrite(STDOUT, PHP_EOL . 'Updated ' . $defaultsPath . PHP_EOL);
exit(0);

/**
 * @return array<string, mixed>
 */
function loadDefaultsFile(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException('Defaults file not found: ' . $path);
    }

    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Unable to read defaults file: ' . $path);
    }

    $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Defaults file must decode to a JSON object.');
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $options
 * @param array<string, mixed> $defaults
 */
function resolveEnvFile(array $options, array $defaults): ?string
{
    $explicit = $options['env-file'] ?? $options['workspace-env'] ?? null;
    if (is_string($explicit) && $explicit !== '') {
        return resolvePath($explicit);
    }

    $workspace = $defaults['defaults']['workspace'] ?? null;
    if (!is_string($workspace) || $workspace === '') {
        return null;
    }

    $path = resolvePath(rtrim($workspace, '/') . '/.env');

    return file_exists($path) ? $path : null;
}

/**
 * @param array<string, mixed> $defaults
 * @param string|list<string>|null $selectedProviders
 * @return string[]
 */
function resolveProviders(array $defaults, string|array|null $selectedProviders): array
{
    $available = array_keys(is_array($defaults['providers'] ?? null) ? $defaults['providers'] : []);

    if ($selectedProviders === null) {
        return $available;
    }

    $selected = is_array($selectedProviders) ? $selectedProviders : [$selectedProviders];
    $selected = array_values(array_filter($selected, static fn(string $value): bool => $value !== ''));

    $invalid = array_diff($selected, $available);
    if ($invalid !== []) {
        throw new RuntimeException('Unknown provider(s): ' . implode(', ', $invalid));
    }

    return $selected;
}

/**
 * @param array<string, mixed> $defaults
 * @return array<string, array<string, mixed>>
 */
function buildProviderConfigs(array $defaults): array
{
    $providers = is_array($defaults['providers'] ?? null) ? $defaults['providers'] : [];
    $configs = [];

    foreach ($providers as $providerName => $providerConfig) {
        if (!is_array($providerConfig)) {
            continue;
        }

        $envVar = $providerConfig['apiKeyEnvVar'] ?? null;
        $apiKey = is_string($envVar) && $envVar !== '' ? getenv($envVar) : false;

        $configs[$providerName] = [
            'baseUrl' => $providerConfig['baseUrl'] ?? '',
            'api' => $providerConfig['api'] ?? 'openai-completions',
            'apiKey' => is_string($apiKey) ? $apiKey : '',
        ];
    }

    return $configs;
}

function loadEnvIntoProcess(string $envFile): void
{
    if (!file_exists($envFile)) {
        fwrite(STDERR, '[warn] .env file not found: ' . $envFile . PHP_EOL);
        return;
    }

    $content = file_get_contents($envFile);
    if ($content === false) {
        fwrite(STDERR, '[warn] Unable to read .env file: ' . $envFile . PHP_EOL);
        return;
    }

    foreach (explode("\n", $content) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $equalsPos = strpos($line, '=');
        if ($equalsPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $equalsPos));
        $value = trim(substr($line, $equalsPos + 1));

        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $existing = getenv($key);
        if ($existing === false || $existing === '') {
            putenv($key . '=' . $value);
        }
    }
}

/**
 * @param array<int, array<string, mixed>> $curatedModels
 * @return array<string, array<string, mixed>>
 */
function indexCuratedModels(array $curatedModels): array
{
    $indexed = [];

    foreach ($curatedModels as $model) {
        $id = $model['id'] ?? null;
        if (!is_string($id) || $id === '') {
            continue;
        }

        $indexed[$id] = $model;
    }

    return $indexed;
}

/**
 * @param array<string, mixed> $existing
 * @return array<string, mixed>
 */
function mergeDiscoveredModel(ModelDefinition $definition, array $existing): array
{
    $resolvedContext = resolveBudgetField('contextWindow', $definition, $existing, 4096);
    $resolvedMaxTokens = resolveBudgetField('maxTokens', $definition, $existing, 2048);
    $resolvedFieldSources = mergeResolvedFieldSources($definition, $existing, [
        'contextWindow' => $resolvedContext['source'],
        'maxTokens' => $resolvedMaxTokens['source'],
    ]);

    $merged = [
        'id' => $definition->id,
        'name' => chooseDisplayName($definition, $existing),
    ];

    if (!empty($existing['recommended'])) {
        $merged['recommended'] = true;
    }

    $merged['contextWindow'] = $resolvedContext['value'];
    $merged['maxTokens'] = $resolvedMaxTokens['value'];

    if ($definition->reasoning || !empty($existing['reasoning'])) {
        $merged['reasoning'] = true;
    }

    if ($definition->supportsVision() || !empty($existing['vision'])) {
        $merged['vision'] = true;
    }

    if ($definition->supportsToolCalls() || !empty($existing['toolCalls'])) {
        $merged['toolCalls'] = true;
    }

    if ($definition->thinking || !empty($existing['thinking'])) {
        $merged['thinking'] = true;
    }

    $family = firstNonEmptyString([
        $definition->family,
        is_string($existing['family'] ?? null) ? $existing['family'] : null,
    ]);
    if ($family !== null) {
        $merged['family'] = $family;
    }

    $alias = firstNonEmptyString([
        $definition->alias,
        is_string($existing['alias'] ?? null) ? $existing['alias'] : null,
    ]);
    if ($alias !== null) {
        $merged['alias'] = $alias;
    }

    $numCtx = $definition->numCtx;
    if (!is_int($numCtx) && is_int($existing['numCtx'] ?? null)) {
        $numCtx = $existing['numCtx'];
    }

    if (is_int($numCtx) && $numCtx > 0) {
        $merged['numCtx'] = $numCtx;
    }

    $metadataSource = firstNonEmptyString([
        resolveMetadataSource($definition, $existing, $resolvedFieldSources),
        is_string($existing['metadataSource'] ?? null) ? $existing['metadataSource'] : null,
    ]);
    if ($metadataSource !== null) {
        $merged['metadataSource'] = $metadataSource;
    }

    if ($resolvedFieldSources !== []) {
        $merged['fieldSources'] = $resolvedFieldSources;
    }

    if (is_array($existing['cost'] ?? null) && $existing['cost'] !== []) {
        $merged['cost'] = $existing['cost'];
    }

    foreach (preservedManualFields($existing) as $key => $value) {
        $merged[$key] = $value;
    }

    return $merged;
}

/**
 * @param array<string, mixed> $existing
 * @return array<string, mixed>
 */
function preservedManualFields(array $existing): array
{
    $managed = [
        'id',
        'name',
        'recommended',
        'reasoning',
        'vision',
        'toolCalls',
        'thinking',
        'contextWindow',
        'maxTokens',
        'family',
        'alias',
        'numCtx',
        'metadataSource',
        'fieldSources',
        'cost',
        'input',
    ];

    $preserved = [];

    foreach ($existing as $key => $value) {
        if (!in_array($key, $managed, true)) {
            $preserved[$key] = $value;
        }
    }

    return $preserved;
}

/**
 * @param array<string, mixed> $existing
 */
function chooseDisplayName(ModelDefinition $definition, array $existing): string
{
    $existingName = is_string($existing['name'] ?? null) ? trim($existing['name']) : '';
    $discoveredName = trim($definition->name);

    if ($discoveredName !== '' && $discoveredName !== $definition->id) {
        return $discoveredName;
    }

    if ($existingName !== '') {
        return $existingName;
    }

    return $definition->id;
}

function normalizePositiveInt(int $value, mixed $fallback): int
{
    if ($value > 0) {
        return $value;
    }

    if (is_int($fallback) && $fallback > 0) {
        return $fallback;
    }

    if (is_string($fallback) && ctype_digit($fallback) && (int) $fallback > 0) {
        return (int) $fallback;
    }

    return 1;
}

/**
 * @param array<int, ?string> $values
 */
function firstNonEmptyString(array $values): ?string
{
    foreach ($values as $value) {
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return null;
}

function resolvePath(string $path): string
{
    if ($path === '') {
        return $path;
    }

    if ($path[0] === '~') {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home . substr($path, 1);
        }
    }

    return $path;
}

/**
 * @param array<string, mixed> $model
 */
function isHeuristicOnlyModel(array $model): bool
{
    $metadataSource = $model['metadataSource'] ?? null;
    if ($metadataSource === 'heuristic') {
        return true;
    }

    $fieldSources = $model['fieldSources'] ?? null;
    if (!is_array($fieldSources) || $fieldSources === []) {
        return false;
    }

    foreach ($fieldSources as $source) {
        if ($source === 'heuristic') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $existing
 * @return array{value: int, source: string}
 */
function resolveBudgetField(string $field, ModelDefinition $definition, array $existing, int $fallback): array
{
    $discoveredValue = $field === 'contextWindow' ? $definition->contextWindow : $definition->maxTokens;
    $discoveredSource = resolveDiscoveredFieldSource($field, $definition);
    $existingValue = extractExistingPositiveInt($existing[$field] ?? null);

    if ($existingValue !== null && $discoveredSource === 'heuristic') {
        return [
            'value' => $existingValue,
            'source' => resolveExistingFieldSource($field, $existing),
        ];
    }

    if ($discoveredValue > 0) {
        return [
            'value' => $discoveredValue,
            'source' => $discoveredSource,
        ];
    }

    if ($existingValue !== null) {
        return [
            'value' => $existingValue,
            'source' => resolveExistingFieldSource($field, $existing),
        ];
    }

    return [
        'value' => $fallback,
        'source' => $discoveredSource,
    ];
}

/**
 * @param array<string, mixed> $existing
 * @param array<string, string> $resolvedBudgetSources
 * @return array<string, string>
 */
function mergeResolvedFieldSources(ModelDefinition $definition, array $existing, array $resolvedBudgetSources): array
{
    $fieldSources = is_array($existing['fieldSources'] ?? null) ? $existing['fieldSources'] : [];

    foreach ($definition->fieldSources as $field => $source) {
        if (is_string($field) && $field !== '' && is_string($source) && $source !== '') {
            $fieldSources[$field] = $source;
        }
    }

    foreach ($resolvedBudgetSources as $field => $source) {
        $fieldSources[$field] = $source;
    }

    return $fieldSources;
}

/**
 * @param array<string, mixed> $existing
 * @param array<string, string> $fieldSources
 */
function resolveMetadataSource(ModelDefinition $definition, array $existing, array $fieldSources): ?string
{
    $sources = array_values(array_unique(array_filter($fieldSources, static fn(string $source): bool => $source !== '')));
    $hasHeuristic = in_array('heuristic', $sources, true);
    $hasStaticFallback = in_array('static-fallback', $sources, true);
    $existingMetadataSource = is_string($existing['metadataSource'] ?? null) ? $existing['metadataSource'] : null;

    if ($hasHeuristic && $hasStaticFallback) {
        return 'merged';
    }

    if ($hasStaticFallback) {
        return $existingMetadataSource ?? 'static-fallback';
    }

    if ($definition->metadataSource !== null && $definition->metadataSource !== '') {
        return $definition->metadataSource;
    }

    return $existingMetadataSource;
}

function resolveDiscoveredFieldSource(string $field, ModelDefinition $definition): string
{
    $source = $definition->fieldSources[$field] ?? null;

    if (is_string($source) && $source !== '') {
        return $source;
    }

    return $definition->metadataSource ?? 'heuristic';
}

/**
 * @param array<string, mixed> $existing
 */
function resolveExistingFieldSource(string $field, array $existing): string
{
    $fieldSources = $existing['fieldSources'] ?? null;
    if (is_array($fieldSources) && is_string($fieldSources[$field] ?? null) && $fieldSources[$field] !== '') {
        return $fieldSources[$field];
    }

    $metadataSource = $existing['metadataSource'] ?? null;
    if (is_string($metadataSource) && $metadataSource !== '') {
        return $metadataSource;
    }

    return 'static-fallback';
}

function extractExistingPositiveInt(mixed $value): ?int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }

    if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
        return (int) $value;
    }

    return null;
}

/**
 * @param array<string, mixed> $report
 */
function writeReport(string $reportPath, array $report): void
{
    $directory = dirname($reportPath);
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($reportPath, $encoded . PHP_EOL);
}