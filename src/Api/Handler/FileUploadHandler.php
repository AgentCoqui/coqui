<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Content\ContentStore;
use CoquiBot\Coqui\Storage\FileUploadStorage;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use React\Http\Message\Response;

/**
 * Content endpoints for content-addressed blobs (CAP 0.5.0 putContent/getContent).
 *
 * POST   /api/v1/sessions/{id}/files            — upload one blob (multipart OR raw binary)
 * GET    /api/v1/sessions/{id}/files/{ref}      — download a blob by content_ref (Range-aware)
 *
 * Both flow through the content-addressed {@see ContentStore}: an upload returns a
 * typed `content.json` object addressed by the SHA-256 of its bytes, and a
 * download serves the stored bytes by `content_ref`, honoring a `Range` header
 * with a 206 partial response. There is no per-session mutable file collection:
 * content is immutable and deduplicated, referenced by typed message
 * `attachments[]`, so the legacy per-session list/delete surface is gone.
 * {@see FileUploadStorage} is retained only for the upload MIME/size policy.
 */
final readonly class FileUploadHandler
{
    private ContentStore $contentStore;

    public function __construct(
        private SessionStorage $sessionStorage,
        private FileUploadStorage $uploadStorage,
    ) {
        $this->contentStore = new ContentStore($sessionStorage->getPdo());
    }

    /**
     * POST /api/v1/sessions/{id}/files
     *
     * Uploads a single blob and returns a typed `content.json` object addressed
     * by the SHA-256 of its bytes. Accepts either multipart/form-data (the first
     * uploaded file) or a raw binary request body (bytes are the body, MIME is the
     * request Content-Type). Content-addressed: re-uploading identical bytes
     * returns the existing object.
     */
    public function upload(ServerRequestInterface $request, string $id = ''): Response
    {
        $id = $id !== '' ? $id : $this->sessionIdFromPath($request);

        $session = SessionAccess::requireWritableSession($this->sessionStorage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $blob = $this->readBlob($request);
        if ($blob instanceof Response) {
            return $blob;
        }

        [$bytes, $mimeType] = $blob;

        // Preserve the existing size + MIME guards on the content-addressed path.
        if (strlen($bytes) > FileUploadStorage::MAX_FILE_SIZE) {
            return Router::errorResponse(
                ApiErrorCode::PAYLOAD_TOO_LARGE,
                sprintf('Content exceeds maximum size of %d bytes', FileUploadStorage::MAX_FILE_SIZE),
            );
        }

        if (!$this->uploadStorage->isAllowedMimeType($mimeType)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Content type "%s" is not allowed', $mimeType),
            );
        }

        $content = $this->contentStore->store($bytes, $mimeType);

        return Router::jsonResponse($content, 201);
    }

    /**
     * Resolve the request into raw bytes + MIME type for a single-blob upload.
     *
     * Prefers a multipart upload (first file's stream + client media type); falls
     * back to the raw request body with the request Content-Type. Returns an error
     * Response when nothing usable is present or a multipart part carries an error.
     *
     * @return array{0: string, 1: string}|Response
     */
    private function readBlob(ServerRequestInterface $request): array|Response
    {
        $uploadedFiles = $this->flattenUploadedFiles($request->getUploadedFiles());

        if ($uploadedFiles !== []) {
            $uploaded = $uploadedFiles[0];

            if ($uploaded->getError() !== UPLOAD_ERR_OK) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    $this->uploadErrorMessage($uploaded->getError()),
                );
            }

            return [
                (string) $uploaded->getStream(),
                $uploaded->getClientMediaType() ?? 'application/octet-stream',
            ];
        }

        $bytes = (string) $request->getBody();
        if ($bytes === '') {
            return Router::errorResponse(
                ApiErrorCode::MISSING_FIELD,
                'No content uploaded. Send a multipart file or a raw binary body.',
            );
        }

        return [$bytes, $this->normalizeMime($request->getHeaderLine('Content-Type'))];
    }

    /**
     * GET /api/v1/sessions/{id}/files/{ref}
     *
     * Serves a content-addressed blob by `content_ref`. Honors a single
     * `Range: bytes=a-b` header with a 206 partial response (Content-Range +
     * Accept-Ranges); otherwise returns the full blob with a 200. A missing ref
     * is a `content_not_found` (404).
     */
    public function get(ServerRequestInterface $request, string $id = '', string $fileId = ''): Response
    {
        $id = $id !== '' ? $id : $this->sessionIdFromPath($request);
        $fileId = $fileId !== '' ? $fileId : $this->contentRefFromPath($request);

        $session = SessionAccess::requireReadableSession($this->sessionStorage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $content = $this->contentStore->get($fileId);
        $bytes = $content !== null ? $this->contentStore->readBytes($fileId) : null;

        if ($content === null || $bytes === null) {
            return Router::errorResponse(ApiErrorCode::CONTENT_NOT_FOUND, 'Content not found');
        }

        $total = strlen($bytes);
        $headers = [
            'Content-Type' => $content['mime_type'],
            'Accept-Ranges' => 'bytes',
        ];

        $range = $this->parseRange($request->getHeaderLine('Range'), $total);

        if ($range === null) {
            $headers['Content-Length'] = (string) $total;

            return new Response(200, $headers, $bytes);
        }

        [$start, $end] = $range;
        $slice = substr($bytes, $start, $end - $start + 1);

        $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $total);
        $headers['Content-Length'] = (string) strlen($slice);

        return new Response(206, $headers, $slice);
    }

    /**
     * Parse a single `bytes=a-b` Range header against a known total length.
     *
     * `b` is optional (defaults to the last byte). Returns the inclusive
     * [start, end] byte offsets, or null when the header is absent, malformed,
     * multi-range, or unsatisfiable (so the caller serves the full blob).
     *
     * @return array{0: int, 1: int}|null
     */
    private function parseRange(string $header, int $total): ?array
    {
        if ($header === '' || $total === 0) {
            return null;
        }

        if (preg_match('/^bytes=(\d+)-(\d*)$/', trim($header), $m) !== 1) {
            return null;
        }

        $start = (int) $m[1];
        $end = $m[2] === '' ? $total - 1 : (int) $m[2];

        if ($start > $end || $start >= $total) {
            return null;
        }

        return [$start, min($end, $total - 1)];
    }

    /**
     * Extract the session id from a `/sessions/{id}/files...` request path.
     */
    private function sessionIdFromPath(ServerRequestInterface $request): string
    {
        return preg_match('#/sessions/([^/]+)/files#', $request->getUri()->getPath(), $m) === 1
            ? $m[1]
            : '';
    }

    /**
     * Extract the content ref from a `/sessions/{id}/files/{ref}` request path.
     */
    private function contentRefFromPath(ServerRequestInterface $request): string
    {
        return preg_match('#/sessions/[^/]+/files/([^/]+)#', $request->getUri()->getPath(), $m) === 1
            ? $m[1]
            : '';
    }

    /**
     * Reduce a Content-Type header to its bare media type (drop any parameters).
     */
    private function normalizeMime(string $contentType): string
    {
        $mime = trim(explode(';', $contentType)[0]);

        return $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    /**
     * Flatten the nested uploaded files structure into a flat array.
     *
     * ReactPHP's multipart parser produces a nested structure keyed by field name.
     * This method handles both `files[]` array fields and single `file` fields.
     *
     * @param array<string, mixed> $uploadedFiles
     * @return UploadedFileInterface[]
     */
    private function flattenUploadedFiles(array $uploadedFiles): array
    {
        $flat = [];

        foreach ($uploadedFiles as $field) {
            if ($field instanceof UploadedFileInterface) {
                $flat[] = $field;
            } elseif (is_array($field)) {
                foreach ($field as $file) {
                    if ($file instanceof UploadedFileInterface) {
                        $flat[] = $file;
                    }
                }
            }
        }

        return $flat;
    }

    /**
     * Map PHP upload error codes to human-readable messages.
     */
    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension',
            default => 'Unknown upload error',
        };
    }
}
