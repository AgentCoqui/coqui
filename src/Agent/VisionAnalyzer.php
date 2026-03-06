<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Analyzes images using a vision-capable model and returns a structured description.
 *
 * Mirrors TitleGenerator — a single-shot, non-blocking child agent that reads
 * the 'vision' role instructions and delegates to a vision model. Accepts file
 * paths, URLs, and base64 data URIs.
 *
 * URL images are pre-downloaded and base64-encoded before being sent to the
 * provider. This ensures all providers (including Gemini and Ollama, which
 * don't support URL references) receive the actual image data.
 */
final class VisionAnalyzer
{
    private const string ROLE = 'vision';

    private const string FALLBACK_INSTRUCTIONS = <<<'PROMPT'
        Analyze the provided image and return a detailed description covering:
        subject, notable details, context, any text content, and technical observations.
        Return ONLY the analysis — no preamble, no closing remarks.
        PROMPT;

    /** MIME types accepted as valid image responses. */
    private const array ACCEPTED_IMAGE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/tiff',
        'image/svg+xml',
    ];

    public function __construct(
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly ?RoleDiscovery $roleDiscovery = null,
        private readonly ?ProviderFactory $providerFactory = null,
    ) {}

    /**
     * Analyze an image and return a natural-language description.
     *
     * Returns the analysis text on success, or an error string prefixed with
     * "Error: " on failure. Callers can check for the prefix to distinguish
     * success from failure while still surfacing diagnostic information.
     *
     * @param string $imageSource File path, URL, or data URI (data:image/...;base64,...)
     * @param string $prompt Optional prompt to guide the analysis
     * @return string Analysis text on success, or an error string prefixed with "Error: " on failure
     */
    public function analyze(string $imageSource, string $prompt = 'Analyze this image.'): string
    {
        try {
            $instructions = $this->resolveInstructions();
            $provider = $this->resolveProvider();

            $content = $this->buildContent($imageSource, $prompt);
            if ($content === null) {
                return $this->buildErrorMessage($imageSource);
            }

            $response = $provider->chat([
                new SystemMessage($instructions),
                new UserMessage($content),
            ]);

            $result = trim($response->content);

            return $result !== '' ? $result : 'Error: Vision model returned an empty response.';
        } catch (\Throwable $e) {
            return 'Error: Vision analysis failed — ' . $e->getMessage();
        }
    }

    /**
     * Build multimodal content from the image source.
     *
     * All image sources are normalized to base64 data URIs before being sent
     * to the provider. This ensures universal provider compatibility:
     * - Data URIs: passed through as-is
     * - URLs: downloaded, MIME type detected from Content-Type header, base64-encoded
     * - File paths: read from disk, MIME type detected, base64-encoded
     *
     * @return array<array{type: string, text?: string, image_url?: array<string, mixed>}>|null
     */
    private function buildContent(string $imageSource, string $prompt): ?array
    {
        $content = [['type' => 'text', 'text' => $prompt]];

        // Data URI — pass through directly
        if (str_starts_with($imageSource, 'data:')) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $imageSource],
            ];

            return $content;
        }

        // URL — download and convert to base64 data URI for universal provider support
        if (str_starts_with($imageSource, 'http://') || str_starts_with($imageSource, 'https://')) {
            $dataUri = $this->downloadImageToDataUri($imageSource);
            if ($dataUri === null) {
                return null;
            }

            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $dataUri],
            ];

            return $content;
        }

        // File path — read, encode, and build data URI
        if (!file_exists($imageSource)) {
            return null;
        }

        $fileContent = file_get_contents($imageSource);
        if ($fileContent === false) {
            return null;
        }

        $base64 = base64_encode($fileContent);
        $mime = mime_content_type($imageSource) ?: 'image/png';

        $content[] = [
            'type' => 'image_url',
            'image_url' => ['url' => "data:{$mime};base64,{$base64}"],
        ];

        return $content;
    }

    /**
     * Download an image URL and convert it to a base64 data URI.
     *
     * Uses Symfony HttpClient for the download. Validates the response
     * Content-Type to ensure it's an image before encoding.
     *
     * @return string|null Data URI string (data:{mime};base64,{data}), or null on failure
     */
    private function downloadImageToDataUri(string $url): ?string
    {
        try {
            $client = HttpClient::create([
                'timeout' => 30,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => 'CoquiBot/1.0 (Vision Analyzer)',
                    'Accept' => 'image/*',
                ],
            ]);

            $response = $client->request('GET', $url);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                return null;
            }

            $contentType = $response->getHeaders()['content-type'][0] ?? '';
            // Extract MIME type (strip charset and other parameters)
            $mime = trim(explode(';', $contentType)[0]);

            // Validate it's an image MIME type
            if (!$this->isAcceptedImageType($mime)) {
                // Try to detect from URL extension as fallback
                $mime = $this->mimeFromExtension($url);
                if ($mime === null) {
                    return null;
                }
            }

            $body = $response->getContent();
            if ($body === '') {
                return null;
            }

            $base64 = base64_encode($body);

            return "data:{$mime};base64,{$base64}";
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check if a MIME type is an accepted image type.
     */
    private function isAcceptedImageType(string $mime): bool
    {
        return in_array(strtolower($mime), self::ACCEPTED_IMAGE_TYPES, true);
    }

    /**
     * Detect MIME type from a URL's file extension.
     */
    private function mimeFromExtension(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === false) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'tiff', 'tif' => 'image/tiff',
            'svg' => 'image/svg+xml',
            default => null,
        };
    }

    /**
     * Build a descriptive error message when image content cannot be loaded.
     */
    private function buildErrorMessage(string $imageSource): string
    {
        if (str_starts_with($imageSource, 'http://') || str_starts_with($imageSource, 'https://')) {
            return "Error: Failed to download image from URL: {$imageSource}. "
                . 'The server may be unreachable, returned a non-image response, or the request timed out.';
        }

        if (!file_exists($imageSource)) {
            return "Error: Image file not found: {$imageSource}. "
                . 'Provide an absolute file path, a URL (http/https), or a base64 data URI.';
        }

        return "Error: Failed to read image file: {$imageSource}.";
    }

    private function resolveInstructions(): string
    {
        if ($this->roleDiscovery !== null) {
            try {
                return $this->roleDiscovery->readInstructions(self::ROLE);
            } catch (\Throwable) {
                // Fall through
            }
        }

        return self::FALLBACK_INSTRUCTIONS;
    }

    private function resolveProvider(): \CarmeloSantana\PHPAgents\Contract\ProviderInterface
    {
        $modelString = $this->roleResolver->resolve(self::ROLE);
        $factory = $this->providerFactory ?? new ProviderFactory($this->config);

        return $factory->create($modelString);
    }
}
