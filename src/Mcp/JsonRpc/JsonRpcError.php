<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\JsonRpc;

/**
 * JSON-RPC 2.0 error object.
 */
final readonly class JsonRpcError
{
    public function __construct(
        public int $code,
        public string $message,
        public mixed $data = null,
    ) {}
}
