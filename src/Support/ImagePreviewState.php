<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

final class ImagePreviewState
{
    private bool $consumed = false;

    public function reset(): void
    {
        $this->consumed = false;
    }

    public function hasRenderedPreview(): bool
    {
        return $this->consumed;
    }

    public function consume(): bool
    {
        if ($this->consumed) {
            return false;
        }

        $this->consumed = true;

        return true;
    }
}