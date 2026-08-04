<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Discovery;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CoquiBot\Coqui\Api\Model\ModelProducer;
use CoquiBot\Coqui\Support\Cap;

/**
 * Assembles the aggregated CAP `InstanceInfo` capability-discovery document.
 *
 * The builder is a pure assembler: every value is supplied as a resolved input
 * (the production wiring in {@see \CoquiBot\Coqui\Command\ApiCommand} pulls those
 * inputs from live discovery/config sources; tests pass fixtures). Its job is to
 * shape the wire object and to enforce the CAP enum contract at build time:
 *
 * - `bindings` items are the CLOSED set {in-process, http-sse}.
 * - `mcp.transports` items are the CLOSED set {stdio, http}.
 * - `auth.scheme` is the CLOSED single value `bearer`; `auth` is OMITTED entirely
 *   when the instance is embedded/no-auth (the schema requires both `required`
 *   and `scheme`, so a scheme-less object is never emitted).
 * - `profiles` (and `profile_versions` keys) are an OPEN set: emitted as free
 *   strings, never allowlist-filtered, so an unknown/future profile survives
 *   discovery unchanged (forward tolerance, Foundation §6.2).
 *
 * Required fields (`protocol_version`, `profiles`, `bindings`) are always present;
 * optionals are omitted when their source is unavailable, so the builder always
 * produces a schema-valid minimal document.
 */
final class InstanceInfoBuilder
{
    /** @var list<string> */
    private const array BINDINGS = ['in-process', 'http-sse'];

    /** @var list<string> */
    private const array MCP_TRANSPORTS = ['stdio', 'http'];

    private const string AUTH_SCHEME = 'bearer';

    /**
     * @param list<string>                                                                                        $profiles       free-form profile identifiers (OPEN set — never filtered)
     * @param list<string>                                                                                        $bindings       advertised transport bindings (CLOSED set)
     * @param list<ModelDefinition>                                                                               $models         available models, serialized via ModelProducer
     * @param list<array{namespace: string, description?: string, tools?: list<string>}>                          $hostToolkits   native, non-portable host toolkits
     * @param list<string>                                                                                        $builtinToolkits portable built-in toolkit names
     * @param list<string>|null                                                                                   $mcpTransports  null ⇒ omit the `mcp` block entirely (mcp profile absent)
     * @param array<string, string>                                                                               $profileVersions per-profile semver map (OPEN keys)
     * @param array{max_page_size: int, max_payload_bytes: int, max_content_bytes: int, rate_limit?: array<string, mixed>|null}|null $limits
     * @param array{base_path: string, api_major: string}|null                                                    $api
     */
    public function __construct(
        private readonly array $profiles,
        private readonly array $bindings = self::BINDINGS,
        private readonly int $personaCount = 0,
        private readonly ?string $defaultModel = null,
        private readonly array $models = [],
        private readonly array $hostToolkits = [],
        private readonly array $builtinToolkits = [],
        private readonly ?array $mcpTransports = null,
        private readonly array $profileVersions = [],
        private readonly ?bool $authRequired = null,
        private readonly ?array $limits = null,
        private readonly ?array $api = null,
        private readonly string $name = 'coqui',
        private readonly ?string $schedulesDialect = null,
    ) {}

    /**
     * Assemble the InstanceInfo wire object.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $info = [
            'protocol_version' => Cap::PROTOCOL_VERSION,
            'name' => $this->name,
            // OPEN set: emitted verbatim, deduped, never allowlist-filtered.
            'profiles' => $this->uniqueStrings($this->profiles),
            // CLOSED set: only ever the known members survive.
            'bindings' => $this->closedSet($this->bindings, self::BINDINGS),
        ];

        if ($this->personaCount >= 0) {
            $info['persona_count'] = $this->personaCount;
        }

        if ($this->defaultModel !== null && $this->defaultModel !== '') {
            $info['default_model'] = $this->defaultModel;
        }

        if ($this->models !== []) {
            $info['models'] = array_map(
                static fn (ModelDefinition $model): array => ModelProducer::toWire($model),
                $this->models,
            );
        }

        if ($this->hostToolkits !== []) {
            $info['host_toolkits'] = $this->normalizeHostToolkits($this->hostToolkits);
        }

        if ($this->mcpTransports !== null) {
            $transports = $this->closedSet($this->mcpTransports, self::MCP_TRANSPORTS);
            // An mcp profile with no advertised transports is a valid empty object,
            // not a JSON array.
            $info['mcp'] = $transports === [] ? new \stdClass() : ['transports' => $transports];
        }

        if ($this->profileVersions !== []) {
            $info['profile_versions'] = $this->profileVersions;
        }

        // Omit `auth` entirely when embedded/no-auth: the schema requires both
        // `required` and `scheme`, so a scheme-less object is never emitted.
        if ($this->authRequired !== null) {
            $info['auth'] = [
                'required' => $this->authRequired,
                'scheme' => self::AUTH_SCHEME,
            ];
        }

        if ($this->limits !== null) {
            $info['limits'] = $this->normalizeLimits($this->limits);
        }

        if ($this->api !== null) {
            $info['api'] = [
                'base_path' => (string) $this->api['base_path'],
                'api_major' => (string) $this->api['api_major'],
            ];
        }

        if ($this->builtinToolkits !== []) {
            $info['builtin_toolkits'] = $this->uniqueStrings($this->builtinToolkits);
        }

        if ($this->schedulesDialect !== null && $this->schedulesDialect !== '') {
            $info['schedules'] = ['dialect' => $this->schedulesDialect];
        }

        return $info;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function uniqueStrings(array $values): array
    {
        $seen = [];
        foreach ($values as $value) {
            if ($value !== '' && !in_array($value, $seen, true)) {
                $seen[] = $value;
            }
        }

        return $seen;
    }

    /**
     * Enforce a closed enum: keep only known members, deduped, in input order.
     *
     * @param list<string> $values
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private function closedSet(array $values, array $allowed): array
    {
        $out = [];
        foreach ($values as $value) {
            if (in_array($value, $allowed, true) && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * @param list<array{namespace: string, description?: string, tools?: list<string>}> $toolkits
     *
     * @return list<array{namespace: string, description?: string, tools?: list<string>}>
     */
    private function normalizeHostToolkits(array $toolkits): array
    {
        $out = [];
        foreach ($toolkits as $toolkit) {
            $entry = ['namespace' => (string) $toolkit['namespace']];

            if (isset($toolkit['description']) && $toolkit['description'] !== '') {
                $entry['description'] = (string) $toolkit['description'];
            }

            if (isset($toolkit['tools']) && $toolkit['tools'] !== []) {
                $entry['tools'] = array_map('strval', $toolkit['tools']);
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array{max_page_size: int, max_payload_bytes: int, max_content_bytes: int, rate_limit?: array<string, mixed>|null} $limits
     *
     * @return array<string, mixed>
     */
    private function normalizeLimits(array $limits): array
    {
        $out = [
            'max_page_size' => (int) $limits['max_page_size'],
            'max_payload_bytes' => (int) $limits['max_payload_bytes'],
            'max_content_bytes' => (int) $limits['max_content_bytes'],
        ];

        if (array_key_exists('rate_limit', $limits)) {
            $rateLimit = $limits['rate_limit'];
            // An empty descriptor is a valid empty object, not a JSON array.
            $out['rate_limit'] = $rateLimit === [] ? new \stdClass() : $rateLimit;
        }

        return $out;
    }
}
