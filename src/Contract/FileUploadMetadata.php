<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Immutable value object representing metadata for an uploaded file.
 */
final readonly class FileUploadMetadata implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $originalName,
        public string $mimeType,
        public int $size,
        public bool $isImage,
        public string $storedPath,
        public string $createdAt,
    ) {}

    /**
     * @return array{id: string, original_name: string, mime_type: string, size: int, is_image: bool, created_at: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->originalName,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'is_image' => $this->isImage,
            'created_at' => $this->createdAt,
        ];
    }
}
