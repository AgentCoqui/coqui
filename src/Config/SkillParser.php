<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CoquiBot\Coqui\Contract\SkillProperties;
use CoquiBot\Coqui\Exception\SkillParseException;
use CoquiBot\Coqui\Exception\SkillValidationException;

/**
 * Stateless parser for AgentSkills SKILL.md files.
 *
 * Handles YAML frontmatter extraction, minimal YAML parsing (no ext-yaml
 * dependency), property construction, body extraction, and spec-compliant
 * validation (name format, length limits, allowed fields).
 */
final class SkillParser
{
    private const int MAX_NAME_LENGTH = 64;
    private const int MAX_DESCRIPTION_LENGTH = 1024;
    private const int MAX_COMPATIBILITY_LENGTH = 500;

    private const array ALLOWED_FIELDS = [
        'name',
        'description',
        'license',
        'compatibility',
        'metadata',
        'allowed-tools',
    ];

    /**
     * Locate the SKILL.md file in a skill directory.
     *
     * Prefers uppercase SKILL.md, falls back to lowercase skill.md.
     */
    public function findSkillMd(string $skillDir): ?string
    {
        $upper = rtrim($skillDir, '/') . '/SKILL.md';
        if (file_exists($upper)) {
            return $upper;
        }

        $lower = rtrim($skillDir, '/') . '/skill.md';
        if (file_exists($lower)) {
            return $lower;
        }

        return null;
    }

    /**
     * Split YAML frontmatter from the markdown body.
     *
     * @return array{metadata: array<string, mixed>, body: string}
     *
     * @throws SkillParseException If frontmatter is missing or malformed.
     */
    public function parseFrontmatter(string $content): array
    {
        $content = ltrim($content);

        if (!str_starts_with($content, '---')) {
            throw SkillParseException::malformedFrontmatter(
                'input',
                'Content does not start with --- frontmatter delimiter.',
            );
        }

        // Find the closing --- delimiter (must be on its own line after the opening)
        $rest = substr($content, 3);
        $closingPos = preg_match('/\n---\s*(\n|$)/', $rest, $matches, PREG_OFFSET_CAPTURE);

        if ($closingPos === 0 || $closingPos === false) {
            throw SkillParseException::malformedFrontmatter(
                'input',
                'Unclosed frontmatter — missing closing --- delimiter.',
            );
        }

        $yamlContent = substr($rest, 0, (int) $matches[0][1]);
        $body = substr($rest, (int) $matches[0][1] + strlen($matches[0][0]));

        return [
            'metadata' => $this->parseYaml(trim($yamlContent)),
            'body' => ltrim($body, "\n"),
        ];
    }

    /**
     * Parse frontmatter into a typed SkillProperties value object.
     *
     * @throws SkillParseException If SKILL.md is missing or required fields are absent.
     */
    public function readProperties(string $skillDir): SkillProperties
    {
        $path = $this->findSkillMd($skillDir);

        if ($path === null) {
            throw SkillParseException::missingSkillMd($skillDir);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw SkillParseException::missingSkillMd($skillDir);
        }

        $parsed = $this->parseFrontmatter($content);
        $meta = $parsed['metadata'];

        if (!isset($meta['name']) || !is_string($meta['name']) || $meta['name'] === '') {
            throw SkillParseException::missingRequiredField('name', $path);
        }

        if (!isset($meta['description']) || !is_string($meta['description']) || $meta['description'] === '') {
            throw SkillParseException::missingRequiredField('description', $path);
        }

        /** @var array<string, string> $metadata */
        $metadata = [];
        if (isset($meta['metadata']) && is_array($meta['metadata'])) {
            foreach ($meta['metadata'] as $key => $value) {
                $metadata[(string) $key] = (string) $value;
            }
        }

        return new SkillProperties(
            name: $meta['name'],
            description: $meta['description'],
            path: rtrim($skillDir, '/'),
            license: isset($meta['license']) && is_string($meta['license']) ? $meta['license'] : null,
            compatibility: isset($meta['compatibility']) && is_string($meta['compatibility']) ? $meta['compatibility'] : null,
            allowedTools: isset($meta['allowed-tools']) && is_string($meta['allowed-tools']) ? $meta['allowed-tools'] : null,
            metadata: $metadata,
        );
    }

    /**
     * Return just the markdown body (instructions) after frontmatter.
     *
     * @throws SkillParseException If SKILL.md is missing or malformed.
     */
    public function readBody(string $skillDir): string
    {
        $path = $this->findSkillMd($skillDir);

        if ($path === null) {
            throw SkillParseException::missingSkillMd($skillDir);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw SkillParseException::missingSkillMd($skillDir);
        }

        $parsed = $this->parseFrontmatter($content);

        return $parsed['body'];
    }

