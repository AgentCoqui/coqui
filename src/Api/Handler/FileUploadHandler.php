<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Storage\FileUploadStorage;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use React\Http\Message\Response;

/**
 * File upload endpoints for session-scoped file management.
 *
 * POST   /api/v1/sessions/{id}/files            — upload files (multipart/form-data)
 * GET    /api/v1/sessions/{id}/files            — list uploaded files
 * GET    /api/v1/sessions/{id}/files/{fileId}   — download a file
 * DELETE /api/v1/sessions/{id}/files/{fileId}   — delete a file
 */
final readonly class FileUploadHandler
{
    public function __construct(
        private SessionStorage $sessionStorage,
        private FileUploadStorage $uploadStorage,
    ) {}

    /**
     * POST /api/v1/sessions/{id}/files
     *
     * Accepts multipart/form-data with one or more files in the "files[]" field.
     */
    public function upload(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireWritableSession($this->sessionStorage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $uploadedFiles = $this->flattenUploadedFiles($request->getUploadedFiles());

        if ($uploadedFiles === []) {
            return Router::errorResponse(
                ApiErrorCode::MISSING_FIELD,
                'No files uploaded. Send files as multipart/form-data with field name "files[]"',
            );
        }

        if (count($uploadedFiles) > FileUploadStorage::MAX_FILES_PER_REQUEST) {
            return Router::errorResponse(
                ApiErrorCode::PAYLOAD_TOO_LARGE,
                sprintf('Maximum %d files per request', FileUploadStorage::MAX_FILES_PER_REQUEST),
            );
        }

        $results = [];
        $errors = [];

        foreach ($uploadedFiles as $uploaded) {
            // Check for upload errors
            if ($uploaded->getError() !== UPLOAD_ERR_OK) {
                $errors[] = [
                    'file' => $uploaded->getClientFilename() ?? 'unknown',
                    'error' => $this->uploadErrorMessage($uploaded->getError()),
                ];

                continue;
            }

            // Validate file size
            $size = $uploaded->getSize();
            if ($size !== null && $size > FileUploadStorage::MAX_FILE_SIZE) {
                $errors[] = [
                    'file' => $uploaded->getClientFilename() ?? 'unknown',
                    'error' => sprintf('File exceeds maximum size of %d bytes', FileUploadStorage::MAX_FILE_SIZE),
                ];

                continue;
            }

            // Validate MIME type
            $mimeType = $uploaded->getClientMediaType() ?? 'application/octet-stream';
            if (!$this->uploadStorage->isAllowedMimeType($mimeType)) {
                $errors[] = [
                    'file' => $uploaded->getClientFilename() ?? 'unknown',
                    'error' => sprintf('File type "%s" is not allowed', $mimeType),
                ];

                continue;
            }

            try {
                $contents = (string) $uploaded->getStream();
                $originalName = $uploaded->getClientFilename() ?? 'upload';

                $metadata = $this->uploadStorage->store($id, $contents, $originalName, $mimeType);
                $results[] = $metadata;
            } catch (\Throwable $e) {
                $errors[] = [
                    'file' => $uploaded->getClientFilename() ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $response = [
            'session_id' => $id,
            'files' => $results,
            'count' => count($results),
        ];

        if ($errors !== []) {
            $response['errors'] = $errors;
        }

        $status = $results !== [] ? 201 : 400;

        return Router::jsonResponse($response, $status);
    }

    /**
     * GET /api/v1/sessions/{id}/files
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->sessionStorage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $files = $this->uploadStorage->list($id);

        return Router::jsonResponse([
            'session_id' => $id,
            'files' => $files,
            'count' => count($files),
        ]);
    }

    /**
     * GET /api/v1/sessions/{id}/files/{fileId}
     *
     * Returns the raw file content with appropriate Content-Type header.
     */
    public function get(ServerRequestInterface $request, string $id, string $fileId): Response
    {
        $session = SessionAccess::requireReadableSession($this->sessionStorage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $metadata = $this->uploadStorage->get($id, $fileId);

        if ($metadata === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'File not found');
        }

        $filePath = $this->uploadStorage->getFilePath($id, $fileId);

        if ($filePath === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'File not found on disk');
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to read file');
        }

        return new Response(
            200,
            [
                'Content-Type' => $metadata->mimeType,
                'Content-Length' => (string) $metadata->size,
                'Content-Disposition' => sprintf(
                    'inline; filename="%s"',
                    addslashes($metadata->originalName),
                ),
            ],
            $contents,
        );
    }

    /**
     * DELETE /api/v1/sessions/{id}/files/{fileId}
     */
    public function delete(ServerRequestInterface $request, string $id, string $fileId): Response
    {
        $session = SessionAccess::requireWritableSession($this->sessionStorage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $deleted = $this->uploadStorage->delete($id, $fileId);

        if (!$deleted) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'File not found');
        }

        return Router::jsonResponse(['deleted' => true]);
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
