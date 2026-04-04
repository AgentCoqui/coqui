<?php

declare(strict_types=1);

/**
 * Generates config/documentation.json from doc file headings.
 * Run: php scripts/generate-documentation-index.php
 */

$projectRoot = dirname(__DIR__);

$files = [
    ['path' => 'docs/API.md', 'title' => 'Coqui HTTP API', 'description' => 'Complete REST API reference with all endpoints, authentication, SSE streaming, rate limiting, CORS, and safety documentation'],
    ['path' => 'docs/APP-API-USAGE.md', 'title' => 'Building Applications with the Coqui API', 'description' => 'Architecture overview, session lifecycle, SSE parsing, file uploads, and integration patterns for building apps on the Coqui API'],
    ['path' => 'docs/BACKGROUND-TASKS.md', 'title' => 'Background Tasks', 'description' => 'Background task system: lifecycle, concurrency, crash recovery, agent tools, REPL commands, and API endpoints'],
    ['path' => 'docs/COMMANDS.md', 'title' => 'Commands Reference', 'description' => 'All REPL slash commands, CLI commands, launcher modes, signal handling, and exit code behavior'],
    ['path' => 'docs/CONFIGURATION.md', 'title' => 'Configuration', 'description' => 'openclaw.json schema, agent defaults, model providers, API config, environment overrides, and setup wizard'],
    ['path' => 'docs/FEATURES.md', 'title' => 'Coqui Features', 'description' => 'High-level overview of all Coqui capabilities: multi-model orchestration, memory, extensibility, scheduling, vision, and more'],
    ['path' => 'docs/GITHUB-ACTIONS.md', 'title' => 'GitHub Actions CI', 'description' => 'CI workflow overview, PHP version matrix, local testing instructions, and troubleshooting'],
    ['path' => 'docs/ROLES.md', 'title' => 'Roles Reference', 'description' => 'Built-in role definitions, access levels, role-to-model mapping, custom role creation, and frontmatter schema'],
    ['path' => 'docs/SKILLS.md', 'title' => 'Coqui Skills', 'description' => 'Skills system: SKILL.md format, creation workflow, discovery, validation, progressive disclosure, and examples'],
    ['path' => 'docs/TESTING.md', 'title' => 'Testing', 'description' => 'Test layout, local commands, coverage workflow, and PCOV/Xdebug setup for Linux and macOS'],
    ['path' => 'docs/TOOLKITS.md', 'title' => 'Coqui Toolkits', 'description' => 'Toolkit development guide: anatomy, parameter types, credential management, auto-discovery, testing, and API reference'],
    ['path' => 'AGENTS.md', 'title' => 'Coqui Project Guidelines', 'description' => 'Internal architecture docs: php-agents foundation, credential system, tool gating, visibility, loops, schedules, webhooks, memory, safety, and coding standards'],
    ['path' => 'README.md', 'title' => 'Coqui Bot', 'description' => 'Project overview, installation, quick start, provider setup, built-in tools, extending Coqui, Docker, and performance'],
];

$result = ['version' => '1.0.0', 'files' => []];

foreach ($files as $meta) {
    $filePath = $projectRoot . '/' . $meta['path'];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES);
    $totalLines = count($lines);

    // Track fenced code blocks to skip headings inside them
    $inCodeBlock = false;
    $headings = [];

    foreach ($lines as $i => $line) {
        if (str_starts_with($line, '```')) {
            $inCodeBlock = !$inCodeBlock;
            continue;
        }
        if ($inCodeBlock) {
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m)) {
            $headings[] = [
                'heading' => trim($m[2]),
                'level' => strlen($m[1]),
                'line_start' => $i + 1,
            ];
        }
    }

    // Calculate line_end for each heading (next heading start - 1, or EOF)
    $sections = [];
    for ($i = 0; $i < count($headings); $i++) {
        $h = $headings[$i];
        $lineEnd = ($i + 1 < count($headings))
            ? $headings[$i + 1]['line_start'] - 1
            : $totalLines;
        $sections[] = [
            'heading' => $h['heading'],
            'level' => $h['level'],
            'line_start' => $h['line_start'],
            'line_end' => $lineEnd,
        ];
    }

    $result['files'][] = [
        'path' => $meta['path'],
        'title' => $meta['title'],
        'description' => $meta['description'],
        'sections' => $sections,
    ];
}

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$outPath = $projectRoot . '/config/documentation.json';
file_put_contents($outPath, $json . "\n");

echo "Written to {$outPath}\n";
echo count($result['files']) . " files indexed\n";

$totalSections = 0;
foreach ($result['files'] as $f) {
    $totalSections += count($f['sections']);
}
echo "{$totalSections} total sections\n";
