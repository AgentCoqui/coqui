<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ChannelStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

test('creates session and returns id', function () {
    $id = $this->storage->createSession('orchestrator', 'ollama/qwen3:latest');

    expect($id)->toBeString();
    expect(strlen($id))->toBe(32);
});

test('getSession returns session data', function () {
    $id = $this->storage->createSession('coder', 'anthropic/claude');

    $session = $this->storage->getSession($id);

    expect($session)->toBeArray();
    expect($session['id'])->toBe($id);
    expect($session['model_role'])->toBe('coder');
    expect($session['model'])->toBe('anthropic/claude');
    expect($session['session_type'])->toBe('interactive');
    expect($session['session_origin'])->toBe('user');
});

test('group sessions expose explicit session type alongside compatibility fields', function () {
    $sessionId = $this->storage->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['nova', 'caelum'], 3);

    $session = $this->storage->getSession($sessionId);

    expect($session)->not->toBeNull();
    expect($session['session_type'])->toBe('group');
    expect($session['group_enabled'])->toBe(1);
    expect($session['group_composition_key'])->toBe('caelum|nova');
});

test('getSession returns null for missing session', function () {
    $session = $this->storage->getSession('nonexistent');

    expect($session)->toBeNull();
});

test('listSessions returns all sessions', function () {
    $this->storage->createSession('orchestrator', 'model1');
    $this->storage->createSession('coder', 'model2');

    $sessions = $this->storage->listSessions();

    expect($sessions)->toHaveCount(2);
});

test('session rows expose derived status and status counts', function () {
    $activeId = $this->storage->createSession('orchestrator', 'model1');
    $closedId = $this->storage->createSession('coder', 'model2');
    $archivedId = $this->storage->createSession('analyst', 'model3');

    $this->storage->closeSession($closedId, 'closed-only', false);
    $this->storage->closeSession($archivedId, 'archived-history', true);

    $activeSession = $this->storage->getSession($activeId);
    $closedSession = $this->storage->getSession($closedId);
    $archivedSession = $this->storage->getSession($archivedId);
    $archivedSessions = $this->storage->listSessions(status: 'archived', activeOnly: false);
    $counts = $this->storage->getSessionStatusCounts();

    expect($activeSession['status'])->toBe('active');
    expect($closedSession['status'])->toBe('closed');
    expect($archivedSession['status'])->toBe('archived');
    expect($archivedSessions)->toHaveCount(1);
    expect($archivedSessions[0]['id'])->toBe($archivedId);
    expect($counts)->toBe([
        'active' => 1,
        'closed' => 2,
        'archived' => 1,
        'total' => 3,
    ]);
});

test('addMessage saves and retrieves messages', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $this->storage->addMessage($sessionId, 'user', 'Hello');
    $this->storage->addMessage($sessionId, 'assistant', 'Hi there');

    $messages = $this->storage->getMessages($sessionId);

    expect($messages)->toHaveCount(2);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toBe('Hello');
    expect($messages[1]['role'])->toBe('assistant');
    expect($messages[1]['content'])->toBe('Hi there');
});

test('addMessage with tool calls', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $toolCalls = json_encode([
        ['id' => 'call_123', 'name' => 'read_file', 'arguments' => ['path' => '/tmp/test.txt']],
    ]);

    $this->storage->addMessage($sessionId, 'assistant', '', $toolCalls);

    $messages = $this->storage->getMessages($sessionId);

    expect($messages[0]['tool_calls'])->toBe($toolCalls);
});

test('addMessage persists actor metadata for attributed messages', function () {
    $sessionId = $this->storage->createSession('test', 'model');
    $turnId = $this->storage->createTurn($sessionId, 'Hello team');

    $this->storage->addMessage(
        $sessionId,
        'assistant',
        'I can take this one.',
        turnId: $turnId,
        actorName: 'nova',
        actorRole: 'orchestrator',
    );

    $messages = $this->storage->getMessages($sessionId);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['actor_name'])->toBe('nova');
    expect($messages[0]['actor_role'])->toBe('orchestrator');
});

