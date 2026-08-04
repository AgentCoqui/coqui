<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Export;

/**
 * The typed export collection map — the single source of truth for which
 * collections the CAP 0.5.0 export/import envelope carries and which object
 * schema each collection's items validate against.
 *
 * Every Core object collection AND every internal (diagnostics-only) collection
 * (jobs, job_events, audit_records) is enumerated here, so a schema-valid export
 * envelope can be assembled and each collection typed. The map MUST stay in lockstep
 * with `spec/schema/export.json`'s collection property set; the conformance suite
 * asserts they are identical (drift guard).
 *
 * The preserve+remap roundtrip IMPORT (FK rewrite) is a Phase 6 gate and is
 * deliberately NOT modeled here — Phase 2 proves per-collection typing/producibility
 * only.
 */
final class ExportCollectionMap
{
    /**
     * Collection name => standalone object schema filename. Ordered to mirror
     * export.json. `session_members` is intentionally absent: it is a join
     * collection whose items are typed INLINE by export.json ({session_id,
     * persona_id}) and has no standalone object schema — see {@see names()}.
     *
     * @return array<string, string>
     */
    public static function schemas(): array
    {
        return [
            'personas' => 'persona.json',
            'sessions' => 'session.json',
            'turns' => 'turn.json',
            'messages' => 'message.json',
            'content' => 'content.json',
            'roles' => 'role.json',
            'loop_definitions' => 'loop-definition.json',
            'loops' => 'loop.json',
            'loop_iterations' => 'loop-iteration.json',
            'loop_stages' => 'loop-stage.json',
            'memories' => 'memory.json',
            'jobs' => 'job.json',
            'job_events' => 'job-event.json',
            'audit_records' => 'audit-record.json',
            'child_runs' => 'child-run.json',
            'skills' => 'skill.json',
            'artifacts' => 'artifact.json',
            'questions' => 'question.json',
            'scheduled_tasks' => 'scheduled-task.json',
        ];
    }

    /**
     * Every collection name the envelope types — the standalone-schema collections
     * plus the inline-typed `session_members` join collection.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [...array_keys(self::schemas()), 'session_members'];
    }

    /**
     * Whether the export envelope types a collection of this name.
     */
    public function has(string $collection): bool
    {
        return in_array($collection, self::names(), true);
    }

    /**
     * The internal, diagnostics-only collections (typed for export validation but
     * not part of the public Core object surface).
     *
     * @return list<string>
     */
    public static function internalCollections(): array
    {
        return ['jobs', 'job_events', 'audit_records'];
    }

    /**
     * Project a session-membership pair to its wire shape. session_members is a
     * join collection with no dedicated object schema; each item is the
     * {session_id, persona_id} pair the export.json envelope inlines.
     *
     * @return array<string, string>
     */
    public static function sessionMemberToWire(string $sessionId, string $personaId): array
    {
        return [
            'session_id' => $sessionId,
            'persona_id' => $personaId,
        ];
    }
}
