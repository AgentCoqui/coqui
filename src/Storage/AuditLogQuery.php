<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

/**
 * Validated filter/pagination criteria for an audit-log read.
 *
 * Time semantics: `after` is inclusive (>=), `before` is exclusive (<).
 */
final readonly class AuditLogQuery
{
    public const int MAX_LIMIT = 500;
    public const int DEFAULT_LIMIT = 100;

    public function __construct(
        public ?string $sessionId = null,
        public ?string $toolName = null,
        public ?string $action = null,
        public ?string $after = null,
        public ?string $before = null,
        public int $limit = self::DEFAULT_LIMIT,
        public int $offset = 0,
    ) {}

    /**
     * @param array<string, mixed> $params Raw query parameters.
     *
     * @throws \InvalidArgumentException When a timestamp boundary is not parseable.
     */
    public static function fromParams(array $params): self
    {
        return new self(
            sessionId: self::str($params, 'session_id'),
            toolName: self::str($params, 'tool_name'),
            action: self::str($params, 'action'),
            after: self::timestamp($params, 'after'),
            before: self::timestamp($params, 'before'),
            limit: isset($params['limit'])
                ? max(1, min((int) $params['limit'], self::MAX_LIMIT))
                : self::DEFAULT_LIMIT,
            offset: isset($params['offset']) ? max(0, (int) $params['offset']) : 0,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function str(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws \InvalidArgumentException
     */
    private static function timestamp(array $params, string $key): ?string
    {
        $value = self::str($params, $key);

        if ($value === null) {
            return null;
        }

        if (strtotime($value) === false) {
            throw new \InvalidArgumentException("Invalid ISO-8601 timestamp for \"{$key}\": {$value}");
        }

        return $value;
    }
}
