<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Applies a resolved CAP 0.5.0 session PATCH to storage.
 *
 * Shared by the interactive and group session type handlers so the write path is
 * identical: each present field is written through its dedicated storage mutator,
 * and — if any field was applied — the optimistic-concurrency `version` token is
 * bumped exactly once so a single PATCH advances the version by exactly one.
 */
final class SessionPatchApplier
{
    public static function apply(SessionStorage $storage, string $sessionId, SessionUpdateRequest $request): void
    {
        $applied = false;

        if ($request->updatesTitle && $request->title !== null) {
            $storage->updateSessionTitle($sessionId, $request->title);
            $applied = true;
        }

        if ($request->updatesModel) {
            $storage->updateSessionModelDirect($sessionId, $request->model);
            $applied = true;
        }

        if ($request->updatesWorkspace) {
            $storage->updateSessionWorkspace($sessionId, $request->workspace);
            $applied = true;
        }

        if ($request->updatesPinned && $request->pinned !== null) {
            $storage->setSessionPinned($sessionId, $request->pinned);
            $applied = true;
        }

        if ($request->updatesStatus && $request->status !== null) {
            $storage->setSessionStatus($sessionId, $request->status);
            $applied = true;
        }

        if ($applied) {
            $storage->bumpSessionVersion($sessionId);
        }
    }
}
