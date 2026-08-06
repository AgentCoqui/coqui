<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Api\Handler\ArtifactHandler;
use CoquiBot\Coqui\Api\Handler\ConfigHandler;
use CoquiBot\Coqui\Api\Handler\LoopHandler;
use CoquiBot\Coqui\Api\Handler\McpServerHandler;
use CoquiBot\Coqui\Api\Handler\QuestionHandler;
use CoquiBot\Coqui\Api\Handler\RoleHandler;
use CoquiBot\Coqui\Api\Handler\ScheduleHandler;
use CoquiBot\Coqui\Api\Handler\SessionHandler;

/**
 * Hand-maintained, test-asserted catalog of coqui API operations (CORE-47, CORE-58).
 *
 * ## Scope — coqui-internal self-consistency, NOT tri-catalog parity
 *
 * CAP's CORE-47 ("x-profile operations map across both bindings") and CORE-58
 * ("single-vs-list cardinality agrees") are written against spec artifacts
 * (`operations.yaml`, `openapi.yaml`) that are NOT vendored in the pinned
 * conformance snapshot and MUST NOT be added. This catalog realizes those rows
 * as coqui-side SELF-CONSISTENCY: every operation is described exactly once, so
 * there is a single source of truth that the HTTP route table
 * ({@see \CoquiBot\Coqui\Command\ApiCommand::registerRoutes}) and any future
 * in-process binding both resolve against. The conformance tests assert this
 * catalog is internally consistent — they do NOT (and cannot) prove parity with
 * an external `operations.yaml`/`openapi.yaml`.
 *
 * ## `x-profile`, not `x-persona`
 *
 * The gating dimension is CAP's capability `x-profile` (per
 * `conformance/checklist.md:60`) — the OPEN built-in profile set
 * `{artifacts, questions, skills, schedules, mcp}` advertised in InstanceInfo.
 * An earlier test stub said `x-persona`; that was stale wording, corrected here.
 * "profile" is the capability set — NOT the renamed persona identity, and NOT
 * the `toolProfile` capability sense.
 *
 * ## Binding-agnostic handler
 *
 * Each descriptor names the ONE `[handlerClass, method]` implementation the HTTP
 * router binds; an in-process call would invoke the identical method. The single
 * tuple is the proof that both bindings hit one implementation.
 *
 * ## Cardinality
 *
 * `cardinality` is `list` for exactly the operations whose handler emits the
 * `{data, next_cursor}` cursor page via {@see CursorPage::build} (six endpoints:
 * artifact/schedule/session/role/persona/loop-definition list), and `single`
 * for every operation returning a bare resource object.
 *
 * The catalog is intentionally minimal and accurate to the live route table; the
 * conformance tests are its drift guard.
 */
