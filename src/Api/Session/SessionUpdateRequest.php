<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

/**
 * A resolved CAP 0.5.0 session PATCH.
 *
 * Carries only the editable `session-patch.json` field set (title, pinned,
 * status, model, workspace). Each field pairs an `updates*` presence flag with
 * its value so an omitted key (leave untouched) is distinguishable from an
 * explicit null (clear ⇒ inherit for model, no rooted workspace for workspace).
 */
final readonly class SessionUpdateRequest
{
    public function __construct(
        public bool $updatesTitle = false,
        public ?string $title = null,
        public bool $updatesModel = false,
        public ?string $model = null,
        public bool $updatesWorkspace = false,
        public ?string $workspace = null,
        public bool $updatesPinned = false,
        public ?bool $pinned = null,
        public bool $updatesStatus = false,
        public ?string $status = null,
    ) {}
}
