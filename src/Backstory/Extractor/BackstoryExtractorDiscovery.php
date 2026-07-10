<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Discovers backstory extractors contributed by installed Composer packages.
 *
 * Packages declare their extractor classes under
 * extra.php-agents.backstoryExtractors in composer.json. Each declared class
 * must implement ExtractorInterface and be no-arg constructable. This mirrors
 * the toolkit discovery mechanism (extra.php-agents.toolkits) so that
 * dependency-heavy extractors live in optional mod packages instead of core.
 */
final class BackstoryExtractorDiscovery
{
    private readonly string $installedJsonPath;

    public function __construct(?string $projectRoot = null)
    {
        $root = $projectRoot ?? self::locateProjectRoot();
        $this->installedJsonPath = rtrim($root, '/') . '/vendor/composer/installed.json';
    }

    /**
     * @return list<ExtractorInterface>
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

        // Composer 2.x wraps entries in a 'packages' key.
        $packages = $data['packages'] ?? $data;
        if (!is_array($packages)) {
            return [];
        }

        $extractors = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $declared = $package['extra']['php-agents']['backstoryExtractors'] ?? null;
            if (!is_array($declared)) {
                continue;
            }

            foreach ($declared as $className) {
                if (!is_string($className)) {
                    continue;
                }

                $extractor = self::tryInstantiate($className);
                if ($extractor !== null) {
                    $extractors[] = $extractor;
                }
            }
        }

        return $extractors;
    }

    private static function tryInstantiate(string $className): ?ExtractorInterface
    {
        try {
            if (!class_exists($className)) {
                return null;
            }

            /** @var class-string $className */
            $reflection = new \ReflectionClass($className);

            if (!$reflection->implementsInterface(ExtractorInterface::class) || $reflection->isAbstract()) {
                return null;
            }

            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                return null;
            }

            $instance = $reflection->newInstance();

            return $instance instanceof ExtractorInterface ? $instance : null;
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

        // Fallback: src/Backstory/Extractor -> project root is three levels up.
        return dirname(__DIR__, 3);
    }
}
