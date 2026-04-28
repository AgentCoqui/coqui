<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Channel;

use CoquiBot\Coqui\Channel\Builtin\DiscordChannelDriver;
use CoquiBot\Coqui\Channel\Builtin\SignalChannelDriver;
use CoquiBot\Coqui\Channel\Builtin\TelegramChannelDriver;
use CoquiBot\Coqui\Contract\ChannelDriverInterface;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CarmeloSantana\PathHelper\PathHelper;

/**
 * Discovers built-in and external channel drivers.
 */
final class ChannelDiscovery
{
    /** @var array<string, ChannelDriverInterface> */
    private array $drivers = [];

    /** @var array<string, string> */
    private array $packages = [];

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly ?CredentialResolverInterface $credentialResolver = null,
    ) {
        $this->registerBuiltins();
    }

    /**
     * @return string[]
     */
    public function discoverAll(): array
    {
        $newDrivers = [];

        foreach ($this->packageEntries() as [$packageName, $classNames]) {
            foreach ($this->register($packageName, $classNames) as $driverName) {
                $newDrivers[] = $driverName;
            }
        }

        return $newDrivers;
    }

    /**
     * @param string[] $classNames
     * @return string[]
     */
    public function register(string $packageName, array $classNames): array
    {
        $registered = [];

        foreach ($classNames as $className) {
            $driver = $this->instantiateDriver($className, $packageName);
            if ($driver === null) {
                continue;
            }

            $driverName = $driver->driverName();
            if ($driverName === '' || isset($this->drivers[$driverName])) {
                continue;
            }

            $this->drivers[$driverName] = $driver;
            $this->packages[$driverName] = $packageName;
            $registered[] = $driverName;
        }

        return $registered;
    }

    public function driver(string $name): ?ChannelDriverInterface
    {
        return $this->drivers[$name] ?? null;
    }

    /**
     * @return array<string, ChannelDriverInterface>
     */
    public function drivers(): array
    {
        return $this->drivers;
    }

    /**
     * @return string[]
     */
    public function driverNames(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * @return array<string, string>
     */
    public function packages(): array
    {
        return $this->packages;
    }

    private function registerBuiltins(): void
    {
        $this->register('coquibot/coqui', [
            SignalChannelDriver::class,
            TelegramChannelDriver::class,
            DiscordChannelDriver::class,
        ]);
    }

    /**
     * @return iterable<array{0: string, 1: string[]}>
     */
    private function packageEntries(): iterable
    {
        foreach ([
            PathHelper::trimTrailingSlash($this->projectRoot) . '/vendor/composer/installed.json',
            PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/composer/installed.json',
        ] as $installedPath) {
            if (!file_exists($installedPath)) {
                continue;
            }

            $installedData = json_decode((string) file_get_contents($installedPath), true);
            if (!is_array($installedData)) {
                continue;
            }

            $packages = $installedData['packages'] ?? $installedData;
            if (!is_array($packages)) {
                continue;
            }

            foreach ($packages as $packageEntry) {
                if (!is_array($packageEntry)) {
                    continue;
                }

                $packageName = $packageEntry['name'] ?? null;
                $declared = $packageEntry['extra']['coqui']['channels'] ?? null;

                if (!is_string($packageName) || !is_array($declared)) {
                    continue;
                }

                $classNames = array_values(array_filter($declared, static fn(mixed $class): bool => is_string($class) && $class !== ''));
                if ($classNames === []) {
                    continue;
                }

                yield [$packageName, $classNames];
            }
        }
    }

    private function instantiateDriver(string $className, string $packageName): ?ChannelDriverInterface
    {
        if (!class_exists($className) || !is_subclass_of($className, ChannelDriverInterface::class)) {
            return null;
        }

        $context = [
            'workspacePath' => $this->workspacePath,
            'projectRoot' => $this->projectRoot,
            'packageName' => $packageName,
            'credentialResolver' => $this->credentialResolver,
        ];

        if (method_exists($className, 'fromCoquiContext')) {
            $instance = $className::fromCoquiContext($context);
            return $instance instanceof ChannelDriverInterface ? $instance : null;
        }

        if (method_exists($className, 'fromEnv')) {
            $instance = $className::fromEnv();
            return $instance instanceof ChannelDriverInterface ? $instance : null;
        }

        return new $className();
    }
}