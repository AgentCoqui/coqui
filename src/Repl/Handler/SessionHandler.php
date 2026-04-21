<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ProfilePreferences;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Repl\TimeFormatter;
use CoquiBot\Coqui\Storage\SessionStorage;
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
    ) {}

    public function createNewSession(string $role = 'orchestrator', ?string $profile = null): string
    {
        $modelString = $this->boot->roleResolver()->resolve($role, $profile);
        $sessionId = $this->storage->createSession($role, $modelString, $profile);
        $this->saveSessionFile($sessionId);

        return $sessionId;
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

        if ($profile === null || !$this->boot->profileDiscovery()->profileExists($profile)) {
            return $currentRole;
        }

        $profilePath = $this->boot->profileDiscovery()->getProfilePath($profile);
        $preferences = ProfilePreferences::fromProfilePath($profilePath);
        if ($preferences->isRoleAllowed($currentRole)) {
            return $currentRole;
        }

        $fallbackRole = SystemRole::Orchestrator->value;
        $modelString = $this->boot->roleResolver()->resolve($fallbackRole, $profile);
        $this->storage->updateSessionRole($sessionId, $fallbackRole, $modelString);
        $this->writeInfo(
            $io,
            sprintf('Profile "%s" does not allow role "%s". Reverted session to orchestrator.', $profile, $currentRole),
        );

        return $fallbackRole;
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

        $this->saveSessionFile($arg);
        $io->success('Resumed session: ' . $arg);
        return $arg;
    }

    private function loadOrCreateScopedSession(?SymfonyStyle $io, ?string $profile, string $role): string
    {
        $attachedId = $this->loadAttachedInteractiveSessionIdForScope($profile);
        if ($attachedId !== null) {
            $this->writeInfo($io, $this->attachedScopeMessage($profile, $attachedId));
            return $attachedId;
        }

        $latestId = $profile === null
            ? $this->storage->getLatestInteractiveUnprofiledSessionId()
            : $this->storage->getLatestInteractiveSessionIdForProfile($profile);

        if ($latestId !== null) {
            $this->saveSessionFile($latestId);
            $this->writeInfo($io, $this->latestScopeMessage($profile, $latestId));
            return $latestId;
        }

        $sessionId = $this->createNewSession($role, $profile);
        $this->writeInfo($io, $this->createdScopeMessage($profile, $sessionId));

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
