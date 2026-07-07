<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\Schema;

use CarmeloSantana\PHPAgents\Tool\Parameter\ArrayParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\ObjectParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\Parameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

/**
 * Converts MCP tool inputSchema (JSON Schema) to Coqui Parameter objects.
 *
 * MCP servers declare tool parameters as standard JSON Schema within the
 * inputSchema returned by tools/list. This converter maps each JSON Schema
 * type to the corresponding Coqui Parameter class.
 *
 * Handles nested objects and arrays recursively. Unknown or complex types
 * (anyOf, oneOf, etc.) fall back to StringParameter with a note about
 * JSON-encoding complex values.
 */
final class SchemaConverter
{
    /**
     * Convert an MCP tool's inputSchema to an array of Coqui Parameters.
     *
     * @param array<string, mixed> $inputSchema The JSON Schema from tools/list
     *
     * @return Parameter[]
     */
    public function convert(array $inputSchema): array
    {
        $properties = $inputSchema['properties'] ?? [];

        if (!is_array($properties)) {
            return [];
        }

        $requiredFields = $inputSchema['required'] ?? [];

        if (!is_array($requiredFields)) {
            $requiredFields = [];
        }

        /** @var list<string> $requiredFields */
        $params = [];

        foreach ($properties as $name => $schema) {
            if (!is_string($name) || !is_array($schema)) {
                continue;
            }

            $isRequired = in_array($name, $requiredFields, true);
            $params[] = $this->convertProperty($name, $schema, $isRequired);
        }

        return $params;
    }

    /**
     * Convert a single JSON Schema property to a Parameter.
     *
     * @param array<string, mixed> $schema
     */
    private function convertProperty(string $name, array $schema, bool $required): Parameter
    {
        $description = (string) ($schema['description'] ?? '');
        $type = $schema['type'] ?? null;

        // Handle enum at any type level
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $values = array_map('strval', $schema['enum']);

            return new EnumParameter(
                name: $name,
                description: $description,
                values: $values,
                required: $required,
            );
        }

        return match ($type) {
            'string' => $this->convertString($name, $description, $schema, $required),
            'number' => new NumberParameter(
                name: $name,
                description: $description,
                required: $required,
                integer: false,
                minimum: isset($schema['minimum']) ? (float) $schema['minimum'] : null,
                maximum: isset($schema['maximum']) ? (float) $schema['maximum'] : null,
            ),
            'integer' => new NumberParameter(
                name: $name,
                description: $description,
                required: $required,
                integer: true,
                minimum: isset($schema['minimum']) ? (float) $schema['minimum'] : null,
                maximum: isset($schema['maximum']) ? (float) $schema['maximum'] : null,
            ),
            'boolean' => new BoolParameter(
                name: $name,
                description: $description,
                required: $required,
            ),
            'array' => $this->convertArray($name, $description, $schema, $required),
            'object' => $this->convertObject($name, $description, $schema, $required),
            default => new StringParameter(
                name: $name,
                description: $description !== ''
                    ? $description . ' (complex type — provide as JSON string)'
                    : 'Complex type — provide as JSON string',
                required: $required,
            ),
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function convertString(string $name, string $description, array $schema, bool $required): Parameter
    {
        // String with enum
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            return new EnumParameter(
                name: $name,
                description: $description,
                values: array_map('strval', $schema['enum']),
                required: $required,
            );
        }

        return new StringParameter(
            name: $name,
            description: $description,
            required: $required,
            pattern: isset($schema['pattern']) ? (string) $schema['pattern'] : null,
            maxLength: isset($schema['maxLength']) ? (int) $schema['maxLength'] : null,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function convertArray(string $name, string $description, array $schema, bool $required): ArrayParameter
    {
        $items = null;

        if (isset($schema['items']) && is_array($schema['items'])) {
            $items = $this->convertProperty('item', $schema['items'], false);
        }

        return new ArrayParameter(
            name: $name,
            description: $description,
            required: $required,
            items: $items,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function convertObject(string $name, string $description, array $schema, bool $required): ObjectParameter
    {
        $properties = [];
        $objProperties = $schema['properties'] ?? [];
        $objRequired = $schema['required'] ?? [];

        if (!is_array($objRequired)) {
            $objRequired = [];
        }

        if (is_array($objProperties)) {
            foreach ($objProperties as $propName => $propSchema) {
                if (!is_string($propName) || !is_array($propSchema)) {
                    continue;
                }

                $isRequired = in_array($propName, $objRequired, true);
                $properties[] = $this->convertProperty($propName, $propSchema, $isRequired);
            }
        }

        return new ObjectParameter(
            name: $name,
            description: $description,
            required: $required,
            properties: $properties,
        );
    }
}