test('loadConversation rebuilds conversation object', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $this->storage->addMessage($sessionId, 'system', 'You are a test');
    $this->storage->addMessage($sessionId, 'user', 'Hello');
    $this->storage->addMessage($sessionId, 'assistant', 'Hi');

    $conversation = $this->storage->loadConversation($sessionId);
    $messages = $conversation->messages();

    expect($messages)->toHaveCount(3);
    expect($messages[0]->role()->value)->toBe('system');
    expect($messages[1]->role()->value)->toBe('user');
    expect($messages[2]->role()->value)->toBe('assistant');
});

test('logChildRun saves child run data', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $this->storage->logChildRun(
        sessionId: $sessionId,
        parentIteration: 3,
        agentRole: 'coder',
        model: 'anthropic/claude',
        prompt: 'Write a function',
        result: 'function test() {}',
        tokenCount: 150,
        metadata: ['workflow_phase' => 'delegation', 'intent' => 'delegated_task'],
    );

    $runs = $this->storage->getChildRuns($sessionId);

    expect($runs)->toHaveCount(1);
    expect($runs[0]['agent_role'])->toBe('coder');
    expect($runs[0]['parent_iteration'])->toBe(3);
    expect($runs[0]['token_count'])->toBe(150);
    expect(json_decode((string) $runs[0]['metadata'], true)['workflow_phase'])->toBe('delegation');
});

test('createTask stores structured metadata', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $taskId = $this->storage->createTask(
        sessionId: $sessionId,
        prompt: 'Run loop stage',
        role: 'coder',
        metadata: ['loop_id' => 'loop-123', 'stage_index' => 1],
    );

    $task = $this->storage->getTask($taskId);

    expect($task)->not->toBeNull();
    expect(json_decode((string) $task['metadata'], true)['loop_id'])->toBe('loop-123');
    expect(json_decode((string) $task['metadata'], true)['stage_index'])->toBe(1);
});

test('deleteSession removes session and messages', function () {
    $sessionId = $this->storage->createSession('test', 'model');
    $this->storage->addMessage($sessionId, 'user', 'Hello');

    $this->storage->deleteSession($sessionId);

    expect($this->storage->getSession($sessionId))->toBeNull();
    expect($this->storage->getMessages($sessionId))->toBeEmpty();
});

test('getLatestSessionId returns most recent', function () {
    $id1 = $this->storage->createSession('test1', 'model');
    sleep(1); // Ensure different timestamp (SQLite TEXT stores seconds precision)
    $id2 = $this->storage->createSession('test2', 'model');

    $latest = $this->storage->getLatestSessionId();

    expect($latest)->toBe($id2);
});

test('getLatestSessionId ignores hidden sessions', function () {
    $visibleId = $this->storage->createSession('test1', 'model');
    sleep(1);
    $this->storage->createSession('test2', 'model', visibility: 'hidden');

    $latest = $this->storage->getLatestSessionId();

    expect($latest)->toBe($visibleId);
});

test('getSurfacedSession excludes hidden sessions', function () {
    $hiddenId = $this->storage->createSession('learner', 'background-task', visibility: 'hidden');

    expect($this->storage->getSession($hiddenId))->not->toBeNull();
    expect($this->storage->getSurfacedSession($hiddenId))->toBeNull();
});

test('session origin is derived as channel for channel-linked sessions', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'model');
    $channelStore = new ChannelStore($this->storage->getPdo());
    $channelStore->upsertConfiguredInstance([
        'name' => 'signal-primary',
        'driver' => 'signal',
        'display_name' => 'Signal Primary',
        'default_profile' => 'caelum',
        'bound_session_id' => $sessionId,
        'settings' => ['account' => '+15551234567'],
        'allowed_scopes' => [],
        'security' => ['linkRequired' => true],
    ], ['direct_messages' => true]);

    $session = $this->storage->getSession($sessionId);

    expect($session)->not->toBeNull();
    expect($session['channel_bound'])->toBeTrue();
    expect($session['session_origin'])->toBe('channel');
});

test('reopening storage repairs legacy visible background task sessions', function () {
    $sessionId = $this->storage->createSession('learner', 'legacy-model');
    $this->storage->createTask($sessionId, 'Legacy background task', 'learner');

    unset($this->storage);
    $this->storage = new SessionStorage($this->dbPath);

    $session = $this->storage->getSession($sessionId);

    expect($session)->not->toBeNull();
    expect($session['visibility'])->toBe('hidden');
    expect($session['session_origin'])->toBe('background');
    expect($this->storage->getSurfacedSession($sessionId))->toBeNull();
});

