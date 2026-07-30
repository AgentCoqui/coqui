<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Repl\TimeFormatter;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\InteractiveSessionService;
use CoquiBot\Coqui\Support\PersonaSessionLifecycleManager;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /new, /history, /sessions, /resume, /model slash commands.
 */
final class SessionHandler
{
    private const SESSION_FILE = '.coqui-session';

    public function __construct(
        private readonly BootManager $boot,
        private readonly SessionStorage $storage,
        private ?PersonaSessionLifecycleManager $lifecycleManager = null,
    ) {}

    public function createNewSession(string $role = 'orchestrator', ?string $persona = null): string
    {
        $result = $this->interactiveSessions()->createSession($role, $persona);
        $sessionId = (string) $result->session['id'];
        $this->saveSessionFile($sessionId);

        return $sessionId;
    }

    public function startFreshSession(?SymfonyStyle $io, string $currentSessionId, ?string $activePersona): ?string
    {
        if ($activePersona === null) {
            return $this->createNewSession();
        }

        $io?->warning(sprintf(
            'Starting a new session for persona "%s" will summarize the current chat, store memories, archive the conversation, and close the current session while preserving it in the database.',
            $activePersona,
        ));

        if ($io !== null && !$io->confirm('Start a fresh session for this persona?', false)) {
            $this->writeInfo($io, 'Kept the current personaScoped session open.');
            return null;
        }

        $result = $this->interactiveSessions()->createFreshPersonaSession(
            currentSessionId: $currentSessionId,
            persona: $activePersona,
            modelRole: SystemRole::Orchestrator->value,
            closureReasonPrefix: 'repl_new_persona_session',
        );
        $newSessionId = (string) $result->session['id'];
        $this->saveSessionFile($newSessionId);

        return $newSessionId;
    }

    public function loadOrCreateSession(?SymfonyStyle $io, string $role = 'orchestrator'): string
    {
        return $this->loadOrCreateScopedSession($io, null, $role);
    }

    public function loadOrCreatePersonaSession(?SymfonyStyle $io, string $persona, string $role = 'orchestrator'): string
    {
        return $this->loadOrCreateScopedSession($io, $persona, $role);
    }

    public function loadOrCreateAttachedSession(?SymfonyStyle $io, string $role = 'orchestrator'): string
    {
        $attachedId = $this->loadAttachedInteractiveSessionId();
        if ($attachedId !== null) {
            $this->writeInfo($io, 'Resumed previous session: ' . substr($attachedId, 0, 8) . '...');
            return $attachedId;
        }

        $latestId = $this->storage->getLatestInteractiveSessionId();
        if ($latestId !== null) {
            $this->saveSessionFile($latestId);
            $this->writeInfo($io, 'Resumed latest session: ' . substr($latestId, 0, 8) . '...');
            return $latestId;
        }

        $sessionId = $this->createNewSession($role);
        $this->writeInfo($io, 'Created new session: ' . substr($sessionId, 0, 8) . '...');

        return $sessionId;
    }

    public function saveSessionFile(?string $sessionId = null): void
    {
        if ($sessionId === null) {
            return;
        }
        $sessionFile = $this->boot->workspacePath() . '/' . self::SESSION_FILE;
        file_put_contents($sessionFile, $sessionId);
    }

