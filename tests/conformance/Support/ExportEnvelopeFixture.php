<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance\Support;

use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Api\Handler\TurnHandler;
use CoquiBot\Coqui\Content\ContentStore;
use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Export\ContentProducer;
use CoquiBot\Coqui\Export\LoopIterationProducer;
use CoquiBot\Coqui\Export\LoopStageProducer;
use CoquiBot\Coqui\Export\MessageProducer;
use CoquiBot\Coqui\Persona\PersonaSnapshotStore;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use PDO;

/**
 * Shared seeding + DB-driven assembly for the export/import roundtrip.
 *
 * {@see seed()} populates a live store with one row per DB-backed Core collection
 * (mirroring the canonical export fixture); {@see assemble()} reads that store back
 * through the export producers into an envelope. Using ONE assembler for both the
 * source and the re-imported store makes the import roundtrip a true equality check
 * (both sides normalize identically), so any divergence is a real import defect.
 *
 * Only DB-backed collections are covered. File-authored objects (`roles`,
 * `loop_definitions`) and the internal diagnostics collections are export-only and
 * out of scope for a store-level roundtrip.
 */
final class ExportEnvelopeFixture
{
    public const string PERSONA_ID = 'persona_caelum';

    /**
     * Seed one row per DB-backed collection into a fresh store.
     */
    public static function seed(SessionStorage $storage, string $workspace): void
    {
        $pdo = $storage->pdo();

        // personas — no public id-preserving insert exists, so seed the row directly.
        $pdo->prepare(
            'INSERT INTO personas (id, name, avatar, model, allowed_roles, soul, backstory, context, preferences, version, created_at, updated_at)'
            . ' VALUES (:id, :name, :avatar, :model, :allowed_roles, :soul, :backstory, :context, :preferences, :version, :created_at, :updated_at)'
        )->execute([
            ':id' => self::PERSONA_ID,
            ':name' => 'Caelum',
            ':avatar' => json_encode(['tint' => '#2b3a52'], JSON_THROW_ON_ERROR),
            ':model' => 'anthropic/claude-sonnet-4',
            ':allowed_roles' => json_encode(['orchestrator'], JSON_THROW_ON_ERROR),
            ':soul' => 'You are Caelum, a warm, precise research companion.',
            ':backstory' => null,
            ':context' => null,
            ':preferences' => null,
            ':version' => 1,
            ':created_at' => '2026-07-28T00:00:00Z',
            ':updated_at' => '2026-07-28T00:00:00Z',
        ]);

        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', self::PERSONA_ID);
        $turnId = $storage->createTurn($sessionId, 'Summarize the suite.', 'anthropic/claude-sonnet-4', null, self::PERSONA_ID);

        $content = new ContentStore($pdo);
        $blob = $content->store("hello, content\n", 'text/markdown');

        $messageId = $storage->addMessage(
            $sessionId,
            'assistant',
            'The suite validates Core objects.',
            null,
            null,
            $turnId,
            'Caelum',
            'orchestrator',
        );
        // One typed attachment exercises the content-ref + attachment FK path.
        $pdo->prepare(
            'INSERT INTO message_attachments (message_id, content_ref, mime_type) VALUES (:m, :c, :t)'
        )->execute([
            ':m' => $messageId,
            ':c' => $blob['content_ref'],
            ':t' => 'image/png',
        ]);

        $loopStore = new LoopStore($pdo);
        $loopId = $loopStore->createLoop(
            definitionName: 'code-review-loop',
            goal: 'Converge on a clean review.',
            configuration: ['name' => 'code-review-loop'],
            sessionId: $sessionId,
            personaId: self::PERSONA_ID,
            maxIterations: 5,
        );
        $iterationId = $loopStore->createIteration($loopId, 1);
        $loopStore->createStage($iterationId, 0, 'coder');

        $storage->createChildRun(
            parentSessionId: $sessionId,
            role: 'coder',
            model: 'anthropic/claude-sonnet-4',
            prompt: 'Implement the fix.',
            status: 'completed',
            result: 'Fixed.',
            promptTokens: 80,
            completionTokens: 20,
            totalTokens: 100,
        );

        $skills = new SkillLifecycleStore($pdo);
        $skills->upsertSkill(
            name: 'summarize',
            description: 'Condense documents.',
            status: 'available',
            origin: ['kind' => 'builtin'],
            execution: ['kind' => 'instruction', 'requires' => []],
        );

        $artifacts = new ArtifactStore($pdo, new ArtifactFileService($workspace));
        $artifacts->create($sessionId, 'Design Doc', "# Title\nbody\n", 'document', createdBy: 'coder');

        $request = new QuestionRequest(
            id: '01J000000000000000000QEXPORT',
            prompt: 'Proceed?',
            format: QuestionFormat::SingleSelect,
            options: [new QuestionOption('yes', 'Yes'), new QuestionOption('no', 'No')],
            allowOther: false,
            suggested: new QuestionResponse(selected: ['yes']),
        );
        $storage->createQuestion($sessionId, $request, 'interactive');
        $storage->recordQuestionAnswer($request->id, new QuestionResponse(selected: ['yes']));

        $schedules = new ScheduleStore($pdo);
        $schedules->create(
            name: 'daily-review',
            scheduleExpression: '0 9 * * 1-5',
            action: ['kind' => 'turn', 'prompt' => 'Review recent changes.'],
            personaId: self::PERSONA_ID,
        );
    }