test('reopening storage preserves visible channel sessions with task records', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'channel:signal');
    $channelStore = new ChannelStore($this->storage->getPdo());
    $channelStore->upsertConfiguredInstance([
        'name' => 'signal-primary',
        'driver' => 'signal',
        'display_name' => 'Signal Primary',
        'default_profile' => 'caelum',
        'bound_session_id' => $sessionId,
        'settings' => ['account' => '+15551234567'],
        'allowed_scopes' => [],
        'security' => ['linkRequired' => true],
    ], ['direct_messages' => true]);
    $this->storage->createTask($sessionId, 'Channel reply task', 'orchestrator');

    unset($this->storage);
    $this->storage = new SessionStorage($this->dbPath);

    $session = $this->storage->getSession($sessionId);

    expect($session)->not->toBeNull();
    expect($session['visibility'])->toBe('visible');
    expect($session['channel_bound'])->toBeTrue();
    expect($session['session_origin'])->toBe('channel');
    expect($this->storage->getSurfacedSession($sessionId))->not->toBeNull();
});

test('updateTokenCount updates session tokens', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $this->storage->updateTokenCount($sessionId, 500);

    $session = $this->storage->getSession($sessionId);
    expect($session['token_count'])->toBe(500);
});

test('loadConversation reconstructs plain user messages', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $this->storage->addMessage($sessionId, 'user', 'Hello, how are you?');
    $this->storage->addMessage($sessionId, 'assistant', 'I am doing well!');

    $conversation = $this->storage->loadConversation($sessionId);
    $messages = $conversation->messages();

    expect($messages)->toHaveCount(2);
    expect($messages[0]->content())->toBe('Hello, how are you?');
});

test('loadConversation decodes multimodal user content', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    // Store JSON-encoded multimodal content (as would be stored by sanitizeContent)
    $multimodal = json_encode([
        ['type' => 'text', 'text' => 'Describe this image'],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
    ], JSON_THROW_ON_ERROR);

    $this->storage->addMessage($sessionId, 'user', $multimodal);

    $conversation = $this->storage->loadConversation($sessionId);
    $messages = $conversation->messages();

    expect($messages)->toHaveCount(1);
    // The content should be decoded back to an array
    $content = $messages[0]->content();
    expect($content)->toBeArray();
    expect($content[0]['type'])->toBe('text');
    expect($content[0]['text'])->toBe('Describe this image');
    expect($content[1]['type'])->toBe('image_url');
});

test('loadConversation preserves non-JSON user content', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    // Content that starts with [ but is NOT valid multimodal JSON
    $this->storage->addMessage($sessionId, 'user', '[this is just a regular message]');

    $conversation = $this->storage->loadConversation($sessionId);
    $messages = $conversation->messages();

    expect($messages)->toHaveCount(1);
    expect($messages[0]->content())->toBe('[this is just a regular message]');
});

test('updateSessionRole updates model_role and model', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'openai/gpt-4');

    $this->storage->updateSessionRole($sessionId, 'coder', 'anthropic/claude-3-5-sonnet');

    $session = $this->storage->getSession($sessionId);

    expect($session['model_role'])->toBe('coder');
    expect($session['model'])->toBe('anthropic/claude-3-5-sonnet');
});

test('updateSessionRole updates timestamp', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'model');
    $before = $this->storage->getSession($sessionId)['updated_at'];

    // Ensure at least 1 second gap for timestamp difference
    sleep(1);
    $this->storage->updateSessionRole($sessionId, 'reviewer', 'model2');

    $after = $this->storage->getSession($sessionId)['updated_at'];
    expect($after)->not->toBe($before);
});

test('getPdo returns working connection', function () {
    $pdo = $this->storage->getPdo();

    expect($pdo)->toBeInstanceOf(PDO::class);

    // Verify it's a live connection to the same database
    $sessionId = $this->storage->createSession('test', 'model');
    $stmt = $pdo->prepare('SELECT id FROM sessions WHERE id = ?');
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    expect($row['id'])->toBe($sessionId);
});

