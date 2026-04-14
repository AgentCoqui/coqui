<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactFileService;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir() . '/coqui-fs-test-' . bin2hex(random_bytes(8));
    mkdir($this->workspace, 0755, true);
    $this->service = new ArtifactFileService($this->workspace);
});

afterEach(function () {
    // Recursive delete
    if (is_dir($this->workspace)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspace, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->workspace);
    }
});

// --- isFilesystemBacked ---

test('plan type with project is filesystem-backed', function () {
    expect($this->service->isFilesystemBacked('plan', null, 'proj-123'))->toBeTrue();
});

test('plan type with explicit filepath is filesystem-backed', function () {
    expect($this->service->isFilesystemBacked('plan', 'docs/plan.md', null))->toBeTrue();
});

test('plan type without project or filepath is not filesystem-backed', function () {
    expect($this->service->isFilesystemBacked('plan', null, null))->toBeFalse();
});

test('document type with project is filesystem-backed', function () {
    expect($this->service->isFilesystemBacked('document', null, 'proj-123'))->toBeTrue();
});

test('code type with filepath is filesystem-backed', function () {
    expect($this->service->isFilesystemBacked('code', 'src/MyClass.php', null))->toBeTrue();
});

test('code type without filepath is not filesystem-backed', function () {
    expect($this->service->isFilesystemBacked('code', null, 'proj-123'))->toBeFalse();
});

test('loop_output is never filesystem-backed', function () {
    expect($this->service->isFilesystemBacked('loop_output', 'some/path', 'proj-123'))->toBeFalse();
});

test('data type is never filesystem-backed', function () {
    expect($this->service->isFilesystemBacked('data', null, 'proj-123'))->toBeFalse();
});

// --- resolveCanonicalPath ---

test('resolveCanonicalPath returns explicit filepath for code type', function () {
    $path = $this->service->resolveCanonicalPath('abc12345def67890', 'code', 'Auth Service', 'src/AuthService.php', null, null);
    expect($path)->toBe('src/AuthService.php');
});

test('resolveCanonicalPath auto-generates for plan type with project dir', function () {
    $path = $this->service->resolveCanonicalPath('abc12345def67890', 'plan', 'Sprint Planning Doc', null, 'proj-123', 'my-project-abc12345');
    expect($path)->toContain('projects/my-project-abc12345/artifacts/');
    expect($path)->toContain('sprint-planning-doc-');
    expect($path)->toEndWith('.md');
});

test('resolveCanonicalPath returns null for db-only type', function () {
    $path = $this->service->resolveCanonicalPath('abc12345', 'loop_output', 'Output', null, 'proj', 'dir');
    expect($path)->toBeNull();
});

test('resolveCanonicalPath returns null for plan without project', function () {
    $path = $this->service->resolveCanonicalPath('abc12345', 'plan', 'My Plan', null, null, null);
    expect($path)->toBeNull();
});

// --- writeContent / readContent ---

test('writeContent creates file and readContent reads it back', function () {
    $path = 'test-artifacts/my-file.md';

    $written = $this->service->writeContent($path, '# Hello World');
    expect($written)->toBeTrue();

    $content = $this->service->readContent($path);
    expect($content)->toBe('# Hello World');
});

test('readContent returns null for missing file', function () {
    expect($this->service->readContent('nonexistent/file.md'))->toBeNull();
});

test('writeContent creates nested directories', function () {
    $this->service->writeContent('deeply/nested/dir/file.txt', 'content');
    expect($this->service->fileExists('deeply/nested/dir/file.txt'))->toBeTrue();
});

// --- drift detection ---

test('detectDrift reports no drift when content matches', function () {
    $content = 'matching content';
    $this->service->writeContent('test.md', $content);

    $result = $this->service->detectDrift('test.md', $content);
    expect($result['drifted'])->toBeFalse();
});

test('detectDrift reports drift when file differs from db content', function () {
    $this->service->writeContent('test.md', 'disk version');

    $result = $this->service->detectDrift('test.md', 'db version');
    expect($result['drifted'])->toBeTrue();
});

test('detectDrift reports no drift when file missing', function () {
    $result = $this->service->detectDrift('nonexistent.md', 'any content');
    expect($result['drifted'])->toBeFalse();
    expect($result['file_hash'])->toBeNull();
});

// --- deleteFile ---

test('deleteFile removes existing file', function () {
    $this->service->writeContent('deleteme.md', 'content');
    expect($this->service->fileExists('deleteme.md'))->toBeTrue();

    $result = $this->service->deleteFile('deleteme.md');
    expect($result)->toBeTrue();
    expect($this->service->fileExists('deleteme.md'))->toBeFalse();
});

test('deleteFile returns true for nonexistent file', function () {
    expect($this->service->deleteFile('already-gone.md'))->toBeTrue();
});

// --- computeContentHash ---

test('computeContentHash returns consistent sha256', function () {
    $hash1 = $this->service->computeContentHash('test');
    $hash2 = $this->service->computeContentHash('test');
    expect($hash1)->toBe($hash2);
    expect(strlen($hash1))->toBe(64); // SHA-256 hex length
});