    public const string SECOND_PERSONA_ID = 'persona_nova';

    /**
     * Seed a SECOND persona and a group session that keeps {@see PERSONA_ID} as its
     * OWNER (a wire session requires a non-empty owner `persona_id`) while adding the
     * second persona as a NON-owner member. That populates the `session_group_members`
     * join with a real non-owner row — the path the solo-owner {@see seed()} never
     * exercises — so the remap has a `session_members.{session_id, persona_id}` join
     * to rewrite.
     */
    public static function seedGroupMember(SessionStorage $storage): void
    {
        $pdo = $storage->pdo();

        $pdo->prepare(
            'INSERT INTO personas (id, name, avatar, model, allowed_roles, soul, backstory, context, preferences, version, created_at, updated_at)'
            . ' VALUES (:id, :name, :avatar, :model, :allowed_roles, :soul, :backstory, :context, :preferences, :version, :created_at, :updated_at)'
        )->execute([
            ':id' => self::SECOND_PERSONA_ID,
            ':name' => 'Nova',
            ':avatar' => json_encode(['tint' => '#4a2b52'], JSON_THROW_ON_ERROR),
            ':model' => 'anthropic/claude-sonnet-4',
            ':allowed_roles' => json_encode(['orchestrator'], JSON_THROW_ON_ERROR),
            ':soul' => 'You are Nova, a rigorous reviewer.',
            ':backstory' => null,
            ':context' => null,
            ':preferences' => null,
            ':version' => 1,
            ':created_at' => '2026-07-28T00:00:01Z',
            ':updated_at' => '2026-07-28T00:00:01Z',
        ]);

        // Owner = PERSONA_ID; promote the row to a group session and add the second
        // persona as a non-owner member row.
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', self::PERSONA_ID);
        $pdo->prepare("UPDATE sessions SET session_type = 'group', group_enabled = 1 WHERE id = :id")
            ->execute([':id' => $sessionId]);
        $pdo->prepare(
            'INSERT INTO session_group_members (session_id, persona_id, member_order, created_at)'
            . ' VALUES (:session_id, :persona_id, :member_order, :created_at)'
        )->execute([
            ':session_id' => $sessionId,
            ':persona_id' => self::SECOND_PERSONA_ID,
            ':member_order' => 0,
            ':created_at' => '2026-07-28T00:00:02Z',
        ]);
    }