test('checkTablesExist includes turn process tables', function () {
    $result = $this->storage->checkTablesExist();

    expect($result['ok'])->toBeTrue();
    expect($result['missing'])->toBe([]);
});

test('appendTurnEvent stores and retrieves turn process events', function () {
    $sessionId = $this->storage->createSession('test', 'model');
    $turnProcessId = $this->storage->createTurnProcess($sessionId, 'Hello');

    $this->storage->appendTurnEvent($turnProcessId, 'complete', [
        'content' => 'Done',
        'error' => null,
    ]);

    $events = $this->storage->getTurnEvents($turnProcessId);

    expect($events)->toHaveCount(1);
    expect($events[0]['event_type'])->toBe('complete');
    expect(json_decode((string) $events[0]['data'], true))->toBe([
        'content' => 'Done',
        'error' => null,
    ]);
});

test('appendTurnEvent preserves rich complete payload fields', function () {
    $sessionId = $this->storage->createSession('test', 'model');
    $turnProcessId = $this->storage->createTurnProcess($sessionId, 'Hello');
    $payload = [
        'content' => 'Done',
        'iterations' => 1,
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'total_tokens' => 15,
        'duration_ms' => 250,
        'tools_used' => ['list_dir'],
        'child_agent_count' => 0,
        'restart_requested' => false,
        'iteration_limit_reached' => false,
        'budget_exhausted' => false,
        'context_usage' => [
            'max_tokens' => 128000,
            'reserved_tokens' => 8192,
            'used_tokens' => 42,
            'usage_percent' => 0,
            'available_tokens' => 119766,
            'effective_budget' => 119808,
            'breakdown' => [
                'system' => 10,
                'memory' => 0,
                'user' => 8,
                'assistant' => 12,
                'tool' => 12,
                'summary' => 0,
            ],
        ],
        'file_edits' => [
            ['file_path' => '/tmp/example.php', 'operation' => 'update'],
        ],
        'error' => null,
        'review_feedback' => 'Approved',
        'review_approved' => true,
        'background_tasks' => [
            'agents' => [[
                'id' => 'task-1',
                'status' => 'running',
                'title' => 'Review',
                'role' => 'reviewer',
                'started_at' => '2026-04-21T12:00:00+00:00',
                'created_at' => '2026-04-21T11:59:30+00:00',
            ]],
            'tools' => [[
                'id' => 'task-2',
                'status' => 'pending',
                'title' => 'Fetch docs',
                'tool_name' => 'web_fetch',
                'started_at' => null,
                'created_at' => '2026-04-21T11:59:45+00:00',
            ]],
            'total_count' => 2,
        ],
    ];

    $this->storage->appendTurnEvent($turnProcessId, 'complete', $payload);

    $events = $this->storage->getTurnEvents($turnProcessId);

    expect($events)->toHaveCount(1);
    expect(json_decode((string) $events[0]['data'], true))->toBe($payload);
});

test('findRecentTaskByTitle returns most recent matching task', function () {
    $sessionId = $this->storage->createSession('learner', 'quality-automation');

    $firstTaskId = $this->storage->createTask(
        sessionId: $sessionId,
        prompt: 'first prompt',
        role: 'learner',
        title: 'Quality Learning Follow-up: eval-1',
    );

    sleep(1);

    $secondTaskId = $this->storage->createTask(
        sessionId: $sessionId,
        prompt: 'second prompt',
        role: 'learner',
        title: 'Quality Learning Follow-up: eval-1',
    );

    $task = $this->storage->findRecentTaskByTitle(
        title: 'Quality Learning Follow-up: eval-1',
        role: 'learner',
    );

    expect($task)->not->toBeNull();
    expect($task['id'])->toBe($secondTaskId);
    expect($task['id'])->not->toBe($firstTaskId);
});

