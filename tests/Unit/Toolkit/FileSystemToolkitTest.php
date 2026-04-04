<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Storage\EditHistory;
use CoquiBot\Coqui\Toolkit\FileSystemToolkit;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/coqui-fstk-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0755, true);

    $this->historyPath = sys_get_temp_dir() . '/coqui-fstk-hist-' . bin2hex(random_bytes(8));
    $this->history = new EditHistory($this->historyPath);
    $this->toolkit = new FileSystemToolkit($this->root, false, [], $this->history);
    $this->readonlyToolkit = new FileSystemToolkit($this->root, true);
});

afterEach(function () {
    foreach ([$this->root, $this->historyPath] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
});

// ---------------------------------------------------------------
// Tool registration
// ---------------------------------------------------------------

test('full toolkit provides 19 tools', function () {
    expect($this->toolkit->tools())->toHaveCount(19);
});

test('readonly toolkit provides only 4 read tools', function () {
    expect($this->readonlyToolkit->tools())->toHaveCount(4);
});

test('toolkit without history has 18 tools', function () {
    $tk = new FileSystemToolkit($this->root, false, []);

    expect($tk->tools())->toHaveCount(18);
});

test('tool names are correct for full toolkit', function () {
    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $this->toolkit->tools(),
    );

    expect($names)->toContain('read_file');
    expect($names)->toContain('list_dir');
    expect($names)->toContain('search_files');
    expect($names)->toContain('file_info');
    expect($names)->toContain('write_file');
    expect($names)->toContain('create_dir');
    expect($names)->toContain('delete_file');
    expect($names)->toContain('replace_in_file');
    expect($names)->toContain('insert_before');
    expect($names)->toContain('insert_after');
    expect($names)->toContain('replace_block');
    expect($names)->toContain('remove_lines');
    expect($names)->toContain('write_lines');
    expect($names)->toContain('batch_replace');
    expect($names)->toContain('indent_lines');
    expect($names)->toContain('append_to_file');
    expect($names)->toContain('edit_history');
    expect($names)->toContain('copy_file');
    expect($names)->toContain('move');
});

test('readonly tool names are read-only', function () {
    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $this->readonlyToolkit->tools(),
    );

    expect($names)->toBe(['read_file', 'list_dir', 'search_files', 'file_info']);
});

// ---------------------------------------------------------------
// Guidelines
// ---------------------------------------------------------------

test('guidelines includes mode indicator', function () {
    expect($this->toolkit->guidelines())->toContain('READ/WRITE');
    expect($this->readonlyToolkit->guidelines())->toContain('READ-ONLY');
});

test('guidelines does not include write tools in readonly mode', function () {
    $guidelines = $this->readonlyToolkit->guidelines();

    expect($guidelines)->not->toContain('write_file');
    expect($guidelines)->not->toContain('replace_in_file');
});

// ---------------------------------------------------------------
// Read tools
// ---------------------------------------------------------------

