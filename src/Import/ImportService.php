<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Import;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Exception\RequestBodyException;
use CoquiBot\Coqui\Export\ExportCollectionMap;
use PDO;
use PDOException;

/**
 * Imports a CAP 0.5.0 export envelope into a coqui store (CORE-56, part 1).
 *
 * Import is in-process only — there is no `/import` HTTP route. The service takes
 * a live envelope (as produced by the export producers) and replays its DB-backed
 * collections into the target store's tables in foreign-key-dependency order,
 * inside a SINGLE transaction: a mid-import failure rolls the whole thing back so
 * a malformed or conflicting envelope is rejected, never half-applied.
 *
 * PRESERVE mode (this task) inserts every row with its ORIGINAL id verbatim; a
 * primary-key collision aborts the import with `conflict`. REMAP mode — which
 * mints fresh ids and rewrites every foreign key — arrives in Task 11.
 *
 * Only DB-backed collections are persisted. File-authored objects (`roles`,
 * `loop_definitions`) have no table, and the internal diagnostics collections
 * (`jobs`, `job_events`, `audit_records`) are not part of the restorable object
 * surface; both are validated when present but recorded as skipped, not inserted.
 * `memories` is deferred to the Memory Core reshape and is likewise not persisted.
 */
final class ImportService
{
    /**
     * Present-but-not-persisted collections: file-authored objects (no table),
     * the deferred `memories`, and the internal diagnostics collections.
     *
     * @var list<string>
     */
    private const array NON_PERSISTED = [
        'roles',
        'loop_definitions',
        'memories',
        'jobs',
        'job_events',
        'audit_records',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly EnvelopeItemValidator $validator,
    ) {}

    /**
     * Import $envelope into the target store under $mode.
     *
     * @param array<string, mixed> $envelope
     */
    public function import(array $envelope, ImportMode $mode): ImportResult
    {
        if ($mode === ImportMode::Remap) {
            throw new \RuntimeException(
                'Import remap mode is not implemented yet (CORE-56 part 2, Task 11).',
            );
        }

        $this->validateEnvelope($envelope);

        return $this->transactionally(fn(): ImportResult => $this->persist($envelope));
    }

