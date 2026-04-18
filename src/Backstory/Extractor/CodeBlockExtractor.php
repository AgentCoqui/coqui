<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Wraps common source code files in fenced code blocks without executing them.
 */
final class CodeBlockExtractor implements ExtractorInterface
{
    /** @var array<string, string> */
    private const array LANGUAGE_MAP = [
        'bash' => 'bash',
        'c' => 'c',
        'cc' => 'cpp',
        'cpp' => 'cpp',
        'cs' => 'csharp',
        'css' => 'css',
        'dart' => 'dart',
        'fish' => 'fish',
        'go' => 'go',
        'h' => 'c',
        'hh' => 'cpp',
        'hpp' => 'cpp',
        'hs' => 'haskell',
        'java' => 'java',
        'js' => 'javascript',
        'jsx' => 'jsx',
        'kt' => 'kotlin',
        'kts' => 'kotlin',
        'less' => 'less',
        'lua' => 'lua',
        'mjs' => 'javascript',
        'php' => 'php',
        'pl' => 'perl',
        'pm' => 'perl',
        'ps1' => 'powershell',
        'py' => 'python',
        'r' => 'r',
        'rb' => 'ruby',
        'rs' => 'rust',
        'scss' => 'scss',
        'sh' => 'bash',
        'sql' => 'sql',
        'swift' => 'swift',
        'ts' => 'typescript',
        'tsx' => 'tsx',
        'zsh' => 'zsh',
    ];

    public function extract(string $absolutePath): ExtractorResult
    {
        $result = BackstoryTextReader::read($absolutePath);
        if (!$result->success || $result->content === null) {
            return $result;
        }

        if (trim($result->content) === '') {
            return ExtractorResult::fail('File is empty');
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $language = self::LANGUAGE_MAP[$extension] ?? $extension;
        $output = BackstoryTextReader::toCodeFence($result->content, $language);

        return ExtractorResult::ok($output, BackstoryTextReader::estimateTokens($output));
    }

    public function supportedExtensions(): array
    {
        return array_keys(self::LANGUAGE_MAP);
    }
}