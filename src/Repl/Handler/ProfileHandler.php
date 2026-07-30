<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Repl\RouteResult;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /profile and /profiles slash commands.
 */
final class ProfileHandler
{
    public function __construct(
        private readonly BootManager $boot,
        private readonly SessionHandler $session,
    ) {}

    /**
     * Handle /profile [name|reset].
     *
        * Returns a RouteResult with the new profile and a scoped session ID when switching.
     */
    public function handleProfile(
        SymfonyStyle $io,
        string $arg,
        string $activeRole,
        ?string $activeProfile,
    ): RouteResult {
        $profileDiscovery = $this->boot->profileDiscovery();

        if ($arg === '') {
            return $this->showCurrentProfile($io, $activeProfile, $profileDiscovery);
        }

        $parts = preg_split('/\s+/', trim($arg), 2);
        $profileName = strtolower(trim($parts[0] ?? ''));
        $subArg = isset($parts[1]) ? trim($parts[1]) : '';

        if ($profileName === 'default') {
            return $this->handleDefaultProfile($io, $subArg);
        }

        if ($profileName === 'reset' || $profileName === 'none') {
            return $this->resetProfile($io, $activeRole, $activeProfile);
        }

        if (!$profileDiscovery->profileExists($profileName)) {
            $available = $profileDiscovery->availableProfiles();
            $io->error(sprintf(
                'Profile "%s" not found. Available: %s',
                $profileName,
                $available !== [] ? implode(', ', $available) : '(none — create profiles/{name}/soul.md in your workspace)',
            ));
            return RouteResult::continue();
        }

        if ($profileName === $activeProfile) {
            $io->writeln(sprintf('<fg=gray>Already using profile "%s".</>', $profileName));
            return RouteResult::continue();
        }

        $sessionId = $this->session->loadOrCreateProfileSession($io, $profileName, $activeRole);
        $effectiveRole = $this->session->enforceProfileRolePolicy($io, $sessionId, $profileName);

        $description = $profileDiscovery->extractDescription($profileName);
        $io->success(sprintf(
            'Switched to profile "%s"%s',
            $profileName,
            $description !== null ? ' — ' . $description : '',
        ));

        return RouteResult::stateChange(
            newActiveRole: $effectiveRole,
            newSessionId: $sessionId,
            newActiveProfile: $profileName,
        );
    }

    /**
     * Handle /profiles — list all available profiles.
     */
    public function handleProfiles(SymfonyStyle $io, ?string $activeProfile): RouteResult
    {
        $profileDiscovery = $this->boot->profileDiscovery();
        $profiles = $profileDiscovery->discoverAll();

        if ($profiles === []) {
            $io->writeln('<fg=gray>No profiles found. Create profiles/{name}/soul.md in your workspace.</>');
            return RouteResult::continue();
        }

        $io->writeln('<info>Available profiles:</info>');
        foreach ($profiles as $name => $path) {
            $marker = $name === $activeProfile ? ' <fg=green>◀ active</>' : '';
            $description = $profileDiscovery->extractDescription($name);
            $desc = $description !== null ? " — <fg=gray>{$description}</>" : '';
            $io->writeln(sprintf('  • %s%s%s', $name, $desc, $marker));
        }

        return RouteResult::continue();
    }

    private function showCurrentProfile(
        SymfonyStyle $io,
        ?string $activeProfile,
        PersonaDiscovery $profileDiscovery,
    ): RouteResult {
        if ($activeProfile === null) {
            $io->writeln('<info>Active profile:</info> (default — no profile)');
        } else {
            $description = $profileDiscovery->extractDescription($activeProfile);
            $io->writeln(sprintf(
                '<info>Active profile:</info> %s%s',
                $activeProfile,
                $description !== null ? ' — ' . $description : '',
            ));
        }

        $available = $profileDiscovery->availableProfiles();
        if ($available !== []) {
            $io->writeln('<fg=gray>Available profiles:</> ' . implode(', ', $available));
        }

        return RouteResult::continue();
    }

    private function resetProfile(
        SymfonyStyle $io,
        string $activeRole,
        ?string $activeProfile,
    ): RouteResult {
        if ($activeProfile === null) {
            $io->writeln('<fg=gray>No profile is active.</>');
            return RouteResult::continue();
        }

        $sessionId = $this->session->loadOrCreateSession($io, $activeRole);

        $io->success('Profile cleared. Reverted to default identity.');

        return RouteResult::stateChange(
            newSessionId: $sessionId,
            newActiveProfile: '',  // Empty string signals "clear profile"
        );
    }

    private function handleDefaultProfile(SymfonyStyle $io, string $arg): RouteResult
    {
        $profileDiscovery = $this->boot->profileDiscovery();
        $configManager = $this->boot->configManager();
        $configuredDefault = $configManager->config()->getDefaultProfile();

        if ($arg === '') {
            if ($configuredDefault === null) {
                $io->writeln('<info>Configured default profile:</info> none');
            } else {
                $description = $profileDiscovery->extractDescription($configuredDefault);
                $io->writeln(sprintf(
                    '<info>Configured default profile:</info> %s%s',
                    $configuredDefault,
                    $description !== null ? ' — ' . $description : '',
                ));
            }

            $io->writeln('<fg=gray>Use /profile default <name> to set it, or /profile default none to clear it.</>');
            return RouteResult::continue();
        }

        $target = strtolower(trim($arg));
        if ($target === 'none' || $target === 'reset' || $target === 'clear') {
            if ($configuredDefault === null) {
                $io->writeln('<fg=gray>No default profile is configured.</>');
                return RouteResult::continue();
            }

            $errors = $configManager->remove('agents.defaults.profile');
            if ($errors !== []) {
                $io->error('Failed to clear default profile: ' . implode('; ', $errors));
                return RouteResult::continue();
            }

            $io->success('Default profile cleared from openclaw.json.');
            return RouteResult::continue();
        }

        if (!$profileDiscovery->profileExists($target)) {
            $available = $profileDiscovery->availableProfiles();
            $io->error(sprintf(
                'Profile "%s" not found. Available: %s',
                $target,
                $available !== [] ? implode(', ', $available) : '(none — create profiles/{name}/soul.md in your workspace)',
            ));
            return RouteResult::continue();
        }

        if ($target === $configuredDefault) {
            $io->writeln(sprintf('<fg=gray>Default profile is already "%s".</>', $target));
            return RouteResult::continue();
        }

        $errors = $configManager->set('agents.defaults.profile', $target);
        if ($errors !== []) {
            $io->error('Failed to save default profile: ' . implode('; ', $errors));
            return RouteResult::continue();
        }

        $io->success(sprintf('Default profile set to "%s" in openclaw.json.', $target));

        return RouteResult::continue();
    }
}
