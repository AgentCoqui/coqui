<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Import;

/**
 * Validates an export-envelope item (or the whole envelope) against a CAP 0.5.0
 * JSON schema by name (e.g. `persona.json`, `export.json`).
 *
 * {@see ImportService} depends on this narrow port rather than a concrete schema
 * engine so the runtime stays decoupled from where the vendored spec schemas
 * live. Import has no HTTP surface yet (in-process only), so the validator is
 * supplied by the caller that owns the schema directory.
 */
interface EnvelopeItemValidator
{
    /**
     * @param array<int|string, mixed>|object $data
     */
    public function isValid(string $schemaName, array|object $data): bool;

    /**
     * A human-readable description of why $data failed $schemaName, or '' when it
     * is valid. Used to build the rejection's `details`.
     *
     * @param array<int|string, mixed>|object $data
     */
    public function errorText(string $schemaName, array|object $data): string;
}
