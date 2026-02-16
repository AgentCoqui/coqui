<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\SkillParser;
use CoquiBot\Coqui\Contract\SkillProperties;
use CoquiBot\Coqui\Exception\SkillParseException;

// Helper to create a temporary skill directory
function createSkillDir(string $name, string $content): string
{
    $base = sys_get_temp_dir() . '/coqui-test-skills-' . uniqid();
    $dir = $base . '/' . $name;
    mkdir($dir, 0755, true);
    file_put_contents($dir . '/SKILL.md', $content);

    return $dir;
}

function cleanupDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }

    rmdir($dir);
}

// --- findSkillMd ---

test('finds SKILL.md in directory', function () {
    $dir = createSkillDir('test-skill', "---\nname: test-skill\ndescription: Test\n---\nBody");

    $parser = new SkillParser();
    $path = $parser->findSkillMd($dir);

    expect($path)->not->toBeNull();
    expect(basename($path))->toBe('SKILL.md');

    cleanupDir(dirname($dir));
});

test('finds lowercase skill.md as fallback', function () {
    $base = sys_get_temp_dir() . '/coqui-test-lower-' . uniqid();
    $dir = $base . '/test-skill';
    mkdir($dir, 0755, true);
    file_put_contents($dir . '/skill.md', "---\nname: test-skill\ndescription: Test\n---\nBody");

    $parser = new SkillParser();
    $path = $parser->findSkillMd($dir);

    expect($path)->not->toBeNull();
    expect(basename($path))->toBe('skill.md');

    cleanupDir($base);
});

test('prefers SKILL.md over skill.md when both exist', function () {
    $base = sys_get_temp_dir() . '/coqui-test-both-' . uniqid();
    $dir = $base . '/test-skill';
    mkdir($dir, 0755, true);
    file_put_contents($dir . '/SKILL.md', "---\nname: test-skill\ndescription: Upper\n---\nBody");
    file_put_contents($dir . '/skill.md', "---\nname: test-skill\ndescription: Lower\n---\nBody");

    $parser = new SkillParser();
    $path = $parser->findSkillMd($dir);

    expect(basename($path))->toBe('SKILL.md');

    cleanupDir($base);
});

test('returns null when no skill.md exists', function () {
    $dir = sys_get_temp_dir() . '/coqui-test-empty-' . uniqid();
    mkdir($dir, 0755, true);

    $parser = new SkillParser();
    $path = $parser->findSkillMd($dir);

    expect($path)->toBeNull();

    rmdir($dir);
});

// --- parseFrontmatter ---

test('parses valid frontmatter', function () {
    $parser = new SkillParser();
    $content = "---\nname: test-skill\ndescription: A test skill\n---\n\n# Hello\n\nBody content here.";

    $result = $parser->parseFrontmatter($content);

    expect($result['metadata'])->toHaveKey('name', 'test-skill');
    expect($result['metadata'])->toHaveKey('description', 'A test skill');
    expect($result['body'])->toContain('# Hello');
    expect($result['body'])->toContain('Body content here.');
});

test('throws on content without frontmatter delimiters', function () {
    $parser = new SkillParser();

    $parser->parseFrontmatter('No frontmatter here');
})->throws(SkillParseException::class);

test('throws on unclosed frontmatter', function () {
    $parser = new SkillParser();

    $parser->parseFrontmatter("---\nname: test\ndescription: unclosed");
})->throws(SkillParseException::class);

// --- parseYaml (tested indirectly through parseFrontmatter) ---

test('parses simple key-value pairs', function () {
    $parser = new SkillParser();
    $result = $parser->parseFrontmatter("---\nname: my-skill\ndescription: Does things\nlicense: MIT\n---\nBody");

    expect($result['metadata']['name'])->toBe('my-skill');
    expect($result['metadata']['description'])->toBe('Does things');
    expect($result['metadata']['license'])->toBe('MIT');
});

