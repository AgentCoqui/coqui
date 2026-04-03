<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use CoquiBot\Coqui\Utility\ScheduleValidator;

/**
 * Immutable value object representing a schedule parsed from a workspace JSON file.
 *
 * Only static definition fields are represented here. Runtime state
 * (last_run_at, run_count, failure_count, etc.) is owned by ScheduleStore.
 */
final readonly class ScheduleFileDefinition
{
    /**
     * @param string $name        Schedule name (derived from filename stem)
     * @param string $sourcePath  Absolute path to the source JSON file
     * @param string $expression  Cron expression or @once
     * @param string $prompt      Agent prompt
     * @param string $role        Agent role (default: orchestrator)
     * @param int    $maxIterations Iteration budget
     * @param string|null $description  Optional description
     * @param string $timezone    Cron evaluation timezone
     * @param int    $maxFailures Circuit breaker threshold
     * @param bool   $enabled     Whether the schedule is active
     * @param string|null $metadata Optional JSON metadata
     */
    public function __construct(
        public string $name,
        public string $sourcePath,
        public string $expression,
        public string $prompt,
        public string $role = 'orchestrator',
        public int $maxIterations = 48,
        public ?string $description = null,
        public string $timezone = 'UTC',
        public int $maxFailures = 3,
        public bool $enabled = true,
        public ?string $metadata = null,
    ) {}

    /**
     * Parse a JSON file into a ScheduleFileDefinition.
     *
     * @param string $filePath Absolute path to the JSON file
     * @throws \InvalidArgumentException On invalid or incomplete JSON
     * @throws \JsonException On malformed JSON
     */
    public static function fromFile(string $filePath): self
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new \InvalidArgumentException(sprintf('Cannot read schedule file: %s', $filePath));
        }

        $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException(sprintf('Schedule file must contain a JSON object: %s', $filePath));
        }

        return self::fromArray($data, $filePath);
    }

    /**
     * Build from a decoded JSON array.
     *
     * @param array<string, mixed> $data Decoded JSON
     * @param string $filePath Absolute path to the source file
     * @throws \InvalidArgumentException On missing or invalid fields
     */
    public static function fromArray(array $data, string $filePath): self
    {
        $name = self::deriveNameFromPath($filePath);

        // Validate name format
        $nameError = ScheduleValidator::validateName($name);
        if ($nameError !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid schedule filename "%s": %s',
                basename($filePath),
                $nameError,
            ));
        }

        // Require expression and prompt
        $expression = trim((string) ($data['schedule_expression'] ?? $data['expression'] ?? $data['cron'] ?? ''));
        if ($expression === '') {
            throw new \InvalidArgumentException(sprintf(
                'Schedule file "%s" missing required field: schedule_expression (or expression/cron)',
                basename($filePath),
            ));
        }

        $exprError = ScheduleValidator::validateExpression($expression);
        if ($exprError !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Schedule file "%s": %s',
                basename($filePath),
                $exprError,
            ));
        }

        $prompt = trim((string) ($data['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \InvalidArgumentException(sprintf(
                'Schedule file "%s" missing required field: prompt',
                basename($filePath),
            ));
        }

        $promptError = ScheduleValidator::validatePromptLength($prompt);
        if ($promptError !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Schedule file "%s": %s',
                basename($filePath),
                $promptError,
            ));
        }

        // Optional fields with defaults
        $timezone = trim((string) ($data['timezone'] ?? 'UTC'));
        $tzError = ScheduleValidator::validateTimezone($timezone);
        if ($tzError !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Schedule file "%s": %s',
                basename($filePath),
                $tzError,
            ));
        }

        $maxIterations = ScheduleValidator::normalizeMaxIterations(
            (int) ($data['max_iterations'] ?? 48),
        );

        $maxFailures = ScheduleValidator::normalizeMaxFailures(
            (int) ($data['max_failures'] ?? 3),
        );

        $metadata = isset($data['metadata']) && is_array($data['metadata'])
            ? json_encode($data['metadata'], JSON_UNESCAPED_SLASHES)
            : (isset($data['metadata']) && is_string($data['metadata']) ? $data['metadata'] : null);

        return new self(
            name: $name,
            sourcePath: $filePath,
            expression: $expression,
            prompt: $prompt,
            role: trim((string) ($data['role'] ?? 'orchestrator')),
            maxIterations: $maxIterations,
            description: isset($data['description']) ? trim((string) $data['description']) : null,
            timezone: $timezone,
            maxFailures: $maxFailures,
            enabled: (bool) ($data['enabled'] ?? true),
            metadata: $metadata,
        );
    }

    /**
     * Derive the schedule name from the JSON filename stem.
     *
     * e.g. "daily-report.json" → "daily-report"
     */
    public static function deriveNameFromPath(string $filePath): string
    {
        return pathinfo(basename($filePath), PATHINFO_FILENAME);
    }
}