final class OperationCatalog
{
    /**
     * Every enumerated operation, in declaration order.
     *
     * @return list<OperationDescriptor>
     */
    public static function all(): array
    {
        $single = OperationDescriptor::CARDINALITY_SINGLE;
        $list = OperationDescriptor::CARDINALITY_LIST;

        return [
            // --- artifacts profile ---
            new OperationDescriptor('listArtifacts', 'GET', '/api/v1/sessions/{id}/artifacts', 'artifacts', $list, [ArtifactHandler::class, 'list']),
            new OperationDescriptor('getArtifact', 'GET', '/api/v1/sessions/{id}/artifacts/{artifactId}', 'artifacts', $single, [ArtifactHandler::class, 'get']),
            new OperationDescriptor('createArtifact', 'POST', '/api/v1/sessions/{id}/artifacts', 'artifacts', $single, [ArtifactHandler::class, 'create']),
            new OperationDescriptor('updateArtifact', 'PATCH', '/api/v1/sessions/{id}/artifacts/{artifactId}', 'artifacts', $single, [ArtifactHandler::class, 'update']),
            new OperationDescriptor('deleteArtifact', 'DELETE', '/api/v1/sessions/{id}/artifacts/{artifactId}', 'artifacts', $single, [ArtifactHandler::class, 'delete']),

            // --- questions profile ---
            new OperationDescriptor('answerQuestion', 'POST', '/api/v1/sessions/{id}/questions/{questionId}/answer', 'questions', $single, [QuestionHandler::class, 'answer']),

            // --- schedules profile ---
            new OperationDescriptor('listSchedules', 'GET', '/api/v1/schedules', 'schedules', $list, [ScheduleHandler::class, 'list']),
            new OperationDescriptor('getSchedule', 'GET', '/api/v1/schedules/{id}', 'schedules', $single, [ScheduleHandler::class, 'get']),
            new OperationDescriptor('createSchedule', 'POST', '/api/v1/schedules', 'schedules', $single, [ScheduleHandler::class, 'create']),
            new OperationDescriptor('updateSchedule', 'PATCH', '/api/v1/schedules/{id}', 'schedules', $single, [ScheduleHandler::class, 'update']),
            new OperationDescriptor('deleteSchedule', 'DELETE', '/api/v1/schedules/{id}', 'schedules', $single, [ScheduleHandler::class, 'delete']),
            new OperationDescriptor('triggerSchedule', 'POST', '/api/v1/schedules/{id}/trigger', 'schedules', $single, [ScheduleHandler::class, 'trigger']),

            // --- mcp profile (route handlers bound as first-class callables to these methods) ---
            new OperationDescriptor('listMcpServers', 'GET', '/api/v1/mcp/servers', 'mcp', $single, [McpServerHandler::class, 'handleList']),
            new OperationDescriptor('createMcpServer', 'POST', '/api/v1/mcp/servers', 'mcp', $single, [McpServerHandler::class, 'handleCreate']),
            new OperationDescriptor('getMcpServer', 'GET', '/api/v1/mcp/servers/{name}', 'mcp', $single, [McpServerHandler::class, 'handleGet']),

            // --- Core ops (no profile) ---
            new OperationDescriptor('listSessions', 'GET', '/api/v1/sessions', null, $list, [SessionHandler::class, 'list']),
            new OperationDescriptor('createSession', 'POST', '/api/v1/sessions', null, $single, [SessionHandler::class, 'create']),
            new OperationDescriptor('updateSession', 'PATCH', '/api/v1/sessions/{id}', null, $single, [SessionHandler::class, 'update']),
            new OperationDescriptor('deleteSession', 'DELETE', '/api/v1/sessions/{id}', null, $single, [SessionHandler::class, 'delete']),
            new OperationDescriptor('addSessionMember', 'POST', '/api/v1/sessions/{id}/members', null, $single, [SessionHandler::class, 'addMember']),
            new OperationDescriptor('removeSessionMember', 'DELETE', '/api/v1/sessions/{id}/members/{persona}', null, $single, [SessionHandler::class, 'removeMember']),
            new OperationDescriptor('listRoles', 'GET', '/api/v1/roles', null, $list, [RoleHandler::class, 'list']),
            new OperationDescriptor('putRole', 'PUT', '/api/v1/roles/{name}', null, $single, [RoleHandler::class, 'put']),
            new OperationDescriptor('listPersonas', 'GET', '/api/v1/personas', null, $list, [ConfigHandler::class, 'personas']),
            new OperationDescriptor('createPersona', 'POST', '/api/v1/personas', null, $single, [ConfigHandler::class, 'createPersona']),
            new OperationDescriptor('updatePersona', 'PATCH', '/api/v1/personas/{name}', null, $single, [ConfigHandler::class, 'updatePersona']),
            new OperationDescriptor('deletePersona', 'DELETE', '/api/v1/personas/{name}', null, $single, [ConfigHandler::class, 'deletePersona']),
            new OperationDescriptor('listLoopDefinitions', 'GET', '/api/v1/loops/definitions', null, $list, [LoopHandler::class, 'definitions']),
        ];
    }

    /**
     * The descriptor for `$operationId`, or null when the catalog has no such op.
     */
    public static function forId(string $operationId): ?OperationDescriptor
    {
        foreach (self::all() as $descriptor) {
            if ($descriptor->operationId === $operationId) {
                return $descriptor;
            }
        }

        return null;
    }
}