test('parses quoted string values', function () {
    $parser = new SkillParser();
    $result = $parser->parseFrontmatter("---\nname: my-skill\ndescription: \"A quoted description\"\n---\nBody");

    expect($result['metadata']['description'])->toBe('A quoted description');
});

test('parses nested metadata map', function () {
    $parser = new SkillParser();
    $content = "---\nname: my-skill\ndescription: Test\nmetadata:\n  author: coqui\n  version: \"1.0\"\n---\nBody";
    $result = $parser->parseFrontmatter($content);

    expect($result['metadata']['metadata'])->toBeArray();
    expect($result['metadata']['metadata']['author'])->toBe('coqui');
    expect($result['metadata']['metadata']['version'])->toBe('1.0');
});

// --- readProperties ---

test('returns SkillProperties from valid SKILL.md', function () {
    $dir = createSkillDir('valid-skill', "---\nname: valid-skill\ndescription: A valid skill for testing.\n---\n\n# Instructions\n\nDo the thing.");

    $parser = new SkillParser();
    $props = $parser->readProperties($dir);

    expect($props)->toBeInstanceOf(SkillProperties::class);
    expect($props->name)->toBe('valid-skill');
    expect($props->description)->toBe('A valid skill for testing.');
    expect($props->path)->toBe($dir);

    cleanupDir(dirname($dir));
});

test('includes all optional fields when present', function () {
    $content = "---\nname: full-skill\ndescription: A full skill\nlicense: Apache-2.0\ncompatibility: Requires Python 3.10\nallowed-tools: Bash(git:*) Read\nmetadata:\n  author: test-org\n  version: \"2.0\"\n---\nBody";
    $dir = createSkillDir('full-skill', $content);

    $parser = new SkillParser();
    $props = $parser->readProperties($dir);

    expect($props->license)->toBe('Apache-2.0');
    expect($props->compatibility)->toBe('Requires Python 3.10');
    expect($props->allowedTools)->toBe('Bash(git:*) Read');
    expect($props->metadata)->toBe(['author' => 'test-org', 'version' => '2.0']);

    cleanupDir(dirname($dir));
});

test('throws SkillParseException when SKILL.md missing', function () {
    $dir = sys_get_temp_dir() . '/coqui-test-nomd-' . uniqid();
    mkdir($dir, 0755, true);

    $parser = new SkillParser();
    $parser->readProperties($dir);

    rmdir($dir);
})->throws(SkillParseException::class);

test('throws SkillParseException when name field missing', function () {
    $dir = createSkillDir('no-name', "---\ndescription: Missing name field\n---\nBody");

    $parser = new SkillParser();

    try {
        $parser->readProperties($dir);
    } finally {
        cleanupDir(dirname($dir));
    }
})->throws(SkillParseException::class);

test('throws SkillParseException when description field missing', function () {
    $dir = createSkillDir('no-desc', "---\nname: no-desc\n---\nBody");

    $parser = new SkillParser();

    try {
        $parser->readProperties($dir);
    } finally {
        cleanupDir(dirname($dir));
    }
})->throws(SkillParseException::class);

// --- readBody ---

test('returns markdown body after frontmatter', function () {
    $dir = createSkillDir('body-test', "---\nname: body-test\ndescription: Test\n---\n\n# Instructions\n\nDo the thing.\n");

    $parser = new SkillParser();
    $body = $parser->readBody($dir);

    expect($body)->toContain('# Instructions');
    expect($body)->toContain('Do the thing.');

    cleanupDir(dirname($dir));
});

test('returns empty string when body is empty', function () {
    $dir = createSkillDir('empty-body', "---\nname: empty-body\ndescription: Test\n---\n");

    $parser = new SkillParser();
    $body = $parser->readBody($dir);

    expect(trim($body))->toBe('');

    cleanupDir(dirname($dir));
});

// --- validate ---

test('valid skill returns empty errors', function () {
    $dir = createSkillDir('valid-skill', "---\nname: valid-skill\ndescription: A valid skill.\n---\nBody");

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->toBeEmpty();

    cleanupDir(dirname($dir));
});

