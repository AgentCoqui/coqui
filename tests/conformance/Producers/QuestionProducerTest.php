<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

const QUESTION_STATUS_ENUM = ['open', 'answered', 'cancelled'];

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-question-producer-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

/**
 * @param list<QuestionOption> $options
 */
function makeQuestion(
    string $id,
    string $prompt,
    QuestionFormat $format,
    array $options,
    QuestionResponse $suggested,
    bool $allowOther = false,
): QuestionRequest {
    return new QuestionRequest(
        id: $id,
        prompt: $prompt,
        format: $format,
        options: $options,
        allowOther: $allowOther,
        suggested: $suggested,
    );
}

it('CORE-24: an open free-text question serializes to a schema-valid Question', function () {
    $q = makeQuestion(
        id: '01J000000000000000000QUEST01',
        prompt: 'What is your deployment target?',
        format: QuestionFormat::FreeText,
        options: [],
        suggested: new QuestionResponse(text: 'production'),
    );
    $this->storage->createQuestion($this->sessionId, $q, 'interactive');

    $wire = QuestionPersistence::toWire($this->storage->getQuestion($q->id));

    $v = new ConformanceValidator();
    expect($v->isValid('question.json', $wire))->toBeTrue($v->errorText('question.json', $wire));

    expect($wire['id'])->toBe($q->id);
    expect($wire['session_id'])->toBe($this->sessionId);
    expect($wire['prompt'])->toBe('What is your deployment target?');
    // coqui's free_text maps to the schema's `text`.
    expect($wire['format'])->toBe('text');
    // Free-form ⇒ no closed option set.
    expect($wire['options'])->toBeNull();
    // Pending ⇒ open; open ⇒ not yet answered.
    expect($wire['status'])->toBe('open');
    expect($wire['status'])->toBeIn(QUESTION_STATUS_ENUM);
    expect($wire['answer'])->toBeNull();
    expect($wire['suggested'])->toBe('production');
    // created_at is RFC-3339 UTC (Z); answered_at is null while open.
    expect($wire['created_at'])->toMatch('/Z$/');
    expect($wire['answered_at'])->toBeNull();
})->group('conformance');

it('CORE-24: toWire emits exactly the schema properties (additionalProperties:false-clean)', function () {
    $q = makeQuestion(
        id: '01J000000000000000000QUEST02',
        prompt: 'Pick one',
        format: QuestionFormat::SingleSelect,
        options: [new QuestionOption('yes'), new QuestionOption('no')],
        suggested: new QuestionResponse(selected: ['yes']),
    );
    $this->storage->createQuestion($this->sessionId, $q, 'interactive');

    $wire = QuestionPersistence::toWire($this->storage->getQuestion($q->id));

    // Internal columns (turn_id/loop_id/stage_id/responder_kind) MUST NOT leak.
    expect(array_keys($wire))->toEqualCanonicalizing([
        'id', 'session_id', 'prompt', 'format', 'options', 'allow_other',
        'status', 'answer', 'suggested', 'created_at', 'answered_at',
    ]);
})->group('conformance');

it('CORE-24: a single-select carries typed {value,label} options', function () {
    $q = makeQuestion(
        id: '01J000000000000000000QUEST03',
        prompt: 'Choose a region',
        format: QuestionFormat::SingleSelect,
        options: [new QuestionOption('us-east', 'US East (Virginia)'), new QuestionOption('eu-west')],
        suggested: new QuestionResponse(selected: ['us-east']),
    );
    $this->storage->createQuestion($this->sessionId, $q, 'interactive');

    $wire = QuestionPersistence::toWire($this->storage->getQuestion($q->id));

    $v = new ConformanceValidator();
    expect($v->isValid('question.json', $wire))->toBeTrue($v->errorText('question.json', $wire));

    expect($wire['format'])->toBe('single_select');
    // coqui option label is the CAP option value; description is the display label.
    expect($wire['options'])->toBe([
        ['value' => 'us-east', 'label' => 'US East (Virginia)'],
        ['value' => 'eu-west'],
    ]);
})->group('conformance');

it('CORE-24: an answered single-select maps status=answered + a scalar answer + answered_at', function () {
    $q = makeQuestion(
        id: '01J000000000000000000QUEST04',
        prompt: 'Proceed?',
        format: QuestionFormat::SingleSelect,
        options: [new QuestionOption('yes'), new QuestionOption('no')],
        suggested: new QuestionResponse(selected: ['yes']),
    );
    $this->storage->createQuestion($this->sessionId, $q, 'interactive');
    $ok = $this->storage->recordQuestionAnswer($q->id, new QuestionResponse(selected: ['no']));
    expect($ok)->toBeTrue();

    $wire = QuestionPersistence::toWire($this->storage->getQuestion($q->id));

    $v = new ConformanceValidator();
    expect($v->isValid('question.json', $wire))->toBeTrue($v->errorText('question.json', $wire));

    expect($wire['status'])->toBe('answered');
    expect($wire['status'])->toBeIn(QUESTION_STATUS_ENUM);
    expect($wire['answer'])->toBe('no');
    expect($wire['answered_at'])->toMatch('/Z$/');
})->group('conformance');

it('CORE-24: an answered multi-select carries an array answer', function () {
    $q = makeQuestion(
        id: '01J000000000000000000QUEST05',
        prompt: 'Select toolkits',
        format: QuestionFormat::MultiSelect,
        options: [new QuestionOption('shell'), new QuestionOption('files'), new QuestionOption('web')],
        suggested: new QuestionResponse(selected: ['shell']),
    );
    $this->storage->createQuestion($this->sessionId, $q, 'interactive');
    $this->storage->recordQuestionAnswer($q->id, new QuestionResponse(selected: ['shell', 'web']));

    $wire = QuestionPersistence::toWire($this->storage->getQuestion($q->id));

    $v = new ConformanceValidator();
    expect($v->isValid('question.json', $wire))->toBeTrue($v->errorText('question.json', $wire));

    expect($wire['format'])->toBe('multi_select');
    expect($wire['answer'])->toBe(['shell', 'web']);
})->group('conformance');
