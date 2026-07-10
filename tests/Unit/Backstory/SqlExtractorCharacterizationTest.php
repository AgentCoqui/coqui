<?php

declare(strict_types=1);

use CoquiBot\Coqui\Backstory\Extractor\SqlExtractor;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/coqui-sql-char-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->tempDir);
});

test('SqlExtractor renders CREATE TABLE + INSERT as a markdown table', function () {
    $path = $this->tempDir . '/data.sql';
    file_put_contents($path, <<<SQL
    CREATE TABLE people (id INT, name VARCHAR(50));
    INSERT INTO people (id, name) VALUES (1, 'Alice'), (2, 'Bob');
    SQL);

    $result = (new SqlExtractor())->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('Alice');
    expect($result->content)->toContain('Bob');
});

test('SqlExtractor preserves unsupported statements as fenced sql', function () {
    $path = $this->tempDir . '/proc.sql';
    file_put_contents($path, "CREATE PROCEDURE do_thing() BEGIN SELECT 1; END;");

    $result = (new SqlExtractor())->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('```sql');
});

test('SqlExtractor reports its supported extension', function () {
    expect((new SqlExtractor())->supportedExtensions())->toBe(['sql']);
});
