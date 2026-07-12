<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactFileService;

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir() . '/afs-' . bin2hex(random_bytes(6));
    mkdir($this->workspace, 0775, true);
    $this->svc = new ArtifactFileService($this->workspace);
});

afterEach(function (): void {
    exec('rm -rf ' . escapeshellarg($this->workspace));
});

it('generates a predictable path under artifacts/<type>/', function (): void {
    $path = $this->svc->pathFor('document', 'My Design Doc', 'abcd1234effff', null);

    expect($path)->toStartWith('artifacts/document/')
        ->and($path)->toEndWith('.md')
        ->and($path)->toContain('my-design-doc-abcd1234');
});

it('derives code extension from language', function (): void {
    $path = $this->svc->pathFor('code', 'Widget', 'deadbeef0000', 'php');

    expect($path)->toStartWith('artifacts/code/')->and($path)->toEndWith('.php');
});

it('falls back to .txt for code without a known language', function (): void {
    $path = $this->svc->pathFor('code', 'Mystery', 'deadbeef0001', null);

    expect($path)->toEndWith('.txt');
});

it('writes content and returns its hash; read returns the same content', function (): void {
    $path = $this->svc->pathFor('plan', 'Plan A', 'aaaa1111bbbb', null);
    $hash = $this->svc->write($path, "hello world\n");

    expect(file_exists($this->workspace . '/' . $path))->toBeTrue()
        ->and($hash)->toBe(hash('sha256', "hello world\n"))
        ->and($this->svc->read($path))->toBe("hello world\n");
});

it('read returns null for a missing file', function (): void {
    expect($this->svc->read('artifacts/document/nope-00000000.md'))->toBeNull();
});

it('delete removes the file and is idempotent', function (): void {
    $path = $this->svc->pathFor('document', 'Del', 'ccccdddd0000', null);
    $this->svc->write($path, 'x');

    expect($this->svc->delete($path))->toBeTrue()
        ->and(file_exists($this->workspace . '/' . $path))->toBeFalse()
        ->and($this->svc->delete($path))->toBeTrue();
});
