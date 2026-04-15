<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Repl\RouteResult;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /profile and /profiles slash commands.
 *
 * Switching profiles creates a new session (conversation-scoped identity).
 */
final class ProfileHandler
{
    public function __construct(
        private readonly BootManager $boot,
        private readonly SessionStorage $storage,
    ) {}

    /**
     * Handle /profile [name|reset].
     *
     * Returns a RouteResult with the new profile and a new session ID when switching.
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

        $profileName = strtolower(trim($arg));

        if ($profileName === 'reset' || $profileName === 'default' || $profileName === 'none') {
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

        // Create a new session for the profile switch (conversation-scoped identity)
        $modelString = $this->boot->roleResolver()->resolve($activeRole);
        $sessionId = $this->storage->createSession($activeRole, $modelString, $profileName);

        $description = $profileDiscovery->extractDescription($profileName);
        $io->success(sprintf(
            'Switched to profile "%s"%s (new session started)',
            $profileName,
            $description !== null ? ' — ' . $description : '',
        ));

        return RouteResult::stateChange(
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
        ProfileDiscovery $profileDiscovery,
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

        // Create a new session without profile (conversation-scoped)
        $modelString = $this->boot->roleResolver()->resolve($activeRole);
        $sessionId = $this->storage->createSession($activeRole, $modelString);

        $io->success('Profile cleared. Reverted to default identity (new session started).');

        return RouteResult::stateChange(
            newSessionId: $sessionId,
            newActiveProfile: '',  // Empty string signals "clear profile"
        );
    }
}