    /**
     * Validate a skill directory against the AgentSkills spec.
     *
     * @return string[] Validation errors. Empty array means valid.
     */
    public function validate(string $skillDir): array
    {
        $errors = [];
        $path = $this->findSkillMd($skillDir);

        if ($path === null) {
            return ['No SKILL.md or skill.md found in directory.'];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ['Cannot read SKILL.md file.'];
        }

        try {
            $parsed = $this->parseFrontmatter($content);
        } catch (SkillParseException $e) {
            return [$e->getMessage()];
        }

        $meta = $parsed['metadata'];

        // Validate name
        if (!isset($meta['name']) || !is_string($meta['name']) || $meta['name'] === '') {
            $errors[] = 'Required field "name" is missing.';
        } else {
            $name = $meta['name'];

            if (strlen($name) > self::MAX_NAME_LENGTH) {
                $errors[] = sprintf('Name must be at most %d characters, got %d.', self::MAX_NAME_LENGTH, strlen($name));
            }

            if ($name !== strtolower($name)) {
                $errors[] = 'Name must be lowercase.';
            }

            if (preg_match('/[^a-z0-9\-]/', $name)) {
                $errors[] = 'Name may only contain lowercase alphanumeric characters and hyphens.';
            }

            if (str_starts_with($name, '-')) {
                $errors[] = 'Name must not start with a hyphen.';
            }

            if (str_ends_with($name, '-')) {
                $errors[] = 'Name must not end with a hyphen.';
            }

            if (str_contains($name, '--')) {
                $errors[] = 'Name must not contain consecutive hyphens.';
            }

            // Name must match directory name
            $dirName = basename(rtrim($skillDir, '/'));
            if ($name !== $dirName) {
                $errors[] = sprintf('Name "%s" does not match directory name "%s".', $name, $dirName);
            }
        }

        // Validate description
        if (!isset($meta['description']) || !is_string($meta['description']) || $meta['description'] === '') {
            $errors[] = 'Required field "description" is missing.';
        } elseif (strlen($meta['description']) > self::MAX_DESCRIPTION_LENGTH) {
            $errors[] = sprintf(
                'Description must be at most %d characters, got %d.',
                self::MAX_DESCRIPTION_LENGTH,
                strlen($meta['description']),
            );
        }

        // Validate compatibility length
        if (isset($meta['compatibility']) && is_string($meta['compatibility'])) {
            if (strlen($meta['compatibility']) > self::MAX_COMPATIBILITY_LENGTH) {
                $errors[] = sprintf(
                    'Compatibility must be at most %d characters, got %d.',
                    self::MAX_COMPATIBILITY_LENGTH,
                    strlen($meta['compatibility']),
                );
            }
        }

        // Check for unexpected fields
        foreach (array_keys($meta) as $field) {
            if (!in_array($field, self::ALLOWED_FIELDS, true)) {
                $errors[] = sprintf('Unexpected frontmatter field: "%s".', $field);
            }
        }

        return $errors;
    }

    /**
     * Validate a skill name format without requiring a directory.
     *
     * Useful for validating names before creating a new skill.
     *
     * @return string[] Validation errors. Empty array means valid.
     */
    public function validateName(string $name): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = 'Name must not be empty.';
            return $errors;
        }

        if (strlen($name) > self::MAX_NAME_LENGTH) {
            $errors[] = sprintf('Name must be at most %d characters, got %d.', self::MAX_NAME_LENGTH, strlen($name));
        }

        if ($name !== strtolower($name)) {
            $errors[] = 'Name must be lowercase.';
        }

        if (preg_match('/[^a-z0-9\-]/', $name)) {
            $errors[] = 'Name may only contain lowercase alphanumeric characters and hyphens.';
        }

        if (str_starts_with($name, '-')) {
            $errors[] = 'Name must not start with a hyphen.';
        }

        if (str_ends_with($name, '-')) {
            $errors[] = 'Name must not end with a hyphen.';
        }

        if (str_contains($name, '--')) {
            $errors[] = 'Name must not contain consecutive hyphens.';
        }

        return $errors;
    }

    /**
     * Minimal YAML subset parser for AgentSkills frontmatter.
     *
     * Handles:
     * - Simple key: value pairs
     * - Quoted string values ("value" or 'value')
     * - Nested metadata map (one level of indented key-value pairs)
     * - Comment lines (starting with #)
     *
     * @return array<string, mixed>
     */
    private function parseYaml(string $yaml): array
    {
        $result = [];
        $lines = explode("\n", $yaml);
        $currentMapKey = '';
        /** @var array<string, string> $currentMap */
        $currentMap = [];

        foreach ($lines as $line) {
            // Skip empty lines and comments
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            // Check for indented line (part of a nested map)
            if (preg_match('/^[ \t]+(\S+):\s*(.*)$/', $line, $matches)) {
                if ($currentMapKey !== '') {
                    $currentMap[$matches[1]] = $this->stripQuotes(trim($matches[2]));
                }
                continue;
            }

            // Flush any pending nested map
            if ($currentMapKey !== '') {
                $result[$currentMapKey] = $currentMap;
                $currentMapKey = '';
                $currentMap = [];
            }

            // Top-level key: value
            if (preg_match('/^([a-z][a-z0-9\-]*):\s*(.*)$/i', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);

                // If value is empty, this starts a nested map
                if ($value === '') {
                    $currentMapKey = $key;
                    $currentMap = [];
                    continue;
                }

                $result[$key] = $this->stripQuotes($value);
            }
        }

        // Flush final pending nested map
        if ($currentMapKey !== '') {
            $result[$currentMapKey] = $currentMap;
        }

        return $result;
    }

    /**
     * Strip surrounding quotes from a YAML value.
     */
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