test('rejects uppercase names', function () {
    $dir = createSkillDir('Upper-Name', "---\nname: Upper-Name\ndescription: Test\n---\nBody");

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('lowercase');

    cleanupDir(dirname($dir));
});

test('rejects names longer than 64 characters', function () {
    $longName = str_repeat('a', 65);
    $dir = createSkillDir($longName, "---\nname: {$longName}\ndescription: Test\n---\nBody");

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('at most 64');

    cleanupDir(dirname($dir));
});

test('rejects names with leading hyphens', function () {
    $dir = createSkillDir('-leading', "---\nname: -leading\ndescription: Test\n---\nBody");

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('start with a hyphen');

    cleanupDir(dirname($dir));
});

test('rejects names with trailing hyphens', function () {
    $dir = createSkillDir('trailing-', "---\nname: trailing-\ndescription: Test\n---\nBody");

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('end with a hyphen');

    cleanupDir(dirname($dir));
});

test('rejects names with consecutive hyphens', function () {
    $dir = createSkillDir('bad--name', "---\nname: bad--name\ndescription: Test\n---\nBody");

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('consecutive hyphens');

    cleanupDir(dirname($dir));
});

test('rejects names with invalid characters like underscores', function () {
    $dir = createSkillDir('bad_name', "---\nname: bad_name\ndescription: Test\n---\nBody");

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('alphanumeric');

    cleanupDir(dirname($dir));
});

test('rejects name/directory mismatch', function () {
    $dir = createSkillDir('dir-name', "---\nname: different-name\ndescription: Test\n---\nBody");

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('does not match directory');

    cleanupDir(dirname($dir));
});

test('rejects descriptions longer than 1024 characters', function () {
    $longDesc = str_repeat('a', 1025);
    $dir = createSkillDir('long-desc', "---\nname: long-desc\ndescription: {$longDesc}\n---\nBody");

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('at most 1024');

    cleanupDir(dirname($dir));
});

test('rejects compatibility longer than 500 characters', function () {
    $longCompat = str_repeat('a', 501);
    $dir = createSkillDir('long-compat', "---\nname: long-compat\ndescription: Test\ncompatibility: {$longCompat}\n---\nBody");

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('at most 500');

    cleanupDir(dirname($dir));
});

test('rejects unexpected frontmatter fields', function () {
    $dir = createSkillDir('bad-field', "---\nname: bad-field\ndescription: Test\nfoo: bar\n---\nBody");

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('Unexpected');
    expect(implode(' ', $errors))->toContain('foo');

    cleanupDir(dirname($dir));
});

test('accepts all allowed optional fields', function () {
    $content = "---\nname: all-fields\ndescription: Test\nlicense: MIT\ncompatibility: Needs PHP 8.4\nallowed-tools: Bash Read\nmetadata:\n  key: value\n---\nBody";
    $dir = createSkillDir('all-fields', $content);

    $parser = new SkillParser();
    $errors = $parser->validate($dir);

    expect($errors)->toBeEmpty();

    cleanupDir(dirname($dir));
});

// --- validateName ---

test('validateName accepts valid names', function () {
    $parser = new SkillParser();

    expect($parser->validateName('my-skill'))->toBeEmpty();
    expect($parser->validateName('skill123'))->toBeEmpty();
    expect($parser->validateName('a'))->toBeEmpty();
});

test('validateName rejects empty name', function () {
    $parser = new SkillParser();
    $errors = $parser->validateName('');

    expect($errors)->not->toBeEmpty();
});

test('validateName rejects invalid formats', function () {
    $parser = new SkillParser();

    expect($parser->validateName('UPPER'))->not->toBeEmpty();
    expect($parser->validateName('-leading'))->not->toBeEmpty();
    expect($parser->validateName('trailing-'))->not->toBeEmpty();
    expect($parser->validateName('bad--name'))->not->toBeEmpty();
    expect($parser->validateName('under_score'))->not->toBeEmpty();
});
