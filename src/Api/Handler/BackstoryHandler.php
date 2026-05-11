<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Backstory\BackstoryAssembler;
use CoquiBot\Coqui\Backstory\BackstoryInspectionService;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorFactory;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Support\FileSystemOperations;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Backstory inspection and profile-scoped source management endpoints.
 */
final readonly class BackstoryHandler
{
    public function __construct(
        private BackstoryInspectionService $inspectionService,
        private ProfileDiscovery $profileDiscovery,
        private string $workspacePath,
        private ?BackstoryAssembler $assembler = null,
        private ?ExtractorFactory $extractorFactory = null,
    ) {}

    public function get(ServerRequestInterface $request): Response
    {
        try {
            $params = $request->getQueryParams();
            $profile = isset($params['profile']) && is_string($params['profile']) && $params['profile'] !== ''
                ? $params['profile']
                : null;

            return Router::jsonResponse($this->inspectionService->inspect($profile));
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to inspect backstory: ' . $e->getMessage(),
            );
        }
    }

    public function getProfile(ServerRequestInterface $request, string $name): Response
    {
        try {
            return Router::jsonResponse($this->inspectionService->inspect($name));
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to inspect backstory: ' . $e->getMessage(),
            );
        }
    }

    public function getEntry(ServerRequestInterface $request, string $name): Response
    {
        try {
            $profile = $this->profile($name);
            $params = $request->getQueryParams();
            $entryPath = $this->normalizeBackstoryEntryPath($params['path'] ?? null);

            if ($entryPath === null) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'path is required and must target a supported backstory source file.',
                );
            }

            $relativePath = $this->relativeBackstoryEntry($profile['name'], $entryPath);
            if (!$this->operations()->exists($relativePath)) {
                return Router::errorResponse(ApiErrorCode::NOT_FOUND, sprintf('Backstory entry "%s" not found.', $entryPath));
            }

            $absolutePath = $this->workspacePath . '/' . $relativePath;
            $content = file_get_contents($absolutePath);
            if ($content === false) {
                return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to read backstory entry content.');
            }

            return Router::jsonResponse([
                'path' => $this->workspaceBackstoryPath($profile['name'], $entryPath),
                'relative_path' => $entryPath,
                'content' => $content,
            ]);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to read backstory entry: ' . $e->getMessage(),
            );
        }
    }

    public function createFolder(ServerRequestInterface $request, string $name): Response
    {
        try {
            $profile = $this->profile($name);
            $body = $this->requestBody($request);
            $folderPath = $this->normalizeBackstoryDirectoryPath($body['path'] ?? null);

            if ($folderPath === null) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'path is required and must be a non-hidden relative backstory folder path.',
                );
            }

            $this->operations()->createDir($this->relativeBackstoryDirectory($profile['name'], $folderPath));

            return Router::jsonResponse([
                'created' => true,
                'path' => $this->workspaceBackstoryPath($profile['name'], $folderPath),
                'backstory' => $this->inspectionService->inspect($profile['name']),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to create backstory folder: ' . $e->getMessage(),
            );
        }
    }

    public function putEntry(ServerRequestInterface $request, string $name): Response
    {
        try {
            $profile = $this->profile($name);
            $body = $this->requestBody($request);
            $entryPath = $this->normalizeBackstoryEntryPath($body['path'] ?? null);

            if ($entryPath === null) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'path is required and must target a supported backstory source file.',
                );
            }

            $content = $body['content'] ?? null;
            if (!is_string($content) || trim($content) === '') {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'content is required and must be a non-empty string.',
                );
            }

            $this->operations()->write(
                $this->relativeBackstoryEntry($profile['name'], $entryPath),
                rtrim($content) . "\n",
            );
            $this->assembler()->generate($profile['path']);

            return Router::jsonResponse([
                'updated' => true,
                'path' => $this->workspaceBackstoryPath($profile['name'], $entryPath),
                'backstory' => $this->inspectionService->inspect($profile['name']),
            ]);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to update backstory entry: ' . $e->getMessage(),
            );
        }
    }

    public function deleteEntry(ServerRequestInterface $request, string $name): Response
    {
        try {
            $profile = $this->profile($name);
            $body = $this->requestBody($request);
            $entryPath = $this->normalizeBackstoryEntryPath($body['path'] ?? null);

            if ($entryPath === null) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'path is required and must target a supported backstory source file.',
                );
            }

            $relativePath = $this->relativeBackstoryEntry($profile['name'], $entryPath);
            if (!$this->operations()->exists($relativePath)) {
                return Router::errorResponse(ApiErrorCode::NOT_FOUND, sprintf('Backstory entry "%s" not found.', $entryPath));
            }

            $this->operations()->delete($relativePath);
            $this->assembler()->generate($profile['path']);

            return Router::jsonResponse([
                'deleted' => true,
                'path' => $this->workspaceBackstoryPath($profile['name'], $entryPath),
                'backstory' => $this->inspectionService->inspect($profile['name']),
            ]);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to delete backstory entry: ' . $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function profile(string $name): array
    {
        $normalizedName = strtolower(trim($name));
        $profile = $this->profileDiscovery->discoverAll()[$normalizedName] ?? null;

        if ($profile === null) {
            throw new \InvalidArgumentException(sprintf('Unknown profile "%s".', $name));
        }

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(ServerRequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Request body must be a JSON object.');
        }

        return $decoded;
    }

    private function normalizeBackstoryDirectoryPath(mixed $value): ?string
    {
        return $this->normalizeBackstoryPath($value, requireSupportedExtension: false);
    }

    private function normalizeBackstoryEntryPath(mixed $value): ?string
    {
        $normalized = $this->normalizeBackstoryPath($value, requireSupportedExtension: true);
        if ($normalized === null) {
            return null;
        }

        $extension = strtolower((string) pathinfo($normalized, PATHINFO_EXTENSION));

        return $this->extractorFactory()->isSupported($extension)
            ? $normalized
            : null;
    }

    private function normalizeBackstoryPath(mixed $value, bool $requireSupportedExtension): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim(str_replace('\\', '/', $value));
        $normalized = trim($normalized, '/');

        if ($normalized === '' || str_contains($normalized, "\0")) {
            return null;
        }

        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                return null;
            }
        }

        $extension = pathinfo($normalized, PATHINFO_EXTENSION);
        if ($requireSupportedExtension && $extension === '') {
            return null;
        }

        if (!$requireSupportedExtension && $extension !== '') {
            return null;
        }

        return $normalized;
    }

    private function relativeBackstoryDirectory(string $profileName, string $relativePath): string
    {
        return 'profiles/' . $profileName . '/backstory/' . $relativePath;
    }

    private function relativeBackstoryEntry(string $profileName, string $relativePath): string
    {
        return $this->relativeBackstoryDirectory($profileName, $relativePath);
    }

    private function workspaceBackstoryPath(string $profileName, string $relativePath): string
    {
        return 'profiles/' . $profileName . '/backstory/' . $relativePath;
    }

    private function operations(): FileSystemOperations
    {
        return new FileSystemOperations($this->workspacePath);
    }

    private function assembler(): BackstoryAssembler
    {
        return $this->assembler ?? new BackstoryAssembler();
    }

    private function extractorFactory(): ExtractorFactory
    {
        return $this->extractorFactory ?? new ExtractorFactory();
    }
}