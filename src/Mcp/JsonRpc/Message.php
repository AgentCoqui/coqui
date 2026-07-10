<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\JsonRpc;

/**
 * JSON-RPC 2.0 message builder and parser for MCP communication.
 *
 * Handles request, notification, response, and error message types.
 * Requests carry an auto-incrementing integer ID; notifications have no ID.
 */
final readonly class Message
{
    private const string JSONRPC_VERSION = '2.0';

    /**
     * @param array<string, mixed>|null $result  Present on success responses
     * @param JsonRpcError|null         $error   Present on error responses
     * @param array<string, mixed>|null $params  Present on requests/notifications
     */
    private function __construct(
        public ?int $id = null,
        public ?string $method = null,
        public ?array $params = null,
        public ?array $result = null,
        public ?JsonRpcError $error = null,
    ) {}

    /**
     * Create a JSON-RPC request (has an ID, expects a response).
     *
     * @param array<string, mixed> $params
     */
    public static function request(int $id, string $method, array $params = []): self
    {
        return new self(id: $id, method: $method, params: $params);
    }

    /**
     * Create a JSON-RPC notification (no ID, no response expected).
     *
     * @param array<string, mixed> $params
     */
    public static function notification(string $method, array $params = []): self
    {
        return new self(method: $method, params: $params);
    }

    /**
     * Parse a JSON string into a Message.
     *
     * @throws \InvalidArgumentException If the JSON is invalid or missing required fields
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON-RPC message must be an object');
        }

        /** @var array<string, mixed> $data */
        $jsonrpc = $data['jsonrpc'] ?? null;

        if ($jsonrpc !== self::JSONRPC_VERSION) {
            throw new \InvalidArgumentException(
                sprintf('Expected jsonrpc version "%s", got "%s"', self::JSONRPC_VERSION, (string) $jsonrpc),
            );
        }

        $id = isset($data['id']) ? (int) $data['id'] : null;
        $method = isset($data['method']) ? (string) $data['method'] : null;
        $params = isset($data['params']) && is_array($data['params']) ? $data['params'] : null;
        $result = isset($data['result']) && is_array($data['result']) ? $data['result'] : null;
        $error = null;

        if (isset($data['error']) && is_array($data['error'])) {
            /** @var array{code?: int, message?: string, data?: mixed} $errorData */
            $errorData = $data['error'];
            $error = new JsonRpcError(
                code: (int) ($errorData['code'] ?? 0),
                message: (string) ($errorData['message'] ?? 'Unknown error'),
                data: $errorData['data'] ?? null,
            );
        }

        return new self(
            id: $id,
            method: $method,
            params: $params,
            result: $result,
            error: $error,
        );
    }

    /**
     * Serialize to a JSON string (no trailing newline — transport adds that).
     */
    public function toJson(): string
    {
        $data = ['jsonrpc' => self::JSONRPC_VERSION];

        if ($this->id !== null) {
            $data['id'] = $this->id;
        }

        if ($this->method !== null) {
            $data['method'] = $this->method;
        }

        if ($this->params !== null) {
            $data['params'] = $this->params === [] ? (object) [] : $this->params;
        }

        if ($this->result !== null) {
            $data['result'] = $this->result;
        }

        if ($this->error !== null) {
            $data['error'] = [
                'code' => $this->error->code,
                'message' => $this->error->message,
            ];

            if ($this->error->data !== null) {
                $data['error']['data'] = $this->error->data;
            }
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Whether this message is a request (has method + id).
     */
    public function isRequest(): bool
    {
        return $this->method !== null && $this->id !== null;
    }

    /**
     * Whether this message is a notification (has method, no id).
     */
    public function isNotification(): bool
    {
        return $this->method !== null && $this->id === null;
    }

    /**
     * Whether this message is a success response (has result, no error).
     */
    public function isResponse(): bool
    {
        return $this->id !== null && $this->result !== null && $this->error === null;
    }

    /**
     * Whether this message is an error response.
     */
    public function isError(): bool
    {
        return $this->error !== null;
    }
}
