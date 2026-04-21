<?php

declare(strict_types=1);

use CoquiBot\Coqui\Renderer\MarkdownRenderer;
use CoquiBot\Coqui\Renderer\StreamingMarkdownBuffer;
use CoquiBot\Coqui\Support\ImagePreviewService;
use CoquiBot\Coqui\Support\ImagePreviewState;

function makeMarkdownPreviewService(string $workspace): ImagePreviewService
{
    return new ImagePreviewService(
        $workspace,
        static fn(string $path, int $width): array => [
            'preview' => 'PREVIEW:' . basename($path) . ':' . $width,
            'preview_format' => 'ansi_blocks',
            'unavailable_reason' => null,
        ],
    );
}

// ─── MarkdownRenderer static render tests ───

test('renders heading with ANSI bold and color', function () {
    $result = MarkdownRenderer::render('# Hello World');

    expect($result)->toContain("\033[1;36m"); // bold cyan
    expect($result)->toContain('Hello World');
    expect($result)->toContain('# '); // heading prefix
});

test('renders bold text', function () {
    $result = MarkdownRenderer::render('This is **bold** text');

    expect($result)->toContain("\033[1m");
    expect($result)->toContain('bold');
    expect($result)->toContain("\033[22m");
});

test('renders italic text', function () {
    $result = MarkdownRenderer::render('This is *italic* text');

    expect($result)->toContain("\033[3m");
    expect($result)->toContain('italic');
    expect($result)->toContain("\033[23m");
});

test('renders inline code with yellow color', function () {
    $result = MarkdownRenderer::render('Use `composer install`');

    expect($result)->toContain("\033[33m"); // yellow
    expect($result)->toContain('`composer install`');
});

test('renders fenced code block with border', function () {
    $markdown = "```php\n\$x = 1;\n```";
    $result = MarkdownRenderer::render($markdown);

    expect($result)->toContain('╭─');
    expect($result)->toContain('│');
    expect($result)->toContain('╰─');
    expect($result)->toContain('$x = 1;');
});

test('does not render an empty fenced code block', function () {
    $result = MarkdownRenderer::render("```\n```\n");
    $plain = preg_replace('/\e\[[\d;]*m/', '', $result) ?? $result;

    expect($plain)->not->toContain('╭─');
    expect(trim($plain))->toBe('');
});

test('renders unordered list with bullet', function () {
    $result = MarkdownRenderer::render("- Item one\n- Item two");

    expect($result)->toContain('•');
    expect($result)->toContain('Item one');
    expect($result)->toContain('Item two');
});

test('renders unordered list without blank lines between items', function () {
    $result = MarkdownRenderer::render("- Item one\n- Item two");
    $plain = preg_replace('/\e\[[\d;]*m/', '', $result) ?? $result;

    expect($plain)->toContain("  • Item one\n  • Item two\n");
    expect($plain)->not->toContain("Item one\n\n  • Item two");
});

test('renders ordered list with numbers', function () {
    $result = MarkdownRenderer::render("1. First\n2. Second");

    expect($result)->toContain('1.');
    expect($result)->toContain('First');
    expect($result)->toContain('2.');
    expect($result)->toContain('Second');
});

test('renders ordered list without blank lines between items', function () {
    $result = MarkdownRenderer::render("1. First\n2. Second");
    $plain = preg_replace('/\e\[[\d;]*m/', '', $result) ?? $result;

    expect($plain)->toContain("  1. First\n  2. Second\n");
    expect($plain)->not->toContain("First\n\n  2. Second");
});

test('renders blockquote with bar', function () {
    $result = MarkdownRenderer::render('> Quote text');

    expect($result)->toContain('│');
    expect($result)->toContain('Quote text');
});

test('renders thematic break', function () {
    $result = MarkdownRenderer::render("---");

    expect($result)->toContain('─');
});

test('renders link with underline', function () {
    $result = MarkdownRenderer::render('[Example](https://example.com)');

    expect($result)->toContain("\033[4m"); // underline
    expect($result)->toContain('Example');
    expect($result)->toContain('https://example.com');
});

test('renders strikethrough', function () {
    $result = MarkdownRenderer::render('~~deleted~~');

    expect($result)->toContain("\033[9m"); // strikethrough
    expect($result)->toContain('deleted');
});

test('renders task list', function () {
    $result = MarkdownRenderer::render("- [x] Done\n- [ ] Todo");

    expect($result)->toContain('✓');
    expect($result)->toContain('○');
    expect($result)->toContain('Done');
    expect($result)->toContain('Todo');
});

test('renders table with borders', function () {
    $markdown = "| Name | Age |\n| --- | --- |\n| Alice | 30 |";
    $result = MarkdownRenderer::render($markdown);

    expect($result)->toContain('│');
    expect($result)->toContain('Name');
    expect($result)->toContain('Alice');
    expect($result)->toContain('30');
});

