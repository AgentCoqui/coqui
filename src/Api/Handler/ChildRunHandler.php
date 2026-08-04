<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Agent\ChildAgent;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Child-run spawn + read endpoints (CAP CORE-29, part 1).
 *
 * POST /api/v1/sessions/{id}/child-runs                 — spawnChildRun (202, gated)
 * GET  /api/v1/sessions/{id}/child-runs/{childRunId}    — getChildRun
 *
 * Execution model — SYNC-EXECUTE-THEN-REPORT: a spawn inserts a `running`
 * child-run row, runs the child SYNCHRONOUSLY via the existing {@see ChildAgent}
 * path, records the terminal `completed`/`failed` transition on the row, and
 * returns 202 with the resulting `child-run.json` resource. There is no async
 * job runtime — the resource is already terminal when the response is written.
 *
 * Gating mirrors the `spawn_agent` tool, which is registered ONLY by the
 * top-level OrchestratorAgent (children lack it — no nesting): spawning is
 * restricted to top-level, full-access orchestrator sessions. Any other caller
 * is rejected 403 `forbidden`.
 *
 * The wire producer is not duplicated here: rows are serialized through the
 * canonical {@see SessionHandler::childRunToWire()}.
 */
final readonly class ChildRunHandler
{
    use DecodesRequestBody;

    /**
     * @param \Closure(string):ProviderInterface|null $providerResolver Optional
     *        override that resolves a provider from a model string. Defaults to
     *        the injected {@see ProviderFactory}. Present for deterministic tests.
     */
    public function __construct(
        private SessionStorage $storage,
        private RoleResolver $roleResolver,
        private ProviderFactory $providerFactory,
        private ?RoleDiscovery $roleDiscovery = null,
        private ?\Closure $providerResolver = null,
    ) {}

    /**
     * POST /api/v1/sessions/{id}/child-runs
     */
    public function spawnChildRun(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $gate = $this->spawnGate($session);
        if ($gate instanceof Response) {
            return $gate;
        }

        $body = $this->decodeJsonObjectOrNull($request) ?? [];

        $role = trim((string) ($body['role'] ?? ''));
        if ($role === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'role is required');
        }

        $prompt = trim((string) ($body['prompt'] ?? ''));
        if ($prompt === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'prompt is required');
        }

        $persona = is_string($session['persona_id'] ?? null) && $session['persona_id'] !== ''
            ? $session['persona_id']
            : null;

        // Resolve role → model. An empty resolution ⇒ inherit (null model on the row).
        $model = $this->roleResolver->resolve($role, $persona);
        $rowModel = $model !== '' ? $model : null;

        // Phase 1: record the run as `running` with created_at.
        $childRunId = $this->storage->createChildRun(
            parentSessionId: $id,
            role: $role,
            model: $rowModel,
            prompt: $prompt,
            status: 'running',
        );

        // Phase 2: run the child synchronously, then record the terminal transition.
        try {
            $child = new ChildAgent(
                provider: $this->makeProvider($model),
                role: $role,
                taskInstructions: $prompt,
                roleDiscovery: $this->roleDiscovery,
            );

            $output = $child->run(new UserMessage($prompt));
            $usage = $output->usage;

            $this->storage->finalizeChildRun(
                childRunId: $childRunId,
                status: 'completed',
                result: $output->content,
                promptTokens: $usage !== null ? $usage->promptTokens : 0,
                completionTokens: $usage !== null ? $usage->completionTokens : 0,
                totalTokens: $usage !== null ? $usage->totalTokens : 0,
            );
        } catch (\Throwable) {
            $this->storage->finalizeChildRun($childRunId, 'failed');
        }

        $row = $this->storage->getChildRun($childRunId);
        if ($row === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Child run vanished after spawn');
        }

        // 202: the spawn was accepted and executed; the resource is already
        // terminal (completed or failed). A failed child is a valid resource, not
        // an HTTP error — the failure is captured in the row's `status`.
        return Router::jsonResponse(SessionHandler::childRunToWire($row), 202);
    }

    /**
     * GET /api/v1/sessions/{id}/child-runs/{childRunId}
     */
    public function getChildRun(ServerRequestInterface $request, string $id, string $childRunId): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $row = $this->storage->getChildRun($childRunId);
        if ($row === null || (string) ($row['parent_session_id'] ?? '') !== $id) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Child run not found');
        }

        return Router::jsonResponse(SessionHandler::childRunToWire($row));
    }

    /**
     * Enforce "top-level + full access": spawning is allowed only from a top-level
     * orchestrator session whose role resolves to full access. Any other session
     * (a delegated/child role, or a non-full access level) is rejected 403.
     *
     * @param array<string, mixed> $session
     */
    private function spawnGate(array $session): ?Response
    {
        $role = (string) ($session['model_role'] ?? '');

        $isTopLevel = $role === SystemRole::Orchestrator->value;
        $isFullAccess = $this->resolveAccessLevel($role) === 'full';

        if ($isTopLevel && $isFullAccess) {
            return null;
        }

        return Router::errorResponse(
            ApiErrorCode::FORBIDDEN,
            'Child-run spawning is restricted to top-level, full-access orchestrator sessions.',
            ['model_role' => $role],
        );
    }

    /**
     * Resolve a session role's access level, mirroring SpawnAgentTool's resolution
     * with an orchestrator ⇒ full fallback for the top-level role.
     */
    private function resolveAccessLevel(string $role): string
    {
        if ($this->roleDiscovery !== null) {
            try {
                return $this->roleDiscovery->getRole($role)->accessLevel;
            } catch (\Throwable) {
                // Fall through to hardcoded defaults.
            }
        }

        return match ($role) {
            SystemRole::Orchestrator->value, SystemRole::Coder->value => 'full',
            default => 'readonly',
        };
    }

    private function makeProvider(string $model): ProviderInterface
    {
        if ($this->providerResolver !== null) {
            return ($this->providerResolver)($model);
        }

        return $this->providerFactory->create($model);
    }
}
