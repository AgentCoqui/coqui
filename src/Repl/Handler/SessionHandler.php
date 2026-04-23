<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Repl\TimeFormatter;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\InteractiveSessionService;
use CoquiBot\Coqui\Support\ProfileSessionLifecycleManager;
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
        private ?ProfileSessionLifecycleManager $lifecycleManager = null,
    ) {}

    public function createNewSession(string $role = 'orchestrator', ?string $profile = null): string
    {
        $result = $this->interactiveSessions()->createSession($role, $profile);
        $sessionId = (string) $result->session['id'];
        $this->saveSessionFile($sessionId);

        return $sessionId;
    }

    public function startFreshSession(?SymfonyStyle $io, string $currentSessionId, ?string $activeProfile): ?string
    {
        if ($activeProfile === null) {
            return $this->createNewSession();
        }

        $io?->warning(sprintf(
            'Starting a new session for profile "%s" will summarize the current chat, store memories, archive the conversation, and close the current session while preserving it in the database.',
            $activeProfile,
        ));

        if ($io !== null && !$io->confirm('Start a fresh session for this profile?', false)) {
            $this->writeInfo($io, 'Kept the current profiled session open.');
            return null;
        }

        $result = $this->interactiveSessions()->createFreshProfileSession(
            currentSessionId: $currentSessionId,
            profile: $activeProfile,
            modelRole: SystemRole::Orchestrator->value,
            closureReasonPrefix: 'repl_new_profile_session',
        );
        $newSessionId = (string) $result->session['id'];
        $this->saveSessionFile($newSessionId);

        return $newSessionId;
    }

    public function loadOrCreateSession(?SymfonyStyle $io, string $role = 'orchestrator'): string
    {
        return $this->loadOrCreateScopedSession($io, null, $role);
    }

    public function loadOrCreateProfileSession(?SymfonyStyle $io, string $profile, string $role = 'orchestrator'): string
    {
        return $this->loadOrCreateScopedSession($io, $profile, $role);
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

    public function restoreActiveProfileFromSession(string $sessionId): ?string
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            return null;
        }

        $storedProfile = $session['profile'] ?? null;

        return is_string($storedProfile) && $storedProfile !== '' ? $storedProfile : null;
    }

    public function enforceProfileRolePolicy(?SymfonyStyle $io, string $sessionId, ?string $profile): string
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            return SystemRole::Orchestrator->value;
        }

        $currentRole = (string) ($session['model_role'] ?? SystemRole::Orchestrator->value);
        if ($currentRole === '') {
            $currentRole = SystemRole::Orchestrator->value;
        }

        $effectiveRole = $this->interactiveSessions()->enforceProfileRolePolicy($sessionId, $profile);
        if ($effectiveRole !== $currentRole) {
            $this->writeInfo(
                $io,
                sprintf('Profile "%s" does not allow role "%s". Reverted session to orchestrator.', $profile, $currentRole),
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

        if ($this->storage->isSessionClosed($arg)) {
            $io->error(sprintf('Session %s is closed and cannot be resumed.', $arg));
            return null;
        }

        $this->saveSessionFile($arg);
        $io->success('Resumed session: ' . $arg);
        return $arg;
    }

    private function loadOrCreateScopedSession(?SymfonyStyle $io, ?string $profile, string $role): string
    {
        if ($profile !== null) {
            return $this->loadOrCreateProfileScopedSession($io, $profile, $role);
        }

        $attachedId = $this->loadAttachedInteractiveSessionIdForScope($profile);
        if ($attachedId !== null) {
            $this->writeInfo($io, $this->attachedScopeMessage($profile, $attachedId));
            return $attachedId;
        }

        $result = $this->interactiveSessions()->resolveScopedSession($role, $profile, 'repl_unprofiled_duplicate_cleanup');
        $sessionId = (string) $result->session['id'];
        $this->saveSessionFile($sessionId);
        $this->writeInfo($io, $result->created ? $this->createdScopeMessage($profile, $sessionId) : $this->latestScopeMessage($profile, $sessionId));

        return $sessionId;
    }

    private function loadOrCreateProfileScopedSession(?SymfonyStyle $io, string $profile, string $role): string
    {
        $attachedId = $this->loadAttachedInteractiveSessionIdForScope($profile);
        $result = $this->interactiveSessions()->resolveScopedSession($role, $profile, 'profile_duplicate_cleanup');
        $sessionId = (string) $result->session['id'];

        if ($result->closedSessionIds !== []) {
            $this->writeInfo(
                $io,
                sprintf('Archived %d older active session(s) for profile "%s".', count($result->closedSessionIds), $profile),
            );
        }

        $this->saveSessionFile($sessionId);
        $message = $result->created
            ? $this->createdScopeMessage($profile, $sessionId)
            : ($attachedId === $sessionId
                ? $this->attachedScopeMessage($profile, $sessionId)
                : $this->latestScopeMessage($profile, $sessionId));
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

    private function loadAttachedInteractiveSessionIdForScope(?string $profile): ?string
    {
        $sessionId = $this->loadAttachedInteractiveSessionId();
        if ($sessionId === null) {
            return null;
        }

        $session = $this->storage->getSession($sessionId);
        if (!is_array($session)) {
            return null;
        }

        $sessionProfile = $session['profile'] ?? null;
        $resolvedProfile = is_string($sessionProfile) && $sessionProfile !== '' ? $sessionProfile : null;

        return $resolvedProfile === $profile ? $sessionId : null;
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

    private function lifecycleManager(): ProfileSessionLifecycleManager
    {
        if ($this->lifecycleManager === null) {
            $this->lifecycleManager = new ProfileSessionLifecycleManager(
                storage: $this->storage,
                providerFactory: $this->boot->providerFactory(),
                roleResolver: $this->boot->roleResolver(),
                memoryStore: $this->boot->memoryStore(),
                todoStore: $this->boot->todoStore(),
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
            $this->boot->profileDiscovery(),
            $this->lifecycleManager(),
        );
    }

    private function attachedScopeMessage(?string $profile, string $sessionId): string
    {
        if ($profile === null) {
            return 'Resumed attached unprofiled session: ' . substr($sessionId, 0, 8) . '...';
        }

        return sprintf('Resumed attached profile session "%s": %s...', $profile, substr($sessionId, 0, 8));
    }

    private function latestScopeMessage(?string $profile, string $sessionId): string
    {
        if ($profile === null) {
            return 'Resumed latest unprofiled session: ' . substr($sessionId, 0, 8) . '...';
        }

        return sprintf('Resumed latest profile session "%s": %s...', $profile, substr($sessionId, 0, 8));
    }

    private function createdScopeMessage(?string $profile, string $sessionId): string
    {
        if ($profile === null) {
            return 'Created new unprofiled session: ' . substr($sessionId, 0, 8) . '...';
        }

        return sprintf('Created new profile session "%s": %s...', $profile, substr($sessionId, 0, 8));
    }
}