test('returns plain text for non-markdown input', function () {
    $result = MarkdownRenderer::render('Just plain text.');

    expect($result)->toContain('Just plain text.');
});

test('renders local markdown image previews when a preview service is provided', function () {
    $workspace = sys_get_temp_dir() . '/coqui-markdown-image-preview-' . bin2hex(random_bytes(8));
    $imagePath = $workspace . '/images/example.png';

    mkdir(dirname($imagePath), 0755, true);
    file_put_contents($imagePath, 'fixture');

    try {
        $result = MarkdownRenderer::render(
            '![Example](images/example.png)',
            makeMarkdownPreviewService($workspace),
            new ImagePreviewState(),
        );
        $plain = preg_replace('/\e\[[\d;]*m/', '', $result) ?? $result;

        expect($plain)->toContain('[image preview: Example]')
            ->and($plain)->toContain('PREVIEW:example.png:40');
    } finally {
        cleanupTestTree($workspace);
    }
});

// ─── StreamingMarkdownBuffer tests ───

test('streaming buffer accumulates and flushes on blank line', function () {
    $output = '';
    $buffer = new StreamingMarkdownBuffer(function (string $rendered) use (&$output): void {
        $output .= $rendered;
    });

    $buffer->feed("# Hello\n");
    $buffer->feed("\n");

    expect($output)->toContain('Hello');
    expect($output)->toContain("\033[1;36m"); // heading is bold cyan
});

test('streaming buffer holds code fence until close', function () {
    $output = '';
    $buffer = new StreamingMarkdownBuffer(function (string $rendered) use (&$output): void {
        $output .= $rendered;
    });

    $buffer->feed("```php\n");
    $buffer->feed("\$x = 1;\n");

    // Code block is not flushed yet — no closing fence
    expect($output)->toBe('');

    $buffer->feed("```\n");
    $buffer->feed("\n"); // blank line triggers flush of the code block

    expect($output)->toContain('$x = 1;');
    expect($output)->toContain('╭─');
});

test('streaming buffer renders fenced transcript chunks without phantom empty code blocks', function () {
    $output = '';
    $buffer = new StreamingMarkdownBuffer(function (string $rendered) use (&$output): void {
        $output .= $rendered;
    });

    $markdown = <<<'MD'
The loop just finished.

```
## Plan
TL;DR

1. Create `foo/`
   * src/Loop.php
   * composer.json

2. Verify
   * composer install
   * phpstan
```

The coder task was cancelled.

### Next steps
* restart
* manual
MD;

    foreach (str_split($markdown, 17) as $chunk) {
        $buffer->feed($chunk);
    }
    $buffer->flush();

    $plain = preg_replace('/\e\[[\d;]*m/', '', $output) ?? $output;

    expect(substr_count($plain, '  ╭─'))->toBe(1);
    expect($plain)->toContain("  │ ## Plan\n");
    expect($plain)->not->toContain("  ╭─\n  │ \n  ╰─");
    expect($plain)->toContain("  • restart\n  • manual\n");
    expect($plain)->not->toContain("restart\n\n  • manual");
});

test('streaming buffer flush emits remaining content', function () {
    $output = '';
    $buffer = new StreamingMarkdownBuffer(function (string $rendered) use (&$output): void {
        $output .= $rendered;
    });

    $buffer->feed('Some partial text');
    $buffer->flush();

    expect($output)->toContain('Some partial text');
});

test('streaming buffer reset clears state', function () {
    $output = '';
    $buffer = new StreamingMarkdownBuffer(function (string $rendered) use (&$output): void {
        $output .= $rendered;
    });

    $buffer->feed("```\ncode\n");
    $buffer->reset();
    $buffer->feed("# Fresh start\n\n");

    expect($output)->toContain('Fresh start');
    expect($output)->not->toContain('code');
});

test('streaming buffer only renders the first markdown image preview across flushes', function () {
    $workspace = sys_get_temp_dir() . '/coqui-streaming-markdown-image-preview-' . bin2hex(random_bytes(8));
    $firstPath = $workspace . '/images/first.png';
    $secondPath = $workspace . '/images/second.png';

    mkdir(dirname($firstPath), 0755, true);
    file_put_contents($firstPath, 'fixture');
    file_put_contents($secondPath, 'fixture');

    try {
        $output = '';
        $buffer = new StreamingMarkdownBuffer(
            function (string $rendered) use (&$output): void {
                $output .= $rendered;
            },
            makeMarkdownPreviewService($workspace),
            new ImagePreviewState(),
        );

        $buffer->feed("![First](images/first.png)\n\n");
        $buffer->feed("![Second](images/second.png)\n\n");
        $buffer->flush();

        $plain = preg_replace('/\e\[[\d;]*m/', '', $output) ?? $output;

        expect(substr_count($plain, 'PREVIEW:'))->toBe(1)
            ->and($plain)->toContain('[image preview: First]')
            ->and($plain)->toContain('[image: Second]');
    } finally {
        cleanupTestTree($workspace);
    }
});
