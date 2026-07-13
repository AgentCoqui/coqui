<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\StageFinding;
use CoquiBot\Coqui\Contract\StageVerdict;
use CoquiBot\Coqui\Contract\StageSeverity;
use CoquiBot\Coqui\Contract\StageStatus;

test('approved gate verdict has both flags true and no blocking findings', function () {
    $v = new StageVerdict(StageStatus::Done, true, true, [], 'All criteria met.');
    expect($v->isApproved())->toBeTrue();
    expect($v->hasBlockingFindings())->toBeFalse();
});

test('gate verdict with a critical finding is not approved', function () {
    $v = new StageVerdict(StageStatus::DoneWithConcerns, true, true, [
        new StageFinding(StageSeverity::Critical, 'Data loss on retry'),
    ], 'One critical issue.');
    expect($v->hasBlockingFindings())->toBeTrue();
    expect($v->isApproved())->toBeFalse();
});

test('gate verdict with only minor findings and both flags true is approved', function () {
    $v = new StageVerdict(StageStatus::DoneWithConcerns, true, true, [
        new StageFinding(StageSeverity::Minor, 'Typo in comment'),
    ], 'Minor nit only.');
    expect($v->isApproved())->toBeTrue();
});

test('gate verdict is not approved when requirementsMet is false', function () {
    $v = new StageVerdict(StageStatus::DoneWithConcerns, false, true, [], 'Missing feature X.');
    expect($v->isApproved())->toBeFalse();
});

test('toArray then fromArray round-trips', function () {
    $v = new StageVerdict(StageStatus::DoneWithConcerns, false, true, [
        new StageFinding(StageSeverity::Important, 'No error handling', 'src/Foo.php'),
    ], 'Needs work.');
    $restored = StageVerdict::fromArray($v->toArray());
    expect($restored->status)->toBe(StageStatus::DoneWithConcerns);
    expect($restored->requirementsMet)->toBeFalse();
    expect($restored->findings[0]->severity)->toBe(StageSeverity::Important);
    expect($restored->findings[0]->location)->toBe('src/Foo.php');
});

test('gateFromText reads approval keywords into an approved verdict', function () {
    $v = StageVerdict::gateFromText('This looks good. APPROVED — no remaining issues.');
    expect($v->isApproved())->toBeTrue();
});

test('gateFromText treats a rejection as not approved', function () {
    $v = StageVerdict::gateFromText('Needs changes: the parser is broken.');
    expect($v->isApproved())->toBeFalse();
});

test('producerSelfSignal derives status from output and leaves gate flags null', function () {
    $v = StageVerdict::producerSelfSignal("STATUS: BLOCKED\nmissing module");
    expect($v->status)->toBe(StageStatus::Blocked);
    expect($v->requirementsMet)->toBeNull();
    expect($v->qualityPass)->toBeNull();
});
