# SDD Progress — feat/audit-log-access

Plan: docs/superpowers/plans/2026-07-20-audit-log-redaction-and-access.md
Baseline: composer test 2393 passing / composer analyse [OK] 382/382

Pre-flight fixes applied to plan (commit pending):
- AuditRedactorInterface added; AuditRedactor is final so the fail-closed test
  could not subclass it. SessionStorage now depends on the interface.
- fakeCredentials() moved to tests/Pest.php (Task 2 runs its file standalone).

## Task 1 — AuditRedactor: DONE (04014a9)

Added src/Contract/AuditRedactorInterface.php, src/Storage/AuditRedactor.php,
tests/Unit/Storage/AuditRedactorTest.php, and the fakeCredentials() helper in
tests/Pest.php. No deviation from the brief.

- pest tests/Unit/Storage/AuditRedactorTest.php: 12 passed (22 assertions)
- pest (full suite): 2405 passed, 2 warnings, 1 skipped — no regressions
- phpstan (both new files): [OK] No errors

Carried forward for Task 2+ (all over-redaction, no leak; see task-1-report.md):
the bare `auth` key fragment also matches `author`/`oauth_*`; non-string scalars
under a sensitive key become the placeholder string; non-JSON-encodable objects
degrade to `[]` rather than `[REDACTED]`.

Task 1: complete (commits e80d0f5..952c467, review clean after 1 fix round)
  - Fixed 3 fail-open paths found in review: throwing keys()/get() disabled L1;
    preg_replace null kept raw text; array keys never redacted. All had revert-verified
    regression tests. Full suite 2412 passing.
  - Minor deferred to final: bare `auth` fragment over-matches author/oauth (over-redacts only).
Task 2: complete (commit 515f10d, review clean, no fix round)
  - logAudit fail-closed for both arguments and reason (independent try/catch, \Throwable).
  - Negative control reproduced by reviewer: fails with redaction disabled, passes restored.
  - Suite 2417 passing. Note: redaction inert in prod until Task 3 wires call sites.
Task 3: complete (commit 806119e, review clean, no fix round) — PART A COMPLETE, redaction LIVE
  - 6 sites wired with $boot->auditRedactor(); DoctorCommand passes explicit null
    (no BootManager, read-only health check, reviewer-verified never reaches logAudit).
  - Redactor built in initializeCredentials() after resolver, lazy discovery closure.
  - Wiring test scans src for new SessionStorage(, 7 sites 0 offenders. Suite 2419.
  - MINOR for final: wiring test accepts explicit `auditRedactor: null` even on a write
    path — add a boundary comment. Non-blocking (inherent to DoctorCommand design).
Task 4: complete (commits 61b6960, fe9e57e; review clean + 1 test-strengthening round)
  - AuditLogQuery (validated, clamps, throws on bad timestamp) + AuditLogStore (shared PDO,
    2 new indexes, after>= / before<, ORDER BY created_at DESC id DESC).
  - Fixed brief bug: turn_id FK required a real createTurn() fixture.
  - Strengthened pagination test (now fails without id tiebreaker) and after-boundary test
    (now fails if after filter dropped) into real negative controls. Suite 2427.
Task 5: complete (commit ff2307d, review clean, no fix round)
  - AuditHandler: GET /api/v1/audit + /sessions/{id}/audit, self-registering, authenticated.
  - Scope-widening blocked: unset(session_id) + forced sessionId:$id from path. Widening test
    checks actual row session_id (2-session insert), reviewer confirmed no cross-session path.
  - Envelope {entries,total,limit,offset}(+session_id). 400 on bad timestamp. Suite 2435.
Task 6: complete (commit d8bcc09, review clean, no fix round)
  - /audit REPL command, all 5 touch points wired (handler/catalog/router/RunCommand/tabcomplete).
  - Word-grammar filters; bare-filter-word shows Usage error (guard tested). Suite 2441.
  - MINORS for final: (a) RunCommand wiring guarded by phpstan required-param, not a test;
    (b) `/audit session <id>` grammar branch has no dedicated test.
Task 7: complete (commit 996e53a, review clean, no fix round) — ALL TASKS DONE
  - docs/API.md (audit endpoints, envelope, fields, errors) + docs/COMMANDS.md (/audit forms).
  - Reviewer independently traced every documented claim to source; no phantom docs.
  - MINOR for final: COMMANDS.md adds a 2nd adjacent table under Context Management (cosmetic).

FINAL whole-branch review: READY TO MERGE (all 5 Minors triaged acceptable-as-is).
  - No unredacted path to disk; negative control holds; no public route; non-goals respected.
  - Closed reviewer's one completeness note: added end-to-end fail-closed composition test
    (commit 2c61e68), verified as a real negative control. Suite 2442, analyse [OK].
ALL 7 TASKS COMPLETE + final review clean. Ready for PR.
