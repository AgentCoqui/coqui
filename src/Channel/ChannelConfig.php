<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Channel;

/**
 * Normalizes the channels config block into runtime-ready instance definitions.
 */
final readonly class ChannelConfig
{
    /**
     * @param array<string, mixed> $defaults
     * @param list<array<string, mixed>> $instances
     */
    private function __construct(
        private array $defaults,
        private array $instances,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $defaults = $config['defaults'] ?? [];
        $instances = $config['instances'] ?? [];

        return new self(
            defaults: is_array($defaults) ? $defaults : [],
            instances: self::normalizeInstances($instances),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function instances(): array
    {
        return $this->instances;
    }

    /**
     * @param mixed $instances
     * @return list<array<string, mixed>>
     */
    private static function normalizeInstances(mixed $instances): array
    {
        if (!is_array($instances)) {
            return [];
        }

        $normalized = [];

        if (array_is_list($instances)) {
            foreach ($instances as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $name = $entry['name'] ?? null;
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }

                $normalized[] = self::normalizeInstance($name, $entry);
            }

            return $normalized;
        }

        foreach ($instances as $name => $entry) {
            if (!is_string($name) || !is_array($entry) || trim($name) === '') {
                continue;
            }

            $normalized[] = self::normalizeInstance($name, $entry);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function normalizeInstance(string $name, array $entry): array
    {
        $displayName = $entry['displayName'] ?? $entry['display_name'] ?? null;
        $defaultProfile = $entry['defaultProfile'] ?? $entry['default_profile'] ?? null;
        $settings = $entry['settings'] ?? [];
        $allowedScopes = $entry['allowedScopes'] ?? $entry['allowed_scopes'] ?? [];
        $security = $entry['security'] ?? [];

        return [
            'name' => $name,
            'driver' => is_string($entry['driver'] ?? null) ? trim((string) $entry['driver']) : '',
            'enabled' => is_bool($entry['enabled'] ?? null) ? (bool) $entry['enabled'] : true,
            'display_name' => is_string($displayName) && trim($displayName) !== '' ? trim($displayName) : $name,
            'default_profile' => is_string($defaultProfile) && trim($defaultProfile) !== '' ? trim($defaultProfile) : null,
            'settings' => is_array($settings) ? $settings : [],
            'allowed_scopes' => is_array($allowedScopes) ? $allowedScopes : [],
            'security' => is_array($security) ? $security : [],
            'source' => 'config',
        ];
    }
}