test('clearActiveProjectReferences clears matching session pointers only', function () {
    $projectA = 'project-a';
    $projectB = 'project-b';
    $sessionA = $this->storage->createSession('orchestrator', 'model-a');
    $sessionB = $this->storage->createSession('orchestrator', 'model-b');
    $sessionC = $this->storage->createSession('orchestrator', 'model-c');

    $this->storage->setActiveProject($sessionA, $projectA);
    $this->storage->setActiveProject($sessionB, $projectA);
    $this->storage->setActiveProject($sessionC, $projectB);

    $cleared = $this->storage->clearActiveProjectReferences($projectA);

    expect($cleared)->toBe(2);
    expect($this->storage->getActiveProjectId($sessionA))->toBeNull();
    expect($this->storage->getActiveProjectId($sessionB))->toBeNull();
    expect($this->storage->getActiveProjectId($sessionC))->toBe($projectB);
});

test('clearAllActiveProjects clears every active project pointer', function () {
    $sessionA = $this->storage->createSession('orchestrator', 'model-a');
    $sessionB = $this->storage->createSession('orchestrator', 'model-b');
    $sessionC = $this->storage->createSession('orchestrator', 'model-c');

    $this->storage->setActiveProject($sessionA, 'project-a');
    $this->storage->setActiveProject($sessionB, 'project-b');

    $cleared = $this->storage->clearAllActiveProjects();

    expect($cleared)->toBe(2);
    expect($this->storage->getActiveProjectId($sessionA))->toBeNull();
    expect($this->storage->getActiveProjectId($sessionB))->toBeNull();
    expect($this->storage->getActiveProjectId($sessionC))->toBeNull();
});

test('loadConversation preserves ToolCall metadata through storage roundtrip', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $metadata = ['thoughtSignature' => 'gs:test_signature_abc123'];
    $toolCalls = json_encode([
        [
            'id' => 'g00000000',
            'name' => 'read_file',
            'arguments' => ['path' => '/tmp/test.txt'],
            'metadata' => $metadata,
        ],
    ]);

    $this->storage->addMessage($sessionId, 'assistant', '', $toolCalls);

    $conversation = $this->storage->loadConversation($sessionId);
    $messages = $conversation->messages();

    expect($messages)->toHaveCount(1);
    $tc = $messages[0]->toolCalls()[0];
    expect($tc->id)->toBe('g00000000');
    expect($tc->name)->toBe('read_file');
    expect($tc->metadata)->toBe($metadata);
});

test('loadConversation handles ToolCall without metadata field', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    // Simulate old-format tool calls (before metadata was added)
    $toolCalls = json_encode([
        ['id' => 'g00000000', 'name' => 'read_file', 'arguments' => ['path' => '/tmp/test.txt']],
    ]);

    $this->storage->addMessage($sessionId, 'assistant', '', $toolCalls);

    $conversation = $this->storage->loadConversation($sessionId);
    $tc = $conversation->messages()[0]->toolCalls()[0];

    expect($tc->metadata)->toBe([]);
});

// --- Soft-delete (is_summarized) tests ---

test('markMessagesAsSummarized marks messages correctly', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'test/model');

    $this->storage->addMessage($sessionId, 'user', 'Question 1');
    $this->storage->addMessage($sessionId, 'assistant', 'Answer 1');
    $this->storage->addMessage($sessionId, 'user', 'Question 2');
    $this->storage->addMessage($sessionId, 'assistant', 'Answer 2');

    $messages = $this->storage->getMessages($sessionId);
    $idsToMark = [$messages[0]['id'], $messages[1]['id']];

    $marked = $this->storage->markMessagesAsSummarized($idsToMark);
    expect($marked)->toBe(2);
});

test('markMessagesAsSummarized returns 0 for empty array', function () {
    expect($this->storage->markMessagesAsSummarized([]))->toBe(0);
});

test('loadConversation excludes summarized messages', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'test/model');

    $this->storage->addMessage($sessionId, 'user', 'Old question');
    $this->storage->addMessage($sessionId, 'assistant', 'Old answer');
    $this->storage->addMessage($sessionId, 'user', 'New question');
    $this->storage->addMessage($sessionId, 'assistant', 'New answer');

    $messages = $this->storage->getMessages($sessionId);
    // Mark first two messages as summarized
    $this->storage->markMessagesAsSummarized([$messages[0]['id'], $messages[1]['id']]);

    // getMessages still returns all 4 (unfiltered)
    $allMessages = $this->storage->getMessages($sessionId);
    expect(count($allMessages))->toBe(4);

    // loadConversation should only return the 2 non-summarized messages
    $conversation = $this->storage->loadConversation($sessionId);
    expect($conversation->count())->toBe(2);
});

