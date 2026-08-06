<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

/**
 * One row of the {@see OperationCatalog}: a single API operation named by its
 * camelCase operation id, the HTTP verb + path it is reachable at, its optional
 * capability profile, its response cardinality, and the single handler
 * implementation both bindings resolve to.
 *
 * This is a coqui-internal descriptor. It is NOT a projection of an
 * `operations.yaml`/`openapi.yaml` catalog (those are not vendored in the pinned
 * conformance snapshot) — it exists so coqui can assert its OWN cross-binding
 * self-consistency, not tri-catalog parity.
 */
final readonly class OperationDescriptor
{
    /**
     * A single-resource response (a bare object).
     */
    public const string CARDINALITY_SINGLE = 'single';

    /**
     * A cursor-paginated list response — the CAP `{data, next_cursor}` envelope
     * produced by {@see CursorPage::build}.
     */
    public const string CARDINALITY_LIST = 'list';

    /**
     * @param non-empty-string $operationId camelCase id, matching the spec's
     *        `error-coverage.json` op-id namespace where the operation appears there.
     * @param non-empty-string $httpMethod  Upper-case HTTP verb the route binds.
     * @param non-empty-string $path        The `/api/v1/...` route template.
     * @param ?string $profile              The capability profile gating this op —
     *        one of the OPEN built-in set (artifacts, questions, skills, schedules,
     *        mcp) — or null for a Core op reachable without a profile.
     * @param string $cardinality Must be {@see self::CARDINALITY_SINGLE} or
     *        {@see self::CARDINALITY_LIST}; the constructor enforces the closed set.
     * @param array{0: class-string, 1: non-empty-string} $handler The single
     *        `[handlerClass, method]` implementation the HTTP route binds and an
     *        in-process call would invoke — identical under both bindings.
     */
    public function __construct(
        public string $operationId,
        public string $httpMethod,
        public string $path,
        public ?string $profile,
        public string $cardinality,
        public array $handler,
    ) {
        if ($cardinality !== self::CARDINALITY_SINGLE && $cardinality !== self::CARDINALITY_LIST) {
            throw new \InvalidArgumentException(
                "cardinality must be 'single' or 'list', got '{$cardinality}'",
            );
        }
    }

    /**
     * The single implementation, as `Class::method`, that both bindings resolve to.
     */
    public function handlerId(): string
    {
        return $this->handler[0] . '::' . $this->handler[1];
    }
}