    /**
     * Assemble the DB-backed export envelope from a live store.
     *
     * @return array<string, mixed>
     */
    public static function assemble(SessionStorage $storage): array
    {
        $pdo = $storage->pdo();
        $env = [
            'protocol_version' => '0.5.0',
            'exported_at' => '2026-07-28T00:00:03Z',
        ];

        $personas = [];
        foreach (self::rows($pdo, 'SELECT * FROM personas ORDER BY id') as $row) {
            $personas[] = PersonaSnapshotStore::toWire($row);
        }
        self::put($env, 'personas', $personas);

        $sessions = [];
        $members = [];
        $messages = [];
        $childRuns = [];
        foreach (self::column($pdo, 'SELECT id FROM sessions ORDER BY created_at, id') as $sid) {
            $srow = $storage->getSession($sid);
            if ($srow === null) {
                continue;
            }
            $wire = SessionHandler::toWire($srow);
            $sessions[] = $wire;
            foreach ($wire['members'] as $member) {
                $members[] = ['session_id' => $wire['id'], 'persona_id' => $member];
            }
            foreach ($storage->getMessages($sid) as $messageRow) {
                $messages[] = MessageProducer::toWire($messageRow);
            }
            foreach ($storage->getChildRuns($sid) as $childRow) {
                $childRuns[] = SessionHandler::childRunToWire($childRow);
            }
        }
        self::put($env, 'sessions', $sessions);
        self::put($env, 'session_members', $members);

        $turns = [];
        foreach (self::column($pdo, 'SELECT id FROM turns ORDER BY created_at, id') as $tid) {
            $trow = $storage->getTurn($tid);
            if ($trow !== null) {
                $turns[] = TurnHandler::toWire($trow);
            }
        }
        self::put($env, 'turns', $turns);

        $content = [];
        foreach (self::rows($pdo, 'SELECT content_ref, mime_type, size, sha256, created_at FROM content ORDER BY created_at, content_ref') as $row) {
            $content[] = ContentProducer::toWire($row);
        }
        self::put($env, 'content', $content);

        self::put($env, 'messages', $messages);

        $skills = [];
        foreach (self::rows($pdo, 'SELECT * FROM skills ORDER BY name') as $row) {
            $skills[] = SkillLifecycleStore::toWire($row);
        }
        self::put($env, 'skills', $skills);

        $loops = [];
        foreach (self::rows($pdo, 'SELECT * FROM loops ORDER BY started_at, id') as $row) {
            $loops[] = LoopStore::toWire($row);
        }
        self::put($env, 'loops', $loops);

        $iterations = [];
        foreach (self::rows($pdo, 'SELECT * FROM loop_iterations ORDER BY iteration_number, id') as $row) {
            $iterations[] = LoopIterationProducer::toWire($row);
        }
        self::put($env, 'loop_iterations', $iterations);

        $stages = [];
        foreach (self::rows($pdo, 'SELECT * FROM loop_stages ORDER BY stage_index, id') as $row) {
            $stages[] = LoopStageProducer::toWire($row);
        }
        self::put($env, 'loop_stages', $stages);

        self::put($env, 'child_runs', $childRuns);

        $questions = [];
        foreach (self::column($pdo, 'SELECT id FROM questions ORDER BY created_at, id') as $qid) {
            $qrow = $storage->getQuestion($qid);
            if ($qrow !== null) {
                $questions[] = QuestionPersistence::toWire($qrow);
            }
        }
        self::put($env, 'questions', $questions);

        $artifacts = [];
        foreach (self::rows($pdo, 'SELECT * FROM artifacts ORDER BY created_at, id') as $row) {
            $artifacts[] = ArtifactStore::toWire($row);
        }
        self::put($env, 'artifacts', $artifacts);

        $schedules = [];
        foreach (self::rows($pdo, 'SELECT * FROM scheduled_tasks ORDER BY created_at, id') as $row) {
            $schedules[] = ScheduleStore::toWire($row);
        }
        self::put($env, 'scheduled_tasks', $schedules);

        return $env;
    }

    /**
     * Order-insensitive canonical form for envelope equality: objects flatten to
     * assoc arrays, assoc keys sort, list order is preserved.
     *
     * @param array<string, mixed> $envelope
     */
    public static function canonical(array $envelope): string
    {
        return (string) json_encode(self::normalize($envelope), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $key => $child) {
            $out[$key] = self::normalize($child);
        }

        if (!$isList) {
            ksort($out);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $env
     * @param list<mixed> $items
     */
    private static function put(array &$env, string $collection, array $items): void
    {
        if ($items !== []) {
            $env[$collection] = $items;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql);

        return $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<string>
     */
    private static function column(PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }

        return array_map(static fn($v): string => (string) $v, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
