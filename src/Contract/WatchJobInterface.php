<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A pluggable watch job for the WorkspaceWatcher.
 *
 * Implementations scan a specific workspace subdirectory and reconcile
 * detected changes (adds, modifications, removals) into the appropriate
 * storage backend.
 */
interface WatchJobInterface
{
    /**
     * Perform a full scan and reconcile changes.
     *
     * Called by WorkspaceWatcher on each tick. Implementations should:
     *  1. Scan their target directory for current files
     *  2. Compare against previously known state (mtime, hash, etc.)
     *  3. Process adds, modifications, and removals
     *  4. Return a summary of what changed
     *
     * @return WatchJobResult Summary of changes detected and applied
     */
    public function scan(): WatchJobResult;

    /**
     * Human-readable name for logging and diagnostics.
     */
    public function name(): string;
}
