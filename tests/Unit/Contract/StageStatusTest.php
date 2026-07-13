<?php
declare(strict_types=1);

use CoquiBot\Coqui\Contract\StageStatus;
use CoquiBot\Coqui\Contract\StageSeverity;

test('fromProducerSignal defaults to Done when no sentinel present', function () {
    expect(StageStatus::fromProducerSignal("Implemented the feature.\nAll tests pass."))->toBe(StageStatus::Done);
});

test('fromProducerSignal detects a leading BLOCKED sentinel', function () {
    expect(StageStatus::fromProducerSignal("STATUS: BLOCKED\nCannot find the target module."))->toBe(StageStatus::Blocked);
});

test('fromProducerSignal detects NEEDS_CONTEXT case-insensitively within the first lines', function () {
    expect(StageStatus::fromProducerSignal("Working on it\nstatus: needs_context\nmissing the API key"))->toBe(StageStatus::NeedsContext);
});

test('fromProducerSignal ignores a malformed sentinel and returns Done', function () {
    expect(StageStatus::fromProducerSignal("STATUS: WEIRD_VALUE\nbody"))->toBe(StageStatus::Done);
});

test('halts is true only for Blocked and NeedsContext', function () {
    expect(StageStatus::Blocked->halts())->toBeTrue();
    expect(StageStatus::NeedsContext->halts())->toBeTrue();
    expect(StageStatus::Done->halts())->toBeFalse();
    expect(StageStatus::DoneWithConcerns->halts())->toBeFalse();
});

test('severity blocks is true for Critical and Important only', function () {
    expect(StageSeverity::Critical->blocks())->toBeTrue();
    expect(StageSeverity::Important->blocks())->toBeTrue();
    expect(StageSeverity::Minor->blocks())->toBeFalse();
});
