<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Repl\RouteResult;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /persona and /personas slash commands.
 */
final class PersonaHandler
{
    public function __construct(
        private readonly BootManager $boot,
        private readonly SessionHandler $session,
    ) {}

    /**
     * Handle /persona [name|reset].
     *
        * Returns a RouteResult with the new persona and a scoped session ID when switching.
     */
    public function handlePersona(
        SymfonyStyle $io,
        string $arg,
        string $activeRole,
        ?string $activePersona,
    ): RouteResult {
        $personaDiscovery = $this->boot->personaDiscovery();

        if ($arg === '') {
            return $this->showCurrentPersona($io, $activePersona, $personaDiscovery);
        }

        $parts = preg_split('/\s+/', trim($arg), 2);
        $personaName = strtolower(trim($parts[0] ?? ''));
        $subArg = isset($parts[1]) ? trim($parts[1]) : '';

        if ($personaName === 'default') {
            return $this->handleDefaultPersona($io, $subArg);
        }

        if ($personaName === 'reset' || $personaName === 'none') {
            return $this->resetPersona($io, $activeRole, $activePersona);
        }

        if (!$personaDiscovery->personaExists($personaName)) {
            $available = $personaDiscovery->availablePersonas();
            $io->error(sprintf(
                'Persona "%s" not found. Available: %s',
                $personaName,
                $available !== [] ? implode(', ', $available) : '(none — create personas/{name}/soul.md in your workspace)',
            ));
            return RouteResult::continue();
        }

        if ($personaName === $activePersona) {
            $io->writeln(sprintf('<fg=gray>Already using persona "%s".</>', $personaName));
            return RouteResult::continue();
        }

        $sessionId = $this->session->loadOrCreatePersonaSession($io, $personaName, $activeRole);
        $effectiveRole = $this->session->enforcePersonaRolePolicy($io, $sessionId, $personaName);

        $description = $personaDiscovery->extractDescription($personaName);
        $io->success(sprintf(
            'Switched to persona "%s"%s',
            $personaName,
            $description !== null ? ' — ' . $description : '',
        ));

        return RouteResult::stateChange(
            newActiveRole: $effectiveRole,
            newSessionId: $sessionId,
            newActivePersona: $personaName,
        );
    }

    /**
     * Handle /personas — list all available personas.
     */
    public function handlePersonas(SymfonyStyle $io, ?string $activePersona): RouteResult
    {
        $personaDiscovery = $this->boot->personaDiscovery();
        $personas = $personaDiscovery->discoverAll();

        if ($personas === []) {
            $io->writeln('<fg=gray>No personas found. Create personas/{name}/soul.md in your workspace.</>');
            return RouteResult::continue();
        }

        $io->writeln('<info>Available personas:</info>');
        foreach ($personas as $name => $path) {
            $marker = $name === $activePersona ? ' <fg=green>◀ active</>' : '';
            $description = $personaDiscovery->extractDescription($name);
            $desc = $description !== null ? " — <fg=gray>{$description}</>" : '';
            $io->writeln(sprintf('  • %s%s%s', $name, $desc, $marker));
        }

        return RouteResult::continue();
    }

    private function showCurrentPersona(
        SymfonyStyle $io,
        ?string $activePersona,
        PersonaDiscovery $personaDiscovery,
    ): RouteResult {
        if ($activePersona === null) {
            $io->writeln('<info>Active persona:</info> (default — no persona)');
        } else {
            $description = $personaDiscovery->extractDescription($activePersona);
            $io->writeln(sprintf(
                '<info>Active persona:</info> %s%s',
                $activePersona,
                $description !== null ? ' — ' . $description : '',
            ));
        }

        $available = $personaDiscovery->availablePersonas();
        if ($available !== []) {
            $io->writeln('<fg=gray>Available personas:</> ' . implode(', ', $available));
        }

        return RouteResult::continue();
    }

    private function resetPersona(
        SymfonyStyle $io,
        string $activeRole,
        ?string $activePersona,
    ): RouteResult {
        if ($activePersona === null) {
            $io->writeln('<fg=gray>No persona is active.</>');
            return RouteResult::continue();
        }

        $sessionId = $this->session->loadOrCreateSession($io, $activeRole);

        $io->success('Persona cleared. Reverted to default identity.');

        return RouteResult::stateChange(
            newSessionId: $sessionId,
            newActivePersona: '',  // Empty string signals "clear persona"
        );
    }

    private function handleDefaultPersona(SymfonyStyle $io, string $arg): RouteResult
    {
        $personaDiscovery = $this->boot->personaDiscovery();
        $configManager = $this->boot->configManager();
        $configuredDefault = $configManager->config()->getDefaultPersona();

        if ($arg === '') {
            if ($configuredDefault === null) {
                $io->writeln('<info>Configured default persona:</info> none');
            } else {
                $description = $personaDiscovery->extractDescription($configuredDefault);
                $io->writeln(sprintf(
                    '<info>Configured default persona:</info> %s%s',
                    $configuredDefault,
                    $description !== null ? ' — ' . $description : '',
                ));
            }

            $io->writeln('<fg=gray>Use /persona default <name> to set it, or /persona default none to clear it.</>');
            return RouteResult::continue();
        }

        $target = strtolower(trim($arg));
        if ($target === 'none' || $target === 'reset' || $target === 'clear') {
            if ($configuredDefault === null) {
                $io->writeln('<fg=gray>No default persona is configured.</>');
                return RouteResult::continue();
            }

            $errors = $configManager->remove('agents.defaults.persona');
            if ($errors !== []) {
                $io->error('Failed to clear default persona: ' . implode('; ', $errors));
                return RouteResult::continue();
            }

            $io->success('Default persona cleared from openclaw.json.');
            return RouteResult::continue();
        }

        if (!$personaDiscovery->personaExists($target)) {
            $available = $personaDiscovery->availablePersonas();
            $io->error(sprintf(
                'Persona "%s" not found. Available: %s',
                $target,
                $available !== [] ? implode(', ', $available) : '(none — create personas/{name}/soul.md in your workspace)',
            ));
            return RouteResult::continue();
        }

        if ($target === $configuredDefault) {
            $io->writeln(sprintf('<fg=gray>Default persona is already "%s".</>', $target));
            return RouteResult::continue();
        }

        $errors = $configManager->set('agents.defaults.persona', $target);
        if ($errors !== []) {
            $io->error('Failed to save default persona: ' . implode('; ', $errors));
            return RouteResult::continue();
        }

        $io->success(sprintf('Default persona set to "%s" in openclaw.json.', $target));

        return RouteResult::continue();
    }
}
