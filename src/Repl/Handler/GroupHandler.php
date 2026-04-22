<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Exception\GroupSessionException;
use CoquiBot\Coqui\Repl\RouteResult;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\GroupSessionService;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class GroupHandler
{
    public function __construct(
        private GroupSessionService $groupSessions,
        private SessionStorage $storage,
    ) {}

    public function handle(SymfonyStyle $io, string $arg, string $sessionId): RouteResult
    {
        $trimmedArg = trim($arg);
        if ($trimmedArg === '') {
            return $this->handleStatusOrHelp($io, $sessionId);
        }

        $parts = preg_split('/\s+/', $trimmedArg, 2) ?: [];
        $action = strtolower($parts[0] ?? '');
        $rest = trim($parts[1] ?? '');

        return match ($action) {
            'help' => $this->showHelp($io),
            'status', 'members' => $this->showStatus($io, $sessionId),
            'start' => $this->handleStart($io, $rest),
            'add' => $this->handleAdd($io, $sessionId, $rest),
            'remove' => $this->handleRemove($io, $sessionId, $rest),
            'replace' => $this->handleReplace($io, $sessionId, $rest),
            'rounds' => $this->handleRounds($io, $sessionId, $rest),
            default => $this->handleUnknown($io, $action),
        };
    }

    private function handleStatusOrHelp(SymfonyStyle $io, string $sessionId): RouteResult
    {
        if ($this->storage->isGroupSession($sessionId)) {
            return $this->showStatus($io, $sessionId);
        }

        return $this->showHelp($io);
    }

    private function handleStart(SymfonyStyle $io, string $arg): RouteResult
    {
        [$membersInput, $roundsInput] = $this->parseMembersAndRounds($arg);

        try {
            $members = $this->groupSessions->normalizeMembers($membersInput);
            $result = $this->groupSessions->resolveOrCreateSession(
                modelRole: SystemRole::Orchestrator->value,
                members: $members,
                groupMaxRounds: $this->groupSessions->resolveMaxRounds($roundsInput),
            );
        } catch (GroupSessionException $e) {
            return $this->renderError($io, $e);
        }

        $io->success(sprintf(
            '%s group session %s with %s. Prompts without @mentions fan out to everyone; use @name to narrow or @everyone/@group to broadcast.',
            $result->created ? 'Started' : 'Resumed',
            $this->shortSessionId((string) $result->session['id']),
            $this->formatMembers($this->extractSessionMembers($result->session)),
        ));

        return RouteResult::stateChange(
            newActiveRole: SystemRole::Orchestrator->value,
            newSessionId: (string) $result->session['id'],
        );
    }

    private function handleAdd(SymfonyStyle $io, string $sessionId, string $arg): RouteResult
    {
        $groupSession = $this->requireCurrentGroupSession($io, $sessionId);
        if ($groupSession === null) {
            return RouteResult::continue();
        }

        $profileInput = trim($arg);

        try {
            $profile = $this->groupSessions->normalizeMember($profileInput);
            $result = $this->groupSessions->addSessionMember(
                sessionId: $sessionId,
                profile: $profile,
                confirmCloseActive: false,
                groupMaxRounds: $this->currentGroupMaxRounds($groupSession),
                closureReasonPrefix: 'repl_group_membership_update',
            );
        } catch (GroupSessionException $e) {
            if ($e->errorCode === ApiErrorCode::GROUP_SESSION_ACTIVE && $this->confirmGroupConflict($io, $e)) {
                try {
                    $result = $this->groupSessions->addSessionMember(
                        sessionId: $sessionId,
                        profile: $this->groupSessions->normalizeMember($profileInput),
                        confirmCloseActive: true,
                        groupMaxRounds: $this->currentGroupMaxRounds($groupSession),
                        closureReasonPrefix: 'repl_group_membership_update',
                    );
                } catch (GroupSessionException $retryError) {
                    return $this->renderError($io, $retryError);
                }
            } else {
                return $this->renderError($io, $e, cancelledText: 'Kept the current group membership.');
            }
        }

        $io->success(sprintf('Added @%s. Members: %s.', trim($profileInput), $this->formatMembers($this->extractSessionMembers($result->session))));

        return RouteResult::continue();
    }

    private function handleRemove(SymfonyStyle $io, string $sessionId, string $arg): RouteResult
    {
        $groupSession = $this->requireCurrentGroupSession($io, $sessionId);
        if ($groupSession === null) {
            return RouteResult::continue();
        }

        $profileInput = trim($arg);

        try {
            $profile = $this->groupSessions->normalizeMember($profileInput);
            $result = $this->groupSessions->removeSessionMember(
                sessionId: $sessionId,
                profile: $profile,
                confirmCloseActive: false,
                groupMaxRounds: $this->currentGroupMaxRounds($groupSession),
                closureReasonPrefix: 'repl_group_membership_update',
            );
        } catch (GroupSessionException $e) {
            if ($e->errorCode === ApiErrorCode::GROUP_SESSION_ACTIVE && $this->confirmGroupConflict($io, $e)) {
                try {
                    $result = $this->groupSessions->removeSessionMember(
                        sessionId: $sessionId,
                        profile: $this->groupSessions->normalizeMember($profileInput),
                        confirmCloseActive: true,
                        groupMaxRounds: $this->currentGroupMaxRounds($groupSession),
                        closureReasonPrefix: 'repl_group_membership_update',
                    );
                } catch (GroupSessionException $retryError) {
                    return $this->renderError($io, $retryError);
                }
            } else {
                return $this->renderError($io, $e, cancelledText: 'Kept the current group membership.');
            }
        }

        $io->success(sprintf('Removed @%s. Members: %s.', trim($profileInput), $this->formatMembers($this->extractSessionMembers($result->session))));

        return RouteResult::continue();
    }

    private function handleReplace(SymfonyStyle $io, string $sessionId, string $arg): RouteResult
    {
        $groupSession = $this->requireCurrentGroupSession($io, $sessionId);
        if ($groupSession === null) {
            return RouteResult::continue();
        }

        [$membersInput, $roundsInput] = $this->parseMembersAndRounds($arg);

        try {
            $members = $this->groupSessions->normalizeMembers($membersInput);
            $result = $this->groupSessions->replaceSessionMembers(
                sessionId: $sessionId,
                members: $members,
                groupMaxRounds: $roundsInput !== null
                    ? $this->groupSessions->resolveMaxRounds($roundsInput)
                    : $this->currentGroupMaxRounds($groupSession),
                confirmCloseActive: false,
                closureReasonPrefix: 'repl_group_membership_update',
            );
        } catch (GroupSessionException $e) {
            if ($e->errorCode === ApiErrorCode::GROUP_SESSION_ACTIVE && $this->confirmGroupConflict($io, $e)) {
                try {
                    $result = $this->groupSessions->replaceSessionMembers(
                        sessionId: $sessionId,
                        members: $this->groupSessions->normalizeMembers($membersInput),
                        groupMaxRounds: $roundsInput !== null
                            ? $this->groupSessions->resolveMaxRounds($roundsInput)
                            : $this->currentGroupMaxRounds($groupSession),
                        confirmCloseActive: true,
                        closureReasonPrefix: 'repl_group_membership_update',
                    );
                } catch (GroupSessionException $retryError) {
                    return $this->renderError($io, $retryError);
                }
            } else {
                return $this->renderError($io, $e, cancelledText: 'Kept the current group membership.');
            }
        }

        $io->success(sprintf('Updated members: %s.', $this->formatMembers($this->extractSessionMembers($result->session))));

        return RouteResult::continue();
    }

    private function handleRounds(SymfonyStyle $io, string $sessionId, string $arg): RouteResult
    {
        if ($this->requireCurrentGroupSession($io, $sessionId) === null) {
            return RouteResult::continue();
        }

        try {
            $result = $this->groupSessions->updateSessionMaxRounds($sessionId, trim($arg));
        } catch (GroupSessionException $e) {
            return $this->renderError($io, $e);
        }

        $io->success(sprintf('Group max rounds set to %d.', $this->currentGroupMaxRounds($result->session)));

        return RouteResult::continue();
    }

    private function showStatus(SymfonyStyle $io, string $sessionId): RouteResult
    {
        $groupSession = $this->requireCurrentGroupSession($io, $sessionId);
        if ($groupSession === null) {
            return $this->showHelp($io);
        }

        $io->section('Group Session');
        $io->definitionList(
            ['Session' => (string) $groupSession['id']],
            ['Members' => $this->formatMembers($this->extractSessionMembers($groupSession))],
            ['Max rounds' => (string) $this->currentGroupMaxRounds($groupSession)],
            ['Model role' => (string) ($groupSession['model_role'] ?? SystemRole::Orchestrator->value)],
            ['Reply routing' => 'All members respond by default; use @name to narrow or @everyone/@group to broadcast'],
        );

        return RouteResult::continue();
    }

    private function showHelp(SymfonyStyle $io): RouteResult
    {
        $io->text([
            '<fg=cyan>Group sessions</>',
            '',
            '  /group status',
            '  /group start <member1,member2,...> [--rounds=3]',
            '  /group add <profile>',
            '  /group remove <profile>',
            '  /group replace <member1,member2,...> [--rounds=3]',
            '  /group rounds <n>',
            '',
            'Group sessions stay orchestrator-managed and clear any single active profile scope.',
            'General prompts fan out to all members in stored order unless you narrow them with @name.',
            'Use @everyone or @group in a prompt to force a full-team response.',
        ]);

        return RouteResult::continue();
    }

    private function handleUnknown(SymfonyStyle $io, string $action): RouteResult
    {
        $io->error(sprintf('Unknown /group subcommand: %s', $action));

        return $this->showHelp($io);
    }

    /**
     * @return array{0: list<string>, 1: ?string}
     */
    private function parseMembersAndRounds(string $arg): array
    {
        $tokens = preg_split('/\s+/', trim($arg)) ?: [];
        $members = [];
        $rounds = null;

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (str_starts_with($token, '--rounds=')) {
                $rounds = substr($token, strlen('--rounds='));
                continue;
            }

            foreach (preg_split('/,/', $token) ?: [] as $member) {
                $member = trim($member);
                if ($member !== '') {
                    $members[] = $member;
                }
            }
        }

        return [$members, $rounds];
    }

    private function confirmGroupConflict(SymfonyStyle $io, GroupSessionException $e): bool
    {
        $activeSessionId = is_array($e->details) && is_string($e->details['active_session_id'] ?? null)
            ? $e->details['active_session_id']
            : null;

        return $io->confirm(
            sprintf(
                'Close the other active group session%s and continue?',
                $activeSessionId !== null ? ' (' . $this->shortSessionId($activeSessionId) . ')' : '',
            ),
            false,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requireCurrentGroupSession(SymfonyStyle $io, string $sessionId): ?array
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null || SessionType::fromSessionRow($session) !== SessionType::Group) {
            $io->warning('Current session is not a group session. Use /group start <members...> first.');
            return null;
        }

        return $session;
    }

    /**
     * @param array<string, mixed> $session
     * @return list<string>
     */
    private function extractSessionMembers(array $session): array
    {
        $members = $session['group_members'] ?? null;
        if (!is_array($members)) {
            return [];
        }

        return array_values(array_map(
            static fn(array $member): string => (string) ($member['profile'] ?? ''),
            array_filter($members, static fn(mixed $member): bool => is_array($member)),
        ));
    }

    /**
     * @param list<string> $members
     */
    private function formatMembers(array $members): string
    {
        return implode(', ', array_map(static fn(string $member): string => '@' . $member, $members));
    }

    /**
     * @param array<string, mixed> $session
     */
    private function currentGroupMaxRounds(array $session): int
    {
        return is_int($session['group_max_rounds'] ?? null)
            ? $session['group_max_rounds']
            : GroupSessionService::DEFAULT_MAX_ROUNDS;
    }

    private function shortSessionId(string $sessionId): string
    {
        return substr($sessionId, 0, 8) . '...';
    }

    private function renderError(SymfonyStyle $io, GroupSessionException $e, ?string $cancelledText = null): RouteResult
    {
        if ($e->errorCode === ApiErrorCode::GROUP_SESSION_ACTIVE && $cancelledText !== null) {
            $io->text('<fg=gray>' . $cancelledText . '</>');
            return RouteResult::continue();
        }

        $io->error($e->getMessage());

        return RouteResult::continue();
    }
}