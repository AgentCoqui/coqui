<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CoquiBot\Coqui\Contract\RoleProperties;
use CoquiBot\Coqui\Exception\RoleParseException;

/**
 * Stateless parser for role definition .md files.
 *
 * Handles YAML frontmatter extraction, minimal YAML parsing (no ext-yaml
 * dependency), property construction, body extraction, and validation.
 *
 * Modeled on SkillParser but tailored for role-specific fields.
 */
final class RoleParser
{
    private const int MAX_NAME_LENGTH = 64;

    /** @var list<string> */
    private const array VALID_ACCESS_LEVELS = ['full', 'readonly', 'minimal'];

    /**
     * Parse a role markdown file into a RoleProperties value object.
     *
     * @throws RoleParseException If the file is missing or required fields are absent.
     */
    public function readProperties(string $filePath): RoleProperties
    {
        if (!file_exists($filePath)) {
            throw RoleParseException::missingRoleFile($filePath);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw RoleParseException::missingRoleFile($filePath);
        }

        $parsed = $this->parseFrontmatter($content, $filePath);
        $meta = $parsed['metadata'];

        // Validate required fields
        if (!isset($meta['name']) || !is_string($meta['name']) || $meta['name'] === '') {
            throw RoleParseException::missingRequiredField('name', $filePath);
        }

        if (!isset($meta['display_name']) || !is_string($meta['display_name']) || $meta['display_name'] === '') {
            throw RoleParseException::missingRequiredField('display_name', $filePath);
        }

        if (!isset($meta['description']) || !is_string($meta['description']) || $meta['description'] === '') {
            throw RoleParseException::missingRequiredField('description', $filePath);
        }

        // Validate access_level
        $accessLevel = isset($meta['access_level']) && is_string($meta['access_level'])
            ? $meta['access_level']
            : 'readonly';

        if (!in_array($accessLevel, self::VALID_ACCESS_LEVELS, true)) {
            throw RoleParseException::invalidFieldValue('access_level', $accessLevel, $filePath);
        }

        // Parse version
        $version = 1;
        if (isset($meta['version'])) {
            $version = is_numeric($meta['version']) ? (int) $meta['version'] : 1;
        }

        // Parse is_builtin
        $isBuiltin = false;
        if (isset($meta['is_builtin'])) {
            $isBuiltin = $meta['is_builtin'] === 'true' || $meta['is_builtin'] === true;
        }

        return new RoleProperties(
            name: $meta['name'],
            displayName: $meta['display_name'],
            description: $meta['description'],
            path: $filePath,
            version: $version,
            accessLevel: $accessLevel,
            isBuiltin: $isBuiltin,
            model: isset($meta['model']) && is_string($meta['model']) && $meta['model'] !== '' ? $meta['model'] : null,
            titleModel: isset($meta['title_model']) && is_string($meta['title_model']) && $meta['title_model'] !== '' ? $meta['title_model'] : null,
            allowedTools: isset($meta['allowed-tools']) && is_string($meta['allowed-tools']) ? $meta['allowed-tools'] : null,
            maxIterations: isset($meta['max_iterations']) && is_numeric($meta['max_iterations']) ? (int) $meta['max_iterations'] : null,
        );
    }

    /**
     * Return just the markdown body (instructions) after frontmatter.
     *
     * @throws RoleParseException If the file is missing or malformed.
     */
    public function readBody(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw RoleParseException::missingRoleFile($filePath);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw RoleParseException::missingRoleFile($filePath);
        }

        $parsed = $this->parseFrontmatter($content, $filePath);

        return $parsed['body'];
    }

    /**
     * Validate a role name format.
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
     * Build a role file content string from properties and instructions.
     */
    public function buildRoleFile(RoleProperties $properties, string $instructions): string
    {
        $lines = [
            '---',
            "name: {$properties->name}",
            "display_name: {$properties->displayName}",
            "description: {$properties->description}",
            "version: {$properties->version}",
            "access_level: {$properties->accessLevel}",
        ];

        if ($properties->isBuiltin) {
            $lines[] = 'is_builtin: true';
        }

        if ($properties->model !== null) {
            $lines[] = "model: {$properties->model}";
        }

        if ($properties->titleModel !== null) {
            $lines[] = "title_model: {$properties->titleModel}";
        }

        if ($properties->allowedTools !== null) {
            $lines[] = "allowed-tools: {$properties->allowedTools}";
        }

        if ($properties->maxIterations !== null) {
            $lines[] = "max_iterations: {$properties->maxIterations}";
        }

        $lines[] = '---';
        $lines[] = '';

        return implode("\n", $lines) . $instructions . "\n";
    }

    /**
     * Split YAML frontmatter from the markdown body.
     *
     * @return array{metadata: array<string, mixed>, body: string}
     *
     * @throws RoleParseException If frontmatter is missing or malformed.
     */
    private function parseFrontmatter(string $content, string $path): array
    {
        $content = ltrim($content);

        if (!str_starts_with($content, '---')) {
            throw RoleParseException::malformedFrontmatter(
                $path,
                'Content does not start with --- frontmatter delimiter.',
            );
        }

        $rest = substr($content, 3);
        $closingPos = preg_match('/\n---\s*(\n|$)/', $rest, $matches, PREG_OFFSET_CAPTURE);

        if ($closingPos === 0 || $closingPos === false) {
            throw RoleParseException::malformedFrontmatter(
                $path,
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
     * Minimal YAML subset parser for role frontmatter.
     *
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
                $key = $matches[1];
                $value = trim($matches[2]);
                $result[$key] = $this->stripQuotes($value);
            }
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
