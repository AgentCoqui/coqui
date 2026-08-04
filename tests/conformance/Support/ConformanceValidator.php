<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance\Support;

use CoquiBot\Coqui\Import\EnvelopeItemValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;

/**
 * Validates PHP data against the vendored CAP 0.5.0 object schemas.
 *
 * Schemas declare `$id` under https://coqui.dev/spec/schema/<name>.json and cross-
 * reference each other with relative refs (e.g. common.json#/$defs/Id). Registering
 * the prefix against the vendored schema dir lets opis load + resolve them lazily.
 */
final class ConformanceValidator implements EnvelopeItemValidator
{
    private const SCHEMA_PREFIX = 'https://coqui.dev/spec/schema/';

    private Validator $validator;

    public function __construct(?string $schemaDir = null)
    {
        $schemaDir = rtrim($schemaDir ?? __DIR__ . '/../spec/schema', '/');
        $this->validator = new Validator();
        $this->validator->resolver()->registerPrefix(self::SCHEMA_PREFIX, $schemaDir);
    }

    public function validate(string $schemaName, array|object $data): ValidationResult
    {
        return $this->validator->validate(
            Helper::toJSON($data),
            self::SCHEMA_PREFIX . $schemaName
        );
    }

    public function isValid(string $schemaName, array|object $data): bool
    {
        return $this->validate($schemaName, $data)->isValid();
    }

    public function errorText(string $schemaName, array|object $data): string
    {
        $error = $this->validate($schemaName, $data)->error();
        if ($error === null) {
            return '';
        }

        return json_encode(
            (new ErrorFormatter())->format($error),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