test('summary message is not filtered after soft-delete', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'test/model');

    // Add messages, summarize some, then add a summary message
    $this->storage->addMessage($sessionId, 'user', 'Old question');
    $this->storage->addMessage($sessionId, 'assistant', 'Old answer');

    $messages = $this->storage->getMessages($sessionId);
    $this->storage->markMessagesAsSummarized([$messages[0]['id'], $messages[1]['id']]);

    // Add summary message (not marked as summarized)
    $this->storage->addMessage($sessionId, 'user', '[CONVERSATION SUMMARY] Summary of old conversation.');
    $this->storage->addMessage($sessionId, 'user', 'New question');

    // loadConversation should return the summary + new question (2 messages)
    $conversation = $this->storage->loadConversation($sessionId);
    expect($conversation->count())->toBe(2);
});

test('listSessions hides closed sessions by default', function () {
    $openSessionId = $this->storage->createSession('orchestrator', 'model-open');
    $closedSessionId = $this->storage->createSession('orchestrator', 'model-closed');

    $this->storage->closeSession($closedSessionId, 'test-close');

    $activeSessions = $this->storage->listSessions();
    $allSessions = $this->storage->listSessions(50, true, false);

    expect(array_column($activeSessions, 'id'))->toContain($openSessionId);
    expect(array_column($activeSessions, 'id'))->not->toContain($closedSessionId);
    expect(array_column($allSessions, 'id'))->toContain($closedSessionId);
});

test('getLatestInteractiveSessionIdForProfile ignores closed sessions', function () {
    $closedSessionId = $this->storage->createSession('orchestrator', 'model-closed', 'caelum');
    $activeSessionId = $this->storage->createSession('orchestrator', 'model-active', 'caelum');

    $this->storage->closeSession($closedSessionId, 'test-close');

    expect($this->storage->getLatestInteractiveSessionIdForProfile('caelum'))->toBe($activeSessionId);
});

test('listSessions hides hidden worker sessions and exposes channel metadata', function () {
    $visibleSessionId = $this->storage->createSession('orchestrator', 'channel:signal', 'caelum');
    $hiddenSessionId = $this->storage->createSession('orchestrator', 'model-hidden', visibility: 'hidden');
    $channelStore = new \CoquiBot\Coqui\Storage\ChannelStore($this->storage->getPdo());

    $channelStore->upsertConfiguredInstance([
        'name' => 'signal-primary',
        'driver' => 'signal',
        'enabled' => true,
        'display_name' => 'Signal Primary',
        'default_profile' => 'caelum',
        'bound_session_id' => $visibleSessionId,
        'settings' => ['account' => '+15551234567'],
        'allowed_scopes' => [],
        'security' => [],
        'source' => 'config',
    ]);

    $sessions = $this->storage->listSessions();
    $sessionIds = array_column($sessions, 'id');
    $visibleSession = array_values(array_filter($sessions, static fn(array $session): bool => $session['id'] === $visibleSessionId))[0] ?? null;

    expect($sessionIds)->toContain($visibleSessionId);
    expect($sessionIds)->not->toContain($hiddenSessionId);
    expect($visibleSession)->not->toBeNull();
    expect($visibleSession['channel_bound'])->toBeTrue();
    expect($visibleSession['channel']['driver'])->toBe('signal');
});

