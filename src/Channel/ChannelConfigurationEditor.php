<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Channel;

use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Mutates the channels.instances config block in openclaw.json.
 */
final readonly class ChannelConfigurationEditor
{
    public function __construct(
        private ConfigManager $configManager,
        private ChannelDiscovery $channelDiscovery,
        private ProfileDiscovery $profileDiscovery,
        private SessionStorage $sessionStorage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function rawConfig(): array
    {
        return $this->configManager->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        $instances = $this->instancesMap();

        return $instances[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $input
     * @return string[]
     */
    public function create(string $name, array $input): array
    {
        $config = $this->configManager->toArray();
        $instances = $this->coerceInstancesMap($config['channels']['instances'] ?? []);

        if (isset($instances[$name])) {
            return [sprintf('Channel instance "%s" already exists.', $name)];
        }

        $normalized = $this->normalizeDefinition($name, $input, creating: true);
        if (isset($normalized['errors'])) {
            return $normalized['errors'];
        }

        $instances[$name] = $normalized['instance'];
        $config['channels'] ??= [];
        $config['channels']['instances'] = $instances;

        return $this->configManager->save($config);
    }

    /**
     * @param array<string, mixed> $patch
     * @return string[]
     */
    public function update(string $name, array $patch): array
    {
        $config = $this->configManager->toArray();
        $instances = $this->coerceInstancesMap($config['channels']['instances'] ?? []);
        $current = $instances[$name] ?? null;

        if (!is_array($current)) {
            return [sprintf('Channel instance "%s" was not found.', $name)];
        }

        $merged = array_replace($current, $patch);
        $normalized = $this->normalizeDefinition($name, $merged, creating: false);
        if (isset($normalized['errors'])) {
            return $normalized['errors'];
        }

        $instances[$name] = $normalized['instance'];
        $config['channels'] ??= [];
        $config['channels']['instances'] = $instances;

        return $this->configManager->save($config);
    }

    /**
     * @return string[]
     */
    public function setEnabled(string $name, bool $enabled): array
    {
        return $this->update($name, ['enabled' => $enabled]);
    }

    public function delete(string $name): bool
    {
        $config = $this->configManager->toArray();
        $instances = $this->coerceInstancesMap($config['channels']['instances'] ?? []);

        if (!isset($instances[$name])) {
            return false;
        }

        unset($instances[$name]);

        $config['channels'] ??= [];
        if ($instances === []) {
            unset($config['channels']['instances']);
            if ($config['channels'] === []) {
                unset($config['channels']);
            }
        } else {
            $config['channels']['instances'] = $instances;
        }

        return $this->configManager->save($config) === [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function instancesMap(): array
    {
        $config = $this->configManager->toArray();

        return $this->coerceInstancesMap($config['channels']['instances'] ?? []);
    }

    /**
     * @param mixed $instances
     * @return array<string, array<string, mixed>>
     */
    private function coerceInstancesMap(mixed $instances): array
    {
        if (!is_array($instances)) {
            return [];
        }

        $map = [];
        if (array_is_list($instances)) {
            foreach ($instances as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $name = $entry['name'] ?? null;
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }

                $map[$name] = $entry;
            }

            return $map;
        }

        foreach ($instances as $name => $entry) {
            if (!is_string($name) || !is_array($entry) || trim($name) === '') {
                continue;
            }

            $map[$name] = $entry;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{instance: array<string, mixed>}|array{errors: string[]}
     */
    private function normalizeDefinition(string $name, array $input, bool $creating): array
    {
        $errors = [];
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $name)) {
            $errors[] = 'Channel names must be 1-64 characters and use letters, numbers, hyphens, or underscores.';
        }

        $driver = trim((string) ($input['driver'] ?? ''));
        $driverInstance = null;

        if ($driver === '') {
            $errors[] = 'driver is required';
        } elseif (($driverInstance = $this->channelDiscovery->driver($driver)) === null) {
            $errors[] = sprintf('Unknown channel driver "%s".', $driver);
        }

        $displayName = $input['displayName'] ?? $input['display_name'] ?? null;
        if ($displayName !== null && (!is_string($displayName) || trim($displayName) === '')) {
            $errors[] = 'displayName must be a non-empty string';
        }

        $defaultProfile = $input['defaultProfile'] ?? $input['default_profile'] ?? null;
        $boundSessionId = $input['boundSessionId'] ?? $input['bound_session_id'] ?? null;
        if ($defaultProfile !== null && $defaultProfile !== '') {
            if (!is_string($defaultProfile) || trim($defaultProfile) === '') {
                $errors[] = 'defaultProfile must be a non-empty string';
            } elseif (!$this->profileDiscovery->profileExists(trim($defaultProfile))) {
                $errors[] = sprintf('Unknown profile "%s".', trim($defaultProfile));
            }
        }

        if ($boundSessionId !== null && $boundSessionId !== '') {
            if (!is_string($boundSessionId) || trim($boundSessionId) === '') {
                $errors[] = 'boundSessionId must be a non-empty string';
            } else {
                $session = $this->sessionStorage->getSession(trim($boundSessionId));
                if ($session === null) {
                    $errors[] = sprintf('Unknown session "%s".', trim($boundSessionId));
                } elseif ((string) ($session['session_type'] ?? 'interactive') !== 'interactive') {
                    $errors[] = 'boundSessionId must reference an interactive session';
                } elseif ((string) ($session['visibility'] ?? 'visible') !== 'visible') {
                    $errors[] = 'boundSessionId must reference a visible session';
                }
            }
        }

        foreach (['settings', 'allowedScopes', 'allowed_scopes', 'security'] as $field) {
            if (array_key_exists($field, $input) && !is_array($input[$field])) {
                $errors[] = sprintf('%s must be an object or array', $field);
            }
        }

        if (array_key_exists('enabled', $input) && !is_bool($input['enabled'])) {
            $errors[] = 'enabled must be a boolean';
        }

        if ($errors === [] && $driverInstance !== null && !$creating) {
            $driverErrors = $driverInstance->validateInstanceConfig($input);
            foreach ($driverErrors as $error) {
                $errors[] = $error;
            }
        }

        if ($errors !== []) {
            return ['errors' => $errors];
        }

        $instance = [
            'driver' => $driver,
            'enabled' => is_bool($input['enabled'] ?? null) ? (bool) $input['enabled'] : true,
        ];

        if ($displayName !== null && trim((string) $displayName) !== '') {
            $instance['displayName'] = trim((string) $displayName);
        } elseif ($creating) {
            $instance['displayName'] = $name;
        }

        if ($defaultProfile !== null) {
            $trimmedProfile = trim((string) $defaultProfile);
            $instance['defaultProfile'] = $trimmedProfile !== '' ? $trimmedProfile : null;
        }

        if ($boundSessionId !== null) {
            $trimmedSessionId = trim((string) $boundSessionId);
            $instance['boundSessionId'] = $trimmedSessionId !== '' ? $trimmedSessionId : null;
        }

        $settings = $input['settings'] ?? [];
        if (is_array($settings) && $settings !== []) {
            $instance['settings'] = $settings;
        }

        $allowedScopes = $input['allowedScopes'] ?? $input['allowed_scopes'] ?? [];
        if (is_array($allowedScopes) && $allowedScopes !== []) {
            $instance['allowedScopes'] = array_values($allowedScopes);
        }

        $security = $input['security'] ?? [];
        if (is_array($security) && $security !== []) {
            $instance['security'] = $security;
        }

        return ['instance' => $instance];
    }
}