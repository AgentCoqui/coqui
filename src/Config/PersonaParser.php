<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Optional frontmatter parser for profile soul files.
 *
 * Profiles may use plain markdown or begin with YAML frontmatter. The body is
 * always returned without the frontmatter block so profile metadata never leaks
 * into rendered prompts.
 */
final class PersonaParser
{
    /**
     * @return array{metadata: array<string, mixed>, body: string}
     */
    public function parse(string $content): array
    {
        $trimmed = ltrim($content);

        if (!str_starts_with($trimmed, '---')) {
            return [
                'metadata' => [],
                'body' => trim($content),
            ];
        }

        $rest = substr($trimmed, 3);
        $closingPos = preg_match('/\n---\s*(\n|$)/', $rest, $matches, PREG_OFFSET_CAPTURE);

        if ($closingPos === 0 || $closingPos === false) {
            return [
                'metadata' => [],
                'body' => trim($content),
            ];
        }

        $yamlContent = substr($rest, 0, (int) $matches[0][1]);
        $body = substr($rest, (int) $matches[0][1] + strlen($matches[0][0]));

        return [
            'metadata' => $this->parseYaml(trim($yamlContent)),
            'body' => trim($body),
        ];
    }

    /**
     * @return array{metadata: array<string, mixed>, body: string}
     */
    public function readFile(string $filePath): array
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException(sprintf('Failed to read profile file "%s".', $filePath));
        }

        return $this->parse($content);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseYaml(string $yaml): array
    {
        $result = [];
        $lines = explode("\n", $yaml);

        foreach ($lines as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            if (preg_match('/^([a-z][a-z0-9_\-]*):\s*(.*)$/i', $line, $matches)) {
                $result[$matches[1]] = $this->stripQuotes(trim($matches[2]));
            }
        }

        return $result;
    }

    private function stripQuotes(string $value): string
    {
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