test('read_file tool reads entire file', function () {
    file_put_contents($this->root . '/greeting.txt', 'Hello World');

    $tool = findToolByName($this->toolkit, 'read_file');
    $result = $tool->execute(['path' => 'greeting.txt']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Hello World');
});

test('read_file tool reads line range', function () {
    file_put_contents($this->root . '/lines.txt', "L1\nL2\nL3\nL4\nL5");

    $tool = findToolByName($this->toolkit, 'read_file');
    $result = $tool->execute(['path' => 'lines.txt', 'from' => 2, 'to' => 3]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('L2');
    expect($result->content)->toContain('L3');
});

test('read_file tool returns error for missing file', function () {
    $tool = findToolByName($this->toolkit, 'read_file');
    $result = $tool->execute(['path' => 'nope.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('list_dir tool lists directory entries', function () {
    file_put_contents($this->root . '/a.php', '');
    mkdir($this->root . '/sub');

    $tool = findToolByName($this->toolkit, 'list_dir');
    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('a.php');
    expect($result->content)->toContain('sub');
});

test('file_info tool returns file metadata', function () {
    file_put_contents($this->root . '/info.txt', 'hello');

    $tool = findToolByName($this->toolkit, 'file_info');
    $result = $tool->execute(['path' => 'info.txt']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('5'); // size
    expect($result->content)->toContain('file');
})->skip(PHP_OS_FAMILY === 'Windows', 'mime_content_type() unreliable on Windows');

// ---------------------------------------------------------------
// Write tools
// ---------------------------------------------------------------

test('write_file tool creates a new file', function () {
    $tool = findToolByName($this->toolkit, 'write_file');
    $result = $tool->execute(['path' => 'new.txt', 'content' => 'fresh content']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->root . '/new.txt'))->toBe('fresh content');
});

test('write_file tool records edit history', function () {
    file_put_contents($this->root . '/tracked.txt', 'original');

    $tool = findToolByName($this->toolkit, 'write_file');
    $tool->execute(['path' => 'tracked.txt', 'content' => 'modified']);

    $edits = $this->history->list(null, 10);
    expect(count($edits))->toBeGreaterThanOrEqual(1);
    expect($edits[0]['operation'])->toBe('write_file');
});

test('delete_file tool removes a file', function () {
    file_put_contents($this->root . '/del.txt', 'bye');

    $tool = findToolByName($this->toolkit, 'delete_file');
    $result = $tool->execute(['path' => 'del.txt']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_exists($this->root . '/del.txt'))->toBeFalse();
});

test('create_dir tool makes directory', function () {
    $tool = findToolByName($this->toolkit, 'create_dir');
    $result = $tool->execute(['path' => 'new/nested']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(is_dir($this->root . '/new/nested'))->toBeTrue();
});

// ---------------------------------------------------------------
// Surgical edit tools
// ---------------------------------------------------------------

test('replace_in_file replaces text', function () {
    file_put_contents($this->root . '/rep.txt', "foo bar baz\nfoo qux");

    $tool = findToolByName($this->toolkit, 'replace_in_file');
    $result = $tool->execute([
        'path' => 'rep.txt',
        'search' => 'foo',
        'replace' => 'FOO',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->root . '/rep.txt'))->toBe("FOO bar baz\nFOO qux");
});

test('replace_in_file supports regex', function () {
    file_put_contents($this->root . '/regex.txt', 'price: $100' . "\n" . 'price: $200');

    $tool = findToolByName($this->toolkit, 'replace_in_file');
    $result = $tool->execute([
        'path' => 'regex.txt',
        'search' => '\\$(\\d+)',
        'replace' => '€$1',
        'is_regex' => true,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->root . '/regex.txt'))->toContain('€100');
});

test('insert_after inserts content after a match', function () {
    file_put_contents($this->root . '/ins.txt', "line1\nanchor\nline3");

    $tool = findToolByName($this->toolkit, 'insert_after');
    $result = $tool->execute([
        'path' => 'ins.txt',
        'anchor' => 'anchor',
        'content' => 'inserted',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $content = file_get_contents($this->root . '/ins.txt');
    expect($content)->toContain("anchor\ninserted\nline3");
});

test('insert_before inserts content before a match', function () {
    file_put_contents($this->root . '/insb.txt', "line1\nanchor\nline3");

    $tool = findToolByName($this->toolkit, 'insert_before');
    $result = $tool->execute([
        'path' => 'insb.txt',
        'anchor' => 'anchor',
        'content' => 'inserted',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $content = file_get_contents($this->root . '/insb.txt');
    expect($content)->toContain("line1\ninserted\nanchor");
});

test('remove_lines removes a line range', function () {
    file_put_contents($this->root . '/rm.txt', "1\n2\n3\n4\n5");

    $tool = findToolByName($this->toolkit, 'remove_lines');
    $result = $tool->execute(['path' => 'rm.txt', 'from' => 2, 'to' => 4]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->root . '/rm.txt'))->toBe("1\n5");
});

test('write_lines overwrites a line range', function () {
    file_put_contents($this->root . '/wl.txt', "1\n2\n3\n4\n5");

    $tool = findToolByName($this->toolkit, 'write_lines');
    $result = $tool->execute(['path' => 'wl.txt', 'from' => 2, 'to' => 3, 'content' => "two\nthree"]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->root . '/wl.txt'))->toBe("1\ntwo\nthree\n4\n5");
});

test('append_to_file appends content', function () {
    file_put_contents($this->root . '/app.txt', "existing\n");

    $tool = findToolByName($this->toolkit, 'append_to_file');
    $result = $tool->execute(['path' => 'app.txt', 'content' => 'appended']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->root . '/app.txt'))->toBe("existing\nappended");
});

test('replace_block replaces between markers', function () {
    file_put_contents($this->root . '/block.txt', "header\n// START\nold\nstuff\n// END\nfooter");

    $tool = findToolByName($this->toolkit, 'replace_block');
    $result = $tool->execute([
        'path' => 'block.txt',
        'start_marker' => '// START',
        'end_marker' => '// END',
        'new_content' => 'new content',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $content = file_get_contents($this->root . '/block.txt');
    // replace_block replaces lines from start marker through end marker (inclusive)
    expect($content)->toContain('header');
    expect($content)->toContain('new content');
    expect($content)->toContain('footer');
    expect($content)->not->toContain('old');
    expect($content)->not->toContain('stuff');
});

// ---------------------------------------------------------------
// Edit history tool
// ---------------------------------------------------------------

test('edit_history list shows recorded edits', function () {
    file_put_contents($this->root . '/h.txt', 'v1');
    $writeTool = findToolByName($this->toolkit, 'write_file');
    $writeTool->execute(['path' => 'h.txt', 'content' => 'v2']);

    $tool = findToolByName($this->toolkit, 'edit_history');
    $result = $tool->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('h.txt');
});

test('edit_history undo restores previous content', function () {
    file_put_contents($this->root . '/undo.txt', 'original');
    $writeTool = findToolByName($this->toolkit, 'write_file');
    $writeTool->execute(['path' => 'undo.txt', 'content' => 'changed']);

    expect(file_get_contents($this->root . '/undo.txt'))->toBe('changed');

    // Get the edit ID
    $edits = $this->history->list(null, 1);
    $editId = $edits[0]['id'];

    $tool = findToolByName($this->toolkit, 'edit_history');
    $result = $tool->execute(['action' => 'undo', 'edit_id' => $editId]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->root . '/undo.txt'))->toBe('original');
})->skip(PHP_OS_FAMILY === 'Windows', 'EditHistory undo path handling fails on Windows');

// ---------------------------------------------------------------
// list_dir max_depth parameter
// ---------------------------------------------------------------

test('list_dir with max_depth 1 omits entries deeper than one level', function () {
    mkdir($this->root . '/sub/deeper', 0755, true);
    file_put_contents($this->root . '/sub/deeper/hidden.txt', '');

    $tool = findToolByName($this->toolkit, 'list_dir');
    $result = $tool->execute(['recursive' => true, 'max_depth' => 1]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('sub');
    expect($result->content)->not->toContain('hidden.txt');
});

test('list_dir default max_depth 3 does not recurse beyond three levels', function () {
    mkdir($this->root . '/a/b/c/d', 0755, true);
    file_put_contents($this->root . '/a/b/c/d/deep.txt', '');

    $tool = findToolByName($this->toolkit, 'list_dir');
    // default max_depth=3: root→a(D0)→b(D1)→c(D2)→d(D3) listed, d/ won't recurse
    $result = $tool->execute(['recursive' => true]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->not->toContain('deep.txt');
});

// ---------------------------------------------------------------
// search_files with ** pattern
// ---------------------------------------------------------------

test('search_files with ** finds files in subdirectories', function () {
    mkdir($this->root . '/sub');
    file_put_contents($this->root . '/sub/nested.php', '');
    file_put_contents($this->root . '/sub/other.txt', '');

    $tool = findToolByName($this->toolkit, 'search_files');
    $result = $tool->execute(['pattern' => '**/*.php']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    // **/*.php requires a parent directory component; nested files are matched
    expect($result->content)->toContain('nested.php');
    expect($result->content)->not->toContain('other.txt');
});

// ---------------------------------------------------------------
// Copy tool
// ---------------------------------------------------------------

test('copy_file copies a single file', function () {
    file_put_contents($this->root . '/source.txt', 'original content');

    $tool = findToolByName($this->toolkit, 'copy_file');
    $result = $tool->execute(['source' => 'source.txt', 'destination' => 'dest.txt']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Copied file');
    expect(file_get_contents($this->root . '/dest.txt'))->toBe('original content');
    expect(file_exists($this->root . '/source.txt'))->toBeTrue(); // source preserved
});

test('copy_file copies a directory recursively', function () {
    mkdir($this->root . '/src-dir/nested', 0755, true);
    file_put_contents($this->root . '/src-dir/a.txt', 'A');
    file_put_contents($this->root . '/src-dir/nested/b.txt', 'B');

    $tool = findToolByName($this->toolkit, 'copy_file');
    $result = $tool->execute(['source' => 'src-dir', 'destination' => 'dst-dir']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Copied directory');
    expect(file_get_contents($this->root . '/dst-dir/a.txt'))->toBe('A');
    expect(file_get_contents($this->root . '/dst-dir/nested/b.txt'))->toBe('B');
});

test('copy_file errors for missing source', function () {
    $tool = findToolByName($this->toolkit, 'copy_file');
    $result = $tool->execute(['source' => 'missing.txt', 'destination' => 'dest.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('copy_file errors when copying to self', function () {
    file_put_contents($this->root . '/same.txt', 'data');

    $tool = findToolByName($this->toolkit, 'copy_file');
    $result = $tool->execute(['source' => 'same.txt', 'destination' => 'same.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('itself');
});

// ---------------------------------------------------------------
// Move tool
// ---------------------------------------------------------------

test('move renames a single file', function () {
    file_put_contents($this->root . '/old.txt', 'data');

    $tool = findToolByName($this->toolkit, 'move');
    $result = $tool->execute(['source' => 'old.txt', 'destination' => 'new.txt']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Moved file');
    expect(file_exists($this->root . '/old.txt'))->toBeFalse();
    expect(file_get_contents($this->root . '/new.txt'))->toBe('data');
});

test('move renames a directory', function () {
    mkdir($this->root . '/old-dir/sub', 0755, true);
    file_put_contents($this->root . '/old-dir/sub/f.txt', 'content');

    $tool = findToolByName($this->toolkit, 'move');
    $result = $tool->execute(['source' => 'old-dir', 'destination' => 'new-dir']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(is_dir($this->root . '/old-dir'))->toBeFalse();
    expect(file_get_contents($this->root . '/new-dir/sub/f.txt'))->toBe('content');
});

test('move records edit history for files', function () {
    file_put_contents($this->root . '/tracked.txt', 'tracked data');

    $tool = findToolByName($this->toolkit, 'move');
    $tool->execute(['source' => 'tracked.txt', 'destination' => 'moved.txt']);

    $edits = $this->history->list(limit: 10);
    expect($edits)->not->toBeEmpty();
    expect($edits[0]['operation'])->toBe('move');
});

test('move errors for missing source', function () {
    $tool = findToolByName($this->toolkit, 'move');
    $result = $tool->execute(['source' => 'missing.txt', 'destination' => 'dest.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ---------------------------------------------------------------
// Absolute path support
// ---------------------------------------------------------------

test('read_file accepts absolute path within workspace', function () {
    file_put_contents($this->root . '/abs-test.txt', "hello absolute");
    $tool = findToolByName($this->toolkit, 'read_file');
    $result = $tool->execute(['path' => $this->root . '/abs-test.txt']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('hello absolute');
});

test('write_file accepts absolute path within workspace', function () {
    $tool = findToolByName($this->toolkit, 'write_file');
    $result = $tool->execute(['path' => $this->root . '/abs-write.txt', 'content' => 'written via absolute']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->root . '/abs-write.txt'))->toBe('written via absolute');
});

test('read_file rejects absolute path outside workspace', function () {
    $tool = findToolByName($this->toolkit, 'read_file');
    $result = $tool->execute(['path' => '/etc/passwd']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('write_file rejects absolute path outside workspace', function () {
    $tool = findToolByName($this->toolkit, 'write_file');
    $result = $tool->execute(['path' => '/tmp/coqui-escape-test.txt', 'content' => 'should fail']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect(file_exists('/tmp/coqui-escape-test.txt'))->toBeFalse();
});

test('absolute path to read-only mount allows read', function () {
    $mountDir = sys_get_temp_dir() . '/coqui-mount-' . bin2hex(random_bytes(8));
    mkdir($mountDir, 0755, true);
    file_put_contents($mountDir . '/data.txt', 'mount content');
    $mountDir = realpath($mountDir);

    $tk = new FileSystemToolkit($this->root, false, [['realPath' => $mountDir, 'readOnly' => true]], $this->history);
    $tool = findToolByName($tk, 'read_file');
    $result = $tool->execute(['path' => $mountDir . '/data.txt']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('mount content');

    unlink($mountDir . '/data.txt');
    rmdir($mountDir);
});

test('absolute path to read-only mount blocks write', function () {
    $mountDir = sys_get_temp_dir() . '/coqui-mount-' . bin2hex(random_bytes(8));
    mkdir($mountDir, 0755, true);
    $mountDir = realpath($mountDir);

    $tk = new FileSystemToolkit($this->root, false, [['realPath' => $mountDir, 'readOnly' => true]], $this->history);
    $tool = findToolByName($tk, 'write_file');
    $result = $tool->execute(['path' => $mountDir . '/blocked.txt', 'content' => 'should fail']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect(file_exists($mountDir . '/blocked.txt'))->toBeFalse();

    rmdir($mountDir);
});

test('absolute path to rw mount allows write', function () {
    $mountDir = sys_get_temp_dir() . '/coqui-mount-' . bin2hex(random_bytes(8));
    mkdir($mountDir, 0755, true);
    $mountDir = realpath($mountDir);

    $tk = new FileSystemToolkit($this->root, false, [['realPath' => $mountDir, 'readOnly' => false]], $this->history);
    $tool = findToolByName($tk, 'write_file');
    $result = $tool->execute(['path' => $mountDir . '/allowed.txt', 'content' => 'mount write']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($mountDir . '/allowed.txt'))->toBe('mount write');

    unlink($mountDir . '/allowed.txt');
    rmdir($mountDir);
});

// ---------------------------------------------------------------
// Helper — uses $this->toolkit via Pest closures
// ---------------------------------------------------------------

function findToolByName(FileSystemToolkit $toolkit, string $name): \CarmeloSantana\PHPAgents\Contract\ToolInterface
{
    foreach ($toolkit->tools() as $tool) {
        if ($tool->toFunctionSchema()['function']['name'] === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("Tool '{$name}' not found");
}
