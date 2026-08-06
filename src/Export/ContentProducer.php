<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Export;

use CoquiBot\Coqui\Content\ContentStore;

/**
 * Projects a coqui `content` row to a CAP 0.5.0 `content.json` wire object.
 *
 * The projection is exactly {@see ContentStore::toWire()} — the five
 * schema-declared properties, `bytes` omitted (the export envelope carries only
 * the content-addressed metadata, not the payload). Kept as a sibling of the
 * other export producers so the collection map has a uniform producer surface.
 */
final class ContentProducer
{
    /**
     * @param array<string, mixed> $row A `content` row (or a content.json wire object).
     * @return array{content_ref: string, mime_type: string, size: int, sha256: string, created_at: string}
     */
    public static function toWire(array $row): array
    {
        return ContentStore::toWire($row);
    }
}