    public function restoreActiveRoleFromSession(string $sessionId): ?string
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            return null;
        }

        $storedRole = (string) ($session['model_role'] ?? 'orchestrator');
        if ($storedRole !== '' && $storedRole !== 'orchestrator') {
            return $storedRole;
        }

        return null;
    }

    public function restoreActivePersonaFromSession(string $sessionId): ?string
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            return null;
        }

        $storedPersona = $session['persona_id'] ?? null;

        return is_string($storedPersona) && $storedPersona !== '' ? $storedPersona : null;
    }

    public function enforcePersonaRolePolicy(?SymfonyStyle $io, string $sessionId, ?string $persona): string
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            return SystemRole::Orchestrator->value;
        }

        $currentRole = (string) ($session['model_role'] ?? SystemRole::Orchestrator->value);
        if ($currentRole === '') {
            $currentRole = SystemRole::Orchestrator->value;
        }

        $effectiveRole = $this->interactiveSessions()->enforcePersonaRolePolicy($sessionId, $persona);
        if ($effectiveRole !== $currentRole) {
            $this->writeInfo(
                $io,
                sprintf('Persona "%s" does not allow role "%s". Reverted session to orchestrator.', $persona, $currentRole),
            );
        }

        return $effectiveRole;
    }

    public function showHistory(SymfonyStyle $io, string $sessionId): void
    {
        $messages = $this->storage->getMessages($sessionId);

        if (empty($messages)) {
            $io->info('No messages in this session.');
            return;
        }

        $io->section('Conversation History');

        foreach ($messages as $msg) {
            $role = ucfirst($msg['role']);
            $content = $msg['content'];

            if (strlen($content) > 200) {
                $content = substr($content, 0, 197) . '...';
            }

            $color = match ($msg['role']) {
                'user' => 'cyan',
                'assistant' => 'green',
                'system' => 'yellow',
                default => 'gray',
            };

            $io->writeln("<fg={$color}>{$role}:</> {$content}");
        }
    }

    public function listSessions(SymfonyStyle $io, string $currentSessionId): void
    {
        $sessions = $this->storage->listSessions(20);

        if (empty($sessions)) {
            $io->info('No sessions found.');
            return;
        }

        $rows = [];
        foreach ($sessions as $session) {
            $isCurrent = $session['id'] === $currentSessionId ? ' (current)' : '';
            $title = isset($session['title']) && $session['title'] !== ''
                ? ' — ' . $session['title']
                : '';
            $rows[] = [
                $session['id'] . $isCurrent . $title,
                $session['model_role'],
                $session['token_count'],
                TimeFormatter::timeSince($session['updated_at']),
            ];
        }

        $io->table(['ID', 'Role', 'Tokens', 'Updated'], $rows);
    }

    public function showModelInfo(SymfonyStyle $io, string $role = ''): void
    {
        if ($role !== '') {
            $model = $this->boot->roleResolver()->resolve($role);
            $io->writeln("<fg=gray>{$role}:</> {$model}");
            return;
        }

        $io->section('Model Configuration');
        $roles = $this->boot->roleResolver()->toArray();

        $rows = [];
        foreach ($roles as $r => $m) {
            $rows[] = [$r, $m['model'] ?? ''];
        }

        $io->table(['Role', 'Model'], $rows);
    }

    public function resume(SymfonyStyle $io, string $arg): ?string
    {
        if ($arg === '') {
            $io->error('Usage: /resume <session-id>');
            return null;
        }

        $session = $this->storage->getSession($arg);
        if ($session === null) {
            $io->error("Session not found: {$arg}");
            return null;
        }

        if (($session['visibility'] ?? 'visible') !== 'visible') {
            $io->error(sprintf('Session %s is internal and cannot be resumed from the REPL.', $arg));
            return null;
        }

        if ($this->storage->isSessionClosed($arg)) {
            $io->error(sprintf('Session %s is closed and cannot be resumed.', $arg));
            return null;
        }

        $this->saveSessionFile($arg);
        $io->success('Resumed session: ' . $arg);
        return $arg;
    }

    private function loadOrCreateScopedSession(?SymfonyStyle $io, ?string $persona, string $role): string
    {
        if ($persona !== null) {
            return $this->loadOrCreatePersonaScopedSession($io, $persona, $role);
        }

        $attachedId = $this->loadAttachedInteractiveSessionIdForScope($persona);
        if ($attachedId !== null) {
            $this->writeInfo($io, $this->attachedScopeMessage($persona, $attachedId));
            return $attachedId;
        }

        $result = $this->interactiveSessions()->resolveScopedSession($role, $persona, 'repl_unpersona_duplicate_cleanup');
        $sessionId = (string) $result->session['id'];
        $this->saveSessionFile($sessionId);
        $this->writeInfo($io, $result->created ? $this->createdScopeMessage($persona, $sessionId) : $this->latestScopeMessage($persona, $sessionId));

        return $sessionId;
    }

    private function loadOrCreatePersonaScopedSession(?SymfonyStyle $io, string $persona, string $role): string
    {
        $attachedId = $this->loadAttachedInteractiveSessionIdForScope($persona);
        $result = $this->interactiveSessions()->resolveScopedSession($role, $persona, 'persona_duplicate_cleanup');
        $sessionId = (string) $result->session['id'];

        if ($result->closedSessionIds !== []) {
            $this->writeInfo(
                $io,
                sprintf('Archived %d older active session(s) for persona "%s".', count($result->closedSessionIds), $persona),
            );
        }

        $this->saveSessionFile($sessionId);
        $message = $result->created
            ? $this->createdScopeMessage($persona, $sessionId)
            : ($attachedId === $sessionId
                ? $this->attachedScopeMessage($persona, $sessionId)
                : $this->latestScopeMessage($persona, $sessionId));
        $this->writeInfo($io, $message);

        return $sessionId;
    }

    private function loadAttachedInteractiveSessionId(): ?string
    {
        $sessionId = $this->readSessionFile();
        if ($sessionId === null) {
            return null;
        }

        if ($this->storage->getSession($sessionId) === null) {
            return null;
        }

        return $this->storage->isInteractiveSession($sessionId) ? $sessionId : null;
    }

    private function loadAttachedInteractiveSessionIdForScope(?string $persona): ?string
    {
        $sessionId = $this->loadAttachedInteractiveSessionId();
        if ($sessionId === null) {
            return null;
        }

        $session = $this->storage->getSession($sessionId);
        if (!is_array($session)) {
            return null;
        }

        $sessionPersona = $session['persona_id'] ?? null;
        $resolvedPersona = is_string($sessionPersona) && $sessionPersona !== '' ? $sessionPersona : null;

        return $resolvedPersona === $persona ? $sessionId : null;
    }

    private function readSessionFile(): ?string
    {
        $sessionFile = $this->boot->workspacePath() . '/' . self::SESSION_FILE;
        if (!file_exists($sessionFile)) {
            return null;
        }

        $fileContent = file_get_contents($sessionFile);
        if ($fileContent === false) {
            return null;
        }

        $sessionId = trim($fileContent);

        return $sessionId !== '' ? $sessionId : null;
    }

    private function writeInfo(?SymfonyStyle $io, string $message): void
    {
        $io?->info($message);
    }

    private function lifecycleManager(): PersonaSessionLifecycleManager
    {
        if ($this->lifecycleManager === null) {
            $this->lifecycleManager = new PersonaSessionLifecycleManager(
                storage: $this->storage,
                providerFactory: $this->boot->providerFactory(),
                roleResolver: $this->boot->roleResolver(),
                memoryStore: $this->boot->memoryStore(),
                artifactStore: $this->boot->artifactStore(),
            );
        }

        return $this->lifecycleManager;
    }

    private function interactiveSessions(): InteractiveSessionService
    {
        return new InteractiveSessionService(
            $this->storage,
            $this->boot->roleResolver(),
            $this->boot->personaDiscovery(),
            $this->lifecycleManager(),
        );
    }

    private function attachedScopeMessage(?string $persona, string $sessionId): string
    {
        if ($persona === null) {
            return 'Resumed attached unpersonaScoped session: ' . substr($sessionId, 0, 8) . '...';
        }

        return sprintf('Resumed attached persona session "%s": %s...', $persona, substr($sessionId, 0, 8));
    }

    private function latestScopeMessage(?string $persona, string $sessionId): string
    {
        if ($persona === null) {
            return 'Resumed latest unpersonaScoped session: ' . substr($sessionId, 0, 8) . '...';
        }

        return sprintf('Resumed latest persona session "%s": %s...', $persona, substr($sessionId, 0, 8));
    }

    private function createdScopeMessage(?string $persona, string $sessionId): string
    {
        if ($persona === null) {
            return 'Created new unpersonaScoped session: ' . substr($sessionId, 0, 8) . '...';
        }

        return sprintf('Created new persona session "%s": %s...', $persona, substr($sessionId, 0, 8));
    }
}
