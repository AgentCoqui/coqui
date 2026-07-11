<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CoquiBot\Coqui\Api\CoreServices;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Contract\ApiFeatureInterface;

/**
 * Discovers API-feature providers contributed by installed Composer packages.
 *
 * Packages declare provider classes under extra.php-agents.apiFeatures in
 * composer.json. Each must implement ApiFeatureInterface and be no-arg
 * constructable. Mirrors ToolkitDiscovery / BackstoryExtractorDiscovery so
 * that whole HTTP features live in optional mods instead of core.
 */
final class ApiFeatureDiscovery
{
    private readonly string $installedJsonPath;

    public function __construct(?string $projectRoot = null)
    {
        $root = $projectRoot ?? self::locateProjectRoot();
        $this->installedJsonPath = rtrim($root, '/') . '/vendor/composer/installed.json';
    }

    /**
     * @return list<ApiFeatureInterface>
     */
    public function discover(): array
    {
        if (!is_file($this->installedJsonPath)) {
            return [];
        }

        $raw = file_get_contents($this->installedJsonPath);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $packages = $data['packages'] ?? $data;
        if (!is_array($packages)) {
            return [];
        }

        $features = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $declared = $package['extra']['php-agents']['apiFeatures'] ?? null;
            if (!is_array($declared)) {
                continue;
            }

            foreach ($declared as $className) {
                if (!is_string($className)) {
                    continue;
                }

                $feature = self::tryInstantiate($className);
                if ($feature !== null) {
                    $features[] = $feature;
                }
            }
        }

        return $features;
    }

    /**
     * Register every feature, isolating failures so one faulty mod cannot
     * abort API-server boot. Each register() runs in its own try/catch; on
     * failure $onError is invoked (when given) and the loop continues.
     *
     * @param iterable<ApiFeatureInterface> $features
     * @param (callable(ApiFeatureInterface, \Throwable): void)|null $onError
     */
    public function registerAll(iterable $features, Router $router, CoreServices $services, ?callable $onError = null): void
    {
        foreach ($features as $feature) {
            try {
                $feature->register($router, $services);
            } catch (\Throwable $e) {
                if ($onError !== null) {
                    $onError($feature, $e);
                }
            }
        }
    }

    private static function tryInstantiate(string $className): ?ApiFeatureInterface
    {
        try {
            if (!class_exists($className)) {
                return null;
            }

            /** @var class-string $className */
            $reflection = new \ReflectionClass($className);

            if (!$reflection->implementsInterface(ApiFeatureInterface::class) || $reflection->isAbstract()) {
                return null;
            }

            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                return null;
            }

            $instance = $reflection->newInstance();

            return $instance instanceof ApiFeatureInterface ? $instance : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function locateProjectRoot(): string
    {
        $dir = __DIR__;

        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/vendor/composer/installed.json')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        // Fallback: src/Config -> project root is two levels up.
        return dirname(__DIR__, 2);
    }
}