    /**
     * Validate the envelope and every schema-backed collection item BEFORE any
     * write, so a malformed envelope is rejected rather than half-imported.
     *
     * @param array<string, mixed> $envelope
     */
    private function validateEnvelope(array $envelope): void
    {
        if (!$this->validator->isValid('export.json', $envelope)) {
            throw new RequestBodyException(
                ApiErrorCode::VALIDATION_ERROR,
                'Import envelope failed export.json validation.',
                ['error' => $this->validator->errorText('export.json', $envelope)],
            );
        }

        foreach (ExportCollectionMap::schemas() as $collection => $schema) {
            foreach ($this->itemsOf($envelope, $collection) as $item) {
                if (!$this->validator->isValid($schema, $item)) {
                    throw new RequestBodyException(
                        ApiErrorCode::VALIDATION_ERROR,
                        sprintf('Import collection "%s" has a row that failed %s.', $collection, $schema),
                        ['collection' => $collection, 'error' => $this->validator->errorText($schema, $item)],
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function persist(array $envelope): ImportResult
    {
        $counts = [];
        $owners = $this->collectSessionOwners($envelope);

        $counts['personas'] = $this->insertPersonas($envelope);
        $counts['sessions'] = $this->insertSessions($envelope);
        $counts['session_members'] = $this->insertSessionMembers($envelope, $owners);
        $counts['turns'] = $this->insertTurns($envelope);
        $counts['content'] = $this->insertContent($envelope);
        $counts['messages'] = $this->insertMessages($envelope);
        $counts['skills'] = $this->insertSkills($envelope);
        $counts['loops'] = $this->insertLoops($envelope);
        $counts['loop_iterations'] = $this->insertLoopIterations($envelope);
        $counts['loop_stages'] = $this->insertLoopStages($envelope);
        $counts['child_runs'] = $this->insertChildRuns($envelope);
        $counts['questions'] = $this->insertQuestions($envelope);
        $counts['artifacts'] = $this->insertArtifacts($envelope);
        $counts['scheduled_tasks'] = $this->insertScheduledTasks($envelope);

        $skipped = [];
        foreach (self::NON_PERSISTED as $name) {
            if ($this->itemsOf($envelope, $name) !== []) {
                $skipped[] = $name;
            }
        }

        return new ImportResult($counts, $skipped);
    }

    // ── collection inserts ──────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertPersonas(array $envelope): int
    {
        $sql = 'INSERT INTO personas (id, name, avatar, model, allowed_roles, soul, backstory, context, preferences, version, created_at, updated_at)'
            . ' VALUES (:id, :name, :avatar, :model, :allowed_roles, :soul, :backstory, :context, :preferences, :version, :created_at, :updated_at)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'personas') as $item) {
            $this->insert($sql, [
                ':id' => $this->str($item, 'id'),
                ':name' => $this->str($item, 'name'),
                ':avatar' => $this->json($item['avatar'] ?? null) ?? '{}',
                ':model' => $this->str($item, 'model'),
                ':allowed_roles' => $this->json($item['allowed_roles'] ?? null) ?? '[]',
                ':soul' => $this->str($item, 'soul'),
                ':backstory' => $this->nullStr($item, 'backstory'),
                ':context' => $this->json($item['context'] ?? null),
                ':preferences' => $this->json($item['preferences'] ?? null),
                ':version' => $this->int($item, 'version', 1),
                ':created_at' => $this->str($item, 'created_at'),
                ':updated_at' => $this->str($item, 'updated_at'),
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertSessions(array $envelope): int
    {
        $sql = 'INSERT INTO sessions (id, persona_id, model_role, model, kind, pinned, workspace, version, title, session_type, group_enabled, is_closed, is_archived, token_count, created_at, updated_at)'
            . ' VALUES (:id, :persona_id, :model_role, :model, :kind, :pinned, :workspace, :version, :title, :session_type, :group_enabled, :is_closed, :is_archived, :token_count, :created_at, :updated_at)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'sessions') as $item) {
            $persona = $this->str($item, 'persona_id');
            $isGroup = count($this->memberList($item)) > 1;
            $status = $this->str($item, 'status');
            $kind = $this->str($item, 'kind');

            $this->insert($sql, [
                ':id' => $this->str($item, 'id'),
                ':persona_id' => $persona !== '' ? $persona : null,
                // model_role is a NOT NULL operational column the wire never carries
                // and no producer reads back; a stable placeholder keeps the row valid.
                ':model_role' => 'orchestrator',
                ':model' => $this->nullStr($item, 'model'),
                ':kind' => $kind !== '' ? $kind : 'chat',
                ':pinned' => $this->bool($item, 'pinned') ? 1 : 0,
                ':workspace' => $this->nullStr($item, 'workspace'),
                ':version' => $this->int($item, 'version', 1),
                ':title' => $this->nullStr($item, 'title'),
                ':session_type' => $isGroup ? 'group' : 'interactive',
                ':group_enabled' => $isGroup ? 1 : 0,
                ':is_closed' => $status === 'closed' ? 1 : 0,
                ':is_archived' => $status === 'archived' ? 1 : 0,
                ':token_count' => $this->int($item, 'token_count', 0),
                ':created_at' => $this->str($item, 'created_at'),
                ':updated_at' => $this->str($item, 'updated_at'),
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * Membership is carried on the wire by `sessions.persona_id` (the owner) plus
     * this join; the owner is already stored on the session row, so only the
     * non-owner members become `session_group_members` rows.
     *
     * @param array<string, mixed> $envelope
     * @param array<string, string> $owners session_id => owner persona_id
     */
    private function insertSessionMembers(array $envelope, array $owners): int
    {
        $sql = 'INSERT INTO session_group_members (session_id, persona_id, member_order, created_at)'
            . ' VALUES (:session_id, :persona_id, :member_order, :created_at)';

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $order = [];
        $n = 0;
        foreach ($this->itemsOf($envelope, 'session_members') as $item) {
            $sessionId = $this->str($item, 'session_id');
            $personaId = $this->str($item, 'persona_id');
            if ($sessionId === '' || $personaId === '') {
                throw new RequestBodyException(
                    ApiErrorCode::VALIDATION_ERROR,
                    'session_members entry is missing session_id or persona_id.',
                    ['collection' => 'session_members'],
                );
            }

            if (($owners[$sessionId] ?? null) === $personaId) {
                continue;
            }

            $ord = $order[$sessionId] ?? 0;
            $order[$sessionId] = $ord + 1;
            $this->insert($sql, [
                ':session_id' => $sessionId,
                ':persona_id' => $personaId,
                ':member_order' => $ord,
                ':created_at' => $now,
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertTurns(array $envelope): int
    {
        $sql = 'INSERT INTO turns (id, session_id, actor_persona_id, turn_number, user_prompt, response_text, model, prompt_tokens, completion_tokens, total_tokens, iterations, duration_ms, tools_used, status, created_at, completed_at)'
            . ' VALUES (:id, :session_id, :actor_persona_id, :turn_number, :user_prompt, :response_text, :model, :prompt_tokens, :completion_tokens, :total_tokens, :iterations, :duration_ms, :tools_used, :status, :created_at, :completed_at)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'turns') as $item) {
            $status = $this->str($item, 'status');
            $this->insert($sql, [
                ':id' => $this->str($item, 'id'),
                ':session_id' => $this->str($item, 'session_id'),
                ':actor_persona_id' => $this->nullStr($item, 'actor_persona_id'),
                ':turn_number' => $this->int($item, 'turn_number', 1),
                ':user_prompt' => $this->str($item, 'user_prompt'),
                ':response_text' => $this->nullStr($item, 'response_text'),
                ':model' => $this->nullStr($item, 'model'),
                ':prompt_tokens' => $this->int($item, 'prompt_tokens', 0),
                ':completion_tokens' => $this->int($item, 'completion_tokens', 0),
                ':total_tokens' => $this->int($item, 'total_tokens', 0),
                ':iterations' => $this->int($item, 'iterations', 0),
                ':duration_ms' => $this->int($item, 'duration_ms', 0),
                ':tools_used' => $this->json($item['tools_used'] ?? null),
                ':status' => $status !== '' ? $status : 'running',
                ':created_at' => $this->str($item, 'created_at'),
                ':completed_at' => $this->nullStr($item, 'completed_at'),
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertContent(array $envelope): int
    {
        // The envelope carries content-addressed METADATA only (never the bytes),
        // so a placeholder blob satisfies the NOT NULL column; no producer reads it.
        $sql = 'INSERT INTO content (content_ref, mime_type, size, sha256, created_at, bytes)'
            . ' VALUES (:content_ref, :mime_type, :size, :sha256, :created_at, :bytes)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'content') as $item) {
            $this->insert($sql, [
                ':content_ref' => $this->str($item, 'content_ref'),
                ':mime_type' => $this->str($item, 'mime_type'),
                ':size' => $this->int($item, 'size', 0),
                ':sha256' => $this->str($item, 'sha256'),
                ':created_at' => $this->str($item, 'created_at'),
                ':bytes' => '',
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertMessages(array $envelope): int
    {
        $sql = 'INSERT INTO messages (id, session_id, turn_id, role, content, tool_calls, tool_call_id, actor_name, actor_role, created_at)'
            . ' VALUES (:id, :session_id, :turn_id, :role, :content, :tool_calls, :tool_call_id, :actor_name, :actor_role, :created_at)';
        $attachmentSql = 'INSERT INTO message_attachments (message_id, content_ref, mime_type)'
            . ' VALUES (:message_id, :content_ref, :mime_type)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'messages') as $item) {
            $id = $this->str($item, 'id');
            $role = $this->str($item, 'role');
            $this->insert($sql, [
                ':id' => $id,
                ':session_id' => $this->str($item, 'session_id'),
                ':turn_id' => $this->nullStr($item, 'turn_id'),
                ':role' => $role !== '' ? $role : 'assistant',
                ':content' => $this->str($item, 'content'),
                ':tool_calls' => $this->json($item['tool_calls'] ?? null),
                ':tool_call_id' => $this->nullStr($item, 'tool_call_id'),
                ':actor_name' => $this->nullStr($item, 'actor_name'),
                ':actor_role' => $this->nullStr($item, 'actor_role'),
                ':created_at' => $this->str($item, 'created_at'),
            ]);
            $n++;

            $attachments = $item['attachments'] ?? null;
            if (is_array($attachments)) {
                foreach ($attachments as $attachment) {
                    if (!is_array($attachment)) {
                        continue;
                    }
                    $this->insert($attachmentSql, [
                        ':message_id' => $id,
                        ':content_ref' => $this->str($attachment, 'content_ref'),
                        ':mime_type' => $this->str($attachment, 'mime_type'),
                    ]);
                }
            }
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertSkills(array $envelope): int
    {
        $sql = 'INSERT INTO skills (name, description, metadata, source, status, origin, execution, created_at, updated_at)'
            . ' VALUES (:name, :description, :metadata, :source, :status, :origin, :execution, :created_at, :updated_at)';

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $n = 0;
        foreach ($this->itemsOf($envelope, 'skills') as $item) {
            $status = $this->str($item, 'status');
            $this->insert($sql, [
                ':name' => $this->str($item, 'name'),
                ':description' => $this->nullStr($item, 'description'),
                ':metadata' => $this->json($item['metadata'] ?? null),
                ':source' => $this->nullStr($item, 'source'),
                ':status' => $status !== '' ? $status : 'available',
                ':origin' => $this->json($item['origin'] ?? null) ?? '{}',
                ':execution' => $this->json($item['execution'] ?? null) ?? '{}',
                ':created_at' => $this->nullStr($item, 'created_at') ?? $now,
                ':updated_at' => $this->nullStr($item, 'updated_at') ?? $now,
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertLoops(array $envelope): int
    {
        $sql = 'INSERT INTO loops (id, definition_name, persona_id, session_id, goal, status, current_iteration, current_stage, max_iterations, deadline, termination_criteria, configuration, origin, started_at, completed_at, last_activity_at, rework_attempts, dispatch_state, last_dispatch_error, metadata)'
            . ' VALUES (:id, :definition_name, :persona_id, :session_id, :goal, :status, :current_iteration, :current_stage, :max_iterations, :deadline, :termination_criteria, :configuration, :origin, :started_at, :completed_at, :last_activity_at, :rework_attempts, :dispatch_state, :last_dispatch_error, :metadata)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'loops') as $item) {
            $status = $this->str($item, 'status');
            $origin = $this->str($item, 'origin');
            $dispatch = $this->str($item, 'dispatch_state');
            $maxIterations = array_key_exists('max_iterations', $item) && $item['max_iterations'] !== null
                ? $this->int($item, 'max_iterations', 0)
                : null;

            $this->insert($sql, [
                ':id' => $this->str($item, 'id'),
                ':definition_name' => $this->str($item, 'definition_name'),
                ':persona_id' => $this->nullStr($item, 'persona_id'),
                ':session_id' => $this->nullStr($item, 'session_id'),
                ':goal' => $this->str($item, 'goal'),
                ':status' => $status !== '' ? $status : 'running',
                ':current_iteration' => $this->int($item, 'current_iteration', 0),
                ':current_stage' => $this->int($item, 'current_stage', 0),
                ':max_iterations' => $maxIterations,
                ':deadline' => $this->nullStr($item, 'deadline'),
                ':termination_criteria' => $this->json($item['termination_criteria'] ?? null),
                ':configuration' => $this->json($item['configuration'] ?? null),
                ':origin' => $origin !== '' ? $origin : 'conversation',
                // The wire renames `started_at` to `created_at`; restore the column.
                ':started_at' => $this->str($item, 'created_at'),
                ':completed_at' => $this->nullStr($item, 'completed_at'),
                ':last_activity_at' => $this->nullStr($item, 'last_activity_at'),
                ':rework_attempts' => $this->int($item, 'rework_attempts', 0),
                ':dispatch_state' => $dispatch !== '' ? $dispatch : 'pending',
                ':last_dispatch_error' => $this->nullStr($item, 'last_dispatch_error'),
                ':metadata' => $this->json($item['metadata'] ?? null),
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertLoopIterations(array $envelope): int
    {
        $sql = 'INSERT INTO loop_iterations (id, loop_id, iteration_number, status, outcome_summary, started_at, completed_at)'
            . ' VALUES (:id, :loop_id, :iteration_number, :status, :outcome_summary, :started_at, :completed_at)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'loop_iterations') as $item) {
            $status = $this->str($item, 'status');
            $this->insert($sql, [
                ':id' => $this->str($item, 'id'),
                ':loop_id' => $this->str($item, 'loop_id'),
                ':iteration_number' => $this->int($item, 'iteration_number', 1),
                ':status' => $status !== '' ? $status : 'pending',
                ':outcome_summary' => $this->nullStr($item, 'outcome_summary'),
                ':started_at' => $this->nullStr($item, 'started_at'),
                ':completed_at' => $this->nullStr($item, 'completed_at'),
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertLoopStages(array $envelope): int
    {
        $sql = 'INSERT INTO loop_stages (id, iteration_id, stage_index, role, task_id, artifact_id, status, result_summary, started_at, completed_at, verdict)'
            . ' VALUES (:id, :iteration_id, :stage_index, :role, :task_id, :artifact_id, :status, :result_summary, :started_at, :completed_at, :verdict)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'loop_stages') as $item) {
            $status = $this->str($item, 'status');
            $this->insert($sql, [
                ':id' => $this->str($item, 'id'),
                ':iteration_id' => $this->str($item, 'iteration_id'),
                ':stage_index' => $this->int($item, 'stage_index', 0),
                ':role' => $this->str($item, 'role'),
                // The wire renames the coqui `task_id` column to `job_id`.
                ':task_id' => $this->nullStr($item, 'job_id'),
                ':artifact_id' => $this->nullStr($item, 'artifact_id'),
                ':status' => $status !== '' ? $status : 'pending',
                ':result_summary' => $this->nullStr($item, 'result_summary'),
                ':started_at' => $this->nullStr($item, 'started_at'),
                ':completed_at' => $this->nullStr($item, 'completed_at'),
                ':verdict' => $this->json($item['verdict'] ?? null),
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertChildRuns(array $envelope): int
    {
        $sql = 'INSERT INTO child_runs (id, parent_session_id, parent_turn_id, role, model, prompt, result, status, prompt_tokens, completion_tokens, total_tokens, created_at, completed_at)'
            . ' VALUES (:id, :parent_session_id, :parent_turn_id, :role, :model, :prompt, :result, :status, :prompt_tokens, :completion_tokens, :total_tokens, :created_at, :completed_at)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'child_runs') as $item) {
            $status = $this->str($item, 'status');
            $this->insert($sql, [
                ':id' => $this->str($item, 'id'),
                ':parent_session_id' => $this->str($item, 'parent_session_id'),
                ':parent_turn_id' => $this->nullStr($item, 'parent_turn_id'),
                ':role' => $this->str($item, 'role'),
                ':model' => $this->nullStr($item, 'model'),
                ':prompt' => $this->str($item, 'prompt'),
                ':result' => $this->nullStr($item, 'result'),
                ':status' => $status !== '' ? $status : 'completed',
                ':prompt_tokens' => $this->int($item, 'prompt_tokens', 0),
                ':completion_tokens' => $this->int($item, 'completion_tokens', 0),
                ':total_tokens' => $this->int($item, 'total_tokens', 0),
                ':created_at' => $this->str($item, 'created_at'),
                ':completed_at' => $this->nullStr($item, 'completed_at'),
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertQuestions(array $envelope): int
    {
        $sql = 'INSERT INTO questions (id, session_id, turn_id, loop_id, stage_id, responder_kind, request, answer, status, created_at, answered_at)'
            . ' VALUES (:id, :session_id, :turn_id, :loop_id, :stage_id, :responder_kind, :request, :answer, :status, :created_at, :answered_at)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'questions') as $item) {
            $request = $this->rebuildQuestionRequest($item);
            $answer = $this->rebuildQuestionAnswer($item, $request);
            $status = $this->str($item, 'status');

            $this->insert($sql, [
                ':id' => $request->id,
                ':session_id' => $this->str($item, 'session_id'),
                // Internal correlation columns are not on the wire; import restores
                // the object surface only, so they stay null.
                ':turn_id' => null,
                ':loop_id' => null,
                ':stage_id' => null,
                ':responder_kind' => 'interactive',
                ':request' => json_encode($request->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':answer' => $answer === null
                    ? null
                    : json_encode($answer->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':status' => match ($status) {
                    'answered' => 'answered',
                    'cancelled' => 'cancelled',
                    default => 'pending',
                },
                ':created_at' => $this->str($item, 'created_at'),
                ':answered_at' => $this->nullStr($item, 'answered_at'),
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertArtifacts(array $envelope): int
    {
        $sql = 'INSERT INTO artifacts (id, session_id, turn_id, title, type, content, language, filepath, path, content_hash, version, metadata, created_at, updated_at)'
            . ' VALUES (:id, :session_id, :turn_id, :title, :type, :content, :language, :filepath, :path, :content_hash, :version, :metadata, :created_at, :updated_at)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'artifacts') as $item) {
            $type = $this->str($item, 'type');
            $createdAt = $this->str($item, 'created_at');
            $this->insert($sql, [
                ':id' => $this->str($item, 'id'),
                ':session_id' => $this->str($item, 'session_id'),
                ':turn_id' => null,
                // The wire renames `title` to `name`.
                ':title' => $this->str($item, 'name'),
                ':type' => $type !== '' ? $type : 'document',
                ':content' => '',
                ':language' => null,
                ':filepath' => null,
                // content_ref is projected from `path` (else content_hash); restore path.
                ':path' => $this->str($item, 'content_ref'),
                ':content_hash' => null,
                ':version' => 1,
                ':metadata' => $this->json($item['metadata'] ?? null),
                ':created_at' => $createdAt,
                ':updated_at' => $createdAt,
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function insertScheduledTasks(array $envelope): int
    {
        $sql = 'INSERT INTO scheduled_tasks (id, name, cron, persona_id, action_kind, prompt, definition_name, enabled, last_run_at, next_run_at, created_at, updated_at)'
            . ' VALUES (:id, :name, :cron, :persona_id, :action_kind, :prompt, :definition_name, :enabled, :last_run_at, :next_run_at, :created_at, :updated_at)';

        $n = 0;
        foreach ($this->itemsOf($envelope, 'scheduled_tasks') as $item) {
            $action = $item['action'] ?? null;
            $actionArr = $action instanceof \stdClass ? (array) $action : (is_array($action) ? $action : []);
            $kind = is_string($actionArr['kind'] ?? null) ? (string) $actionArr['kind'] : 'turn';
            $createdAt = $this->str($item, 'created_at');

            $this->insert($sql, [
                ':id' => $this->str($item, 'id'),
                ':name' => $this->str($item, 'name'),
                ':cron' => $this->str($item, 'cron'),
                ':persona_id' => $this->nullStr($item, 'persona_id'),
                ':action_kind' => $kind === 'loop' ? 'loop' : 'turn',
                ':prompt' => $kind === 'loop'
                    ? null
                    : (is_string($actionArr['prompt'] ?? null) ? (string) $actionArr['prompt'] : ''),
                ':definition_name' => $kind === 'loop'
                    ? (is_string($actionArr['definition_name'] ?? null) ? (string) $actionArr['definition_name'] : '')
                    : null,
                ':enabled' => $this->str($item, 'status') === 'disabled' ? 0 : 1,
                ':last_run_at' => $this->nullStr($item, 'last_run_at'),
                ':next_run_at' => $this->nullStr($item, 'next_run_at'),
                ':created_at' => $createdAt,
                ':updated_at' => $this->nullStr($item, 'updated_at') ?? $createdAt,
            ]);
            $n++;
        }

        return $n;
    }

    // ── question reconstruction ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $item
     */
    private function rebuildQuestionRequest(array $item): QuestionRequest
    {
        $format = match ($this->str($item, 'format')) {
            'single_select' => 'single_select',
            'multi_select' => 'multi_select',
            default => 'free_text',
        };

        $options = [];
        $wireOptions = $item['options'] ?? null;
        if (is_array($wireOptions)) {
            foreach ($wireOptions as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $options[] = [
                    // The producer maps option->label to the wire `value` and
                    // option->description to the wire `label`.
                    'label' => $this->str($option, 'value'),
                    'description' => $this->nullStr($option, 'label'),
                ];
            }
        }

        $labels = array_map(static fn(array $o): string => (string) $o['label'], $options);
        $isSelect = $format === 'single_select' || $format === 'multi_select';

        return QuestionRequest::fromArray([
            'id' => $this->str($item, 'id'),
            'prompt' => $this->str($item, 'prompt'),
            'format' => $format,
            'options' => $options,
            'allow_other' => $this->bool($item, 'allow_other'),
            'suggested' => $this->rebuildResponse($this->nullStr($item, 'suggested'), $labels, $isSelect),
        ]);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function rebuildQuestionAnswer(array $item, QuestionRequest $request): ?QuestionResponse
    {
        if (!array_key_exists('answer', $item) || $item['answer'] === null) {
            return null;
        }

        $labels = $request->optionLabels();
        $answer = $item['answer'];

        if (is_array($answer)) {
            $selected = array_values(array_filter($answer, is_string(...)));

            return new QuestionResponse(selected: $selected);
        }

        if (is_string($answer)) {
            return in_array($answer, $labels, true)
                ? new QuestionResponse(selected: [$answer])
                : new QuestionResponse(text: $answer);
        }

        return null;
    }

    /**
     * Rebuild a QuestionResponse (suggested or answer) from a scalar wire value.
     *
     * @param list<string> $labels
     * @return array<string, mixed>
     */
    private function rebuildResponse(?string $value, array $labels, bool $isSelect): array
    {
        if ($value === null) {
            return ['selected' => [], 'text' => null];
        }

        if ($isSelect && in_array($value, $labels, true)) {
            return ['selected' => [$value], 'text' => null];
        }

        return ['selected' => [], 'text' => $value];
    }

    // ── shared machinery ────────────────────────────────────────────────────

    /**
     * Run $fn inside a single transaction; roll back on any failure.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transactionally(callable $fn): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Prepare + execute an insert, translating a primary-key/uniqueness collision
     * into a `conflict` rejection (preserve mode inserts original ids verbatim).
     *
     * @param array<string, int|string|null> $params
     */
    private function insert(string $sql, array $params): void
    {
        try {
            $this->pdo->prepare($sql)->execute($params);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                throw new RequestBodyException(
                    ApiErrorCode::CONFLICT,
                    'Import conflict: a row with this id already exists in the target store.',
                    ['error' => $e->getMessage()],
                    409,
                );
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, string> session_id => owner persona_id
     */
    private function collectSessionOwners(array $envelope): array
    {
        $owners = [];
        foreach ($this->itemsOf($envelope, 'sessions') as $item) {
            $id = $this->str($item, 'id');
            if ($id !== '') {
                $owners[$id] = $this->str($item, 'persona_id');
            }
        }

        return $owners;
    }

    /**
     * The array items of a collection, dropping any non-object entries.
     *
     * @param array<string, mixed> $envelope
     * @return list<array<string, mixed>>
     */
    private function itemsOf(array $envelope, string $collection): array
    {
        $value = $envelope[$collection] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<mixed>
     */
    private function memberList(array $item): array
    {
        $members = $item['members'] ?? null;

        return is_array($members) ? $members : [];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function str(array $item, string $key): string
    {
        $value = $item[$key] ?? null;

        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function nullStr(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function int(array $item, string $key, int $default): int
    {
        $value = $item[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function bool(array $item, string $key): bool
    {
        return (bool) ($item[$key] ?? false);
    }

    /**
     * JSON-encode an object/array wire field for a TEXT column, or null.
     */
    private function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || $value instanceof \stdClass) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return null;
    }
}