test('latest interactive helpers ignore channel bound sessions', function () {
    $ordinarySessionId = $this->storage->createSession('orchestrator', 'model-ordinary', 'caelum');
    $channelSessionId = $this->storage->createSession('orchestrator', 'channel:signal', 'caelum');
    $channelStore = new \CoquiBot\Coqui\Storage\ChannelStore($this->storage->getPdo());

    $channelStore->upsertConfiguredInstance([
        'name' => 'signal-primary',
        'driver' => 'signal',
        'enabled' => true,
        'display_name' => 'Signal Primary',
        'default_profile' => 'caelum',
        'bound_session_id' => $channelSessionId,
        'settings' => ['account' => '+15551234567'],
        'allowed_scopes' => [],
        'security' => [],
        'source' => 'config',
    ]);

    $this->storage->getPdo()
        ->prepare('UPDATE sessions SET updated_at = :updated_at WHERE id = :id')
        ->execute(['updated_at' => '2026-01-01T00:00:00+00:00', 'id' => $ordinarySessionId]);
    $this->storage->getPdo()
        ->prepare('UPDATE sessions SET updated_at = :updated_at WHERE id = :id')
        ->execute(['updated_at' => '2026-01-05T00:00:00+00:00', 'id' => $channelSessionId]);

    expect($this->storage->getLatestInteractiveSessionIdForProfile('caelum'))->toBe($ordinarySessionId);
    expect($this->storage->getLatestInteractiveSessionId())->toBe($ordinarySessionId);
});

test('isSessionWritable returns false once a session is closed', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'model');

    expect($this->storage->isSessionWritable($sessionId))->toBeTrue();

    $this->storage->closeSession($sessionId, 'test-close');

    expect($this->storage->isSessionWritable($sessionId))->toBeFalse();
    expect($this->storage->isSessionClosed($sessionId))->toBeTrue();
});

// ─── Background Task Summary ────────────────────────────────────────────────

test('getActiveBackgroundSummary returns running and pending tasks', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'model');

    $taskId1 = $this->storage->createTask($sessionId, 'Refactor auth', 'coder', title: 'Refactor auth');
    $this->storage->updateTaskStatus($taskId1, 'running', ['pid' => 1234]);

    $taskId2 = $this->storage->createTask($sessionId, 'Review code', 'reviewer', title: 'Code review');
    // Stays pending

    $rows = $this->storage->getActiveBackgroundSummary();

    expect($rows)->toHaveCount(2);
    expect($rows[0]['status'])->toBeIn(['running', 'pending']);
    expect($rows[1]['status'])->toBeIn(['running', 'pending']);
});

test('getActiveBackgroundSummary excludes completed and failed tasks', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'model');

    $taskId1 = $this->storage->createTask($sessionId, 'Completed task', 'coder', title: 'Done');
    $this->storage->updateTaskStatus($taskId1, 'running');
    $this->storage->updateTaskStatus($taskId1, 'completed', ['result' => 'Done']);

    $taskId2 = $this->storage->createTask($sessionId, 'Failed task', 'coder', title: 'Failed');
    $this->storage->updateTaskStatus($taskId2, 'running');
    $this->storage->updateTaskStatus($taskId2, 'failed', ['error' => 'Crashed']);

    $taskId3 = $this->storage->createTask($sessionId, 'Running task', 'coder', title: 'Active');
    $this->storage->updateTaskStatus($taskId3, 'running');

    $rows = $this->storage->getActiveBackgroundSummary();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['title'])->toBe('Active');
    expect($rows[0]['status'])->toBe('running');
});

test('getActiveBackgroundSummary returns empty array when no active tasks', function () {
    $rows = $this->storage->getActiveBackgroundSummary();

    expect($rows)->toBe([]);
});

test('getActiveBackgroundSummary includes tool_name for tool tasks', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'model');

    $taskId = $this->storage->createTask(
        $sessionId,
        'Scrape website',
        'orchestrator',
        title: 'Scrape docs',
        toolName: 'web_scrape',
        toolArguments: '{"url": "https://example.com"}',
    );
    $this->storage->updateTaskStatus($taskId, 'running');

    $rows = $this->storage->getActiveBackgroundSummary();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['tool_name'])->toBe('web_scrape');
    expect($rows[0]['role'])->toBe('orchestrator');
});

test('getActiveBackgroundSummary orders by creation time ascending', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'model');

    $taskId1 = $this->storage->createTask($sessionId, 'First task', 'coder', title: 'First');
    usleep(10_000); // 10ms gap for timestamp ordering
    $taskId2 = $this->storage->createTask($sessionId, 'Second task', 'reviewer', title: 'Second');

    $rows = $this->storage->getActiveBackgroundSummary();

    expect($rows)->toHaveCount(2);
    expect($rows[0]['title'])->toBe('First');
    expect($rows[1]['title'])->toBe('Second');
});
