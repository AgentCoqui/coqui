<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

function toolFromToolkit(ToolkitInterface $toolkit, string $name): ToolInterface
{
	foreach ($toolkit->tools() as $tool) {
		if ($tool->name() === $name) {
			return $tool;
		}
	}

	throw new InvalidArgumentException(sprintf('Tool "%s" not found.', $name));
}

function releaseTestObjectProperties(object $context): void
{
	foreach (array_keys(get_object_vars($context)) as $property) {
		if (is_object($context->{$property})) {
			$context->{$property} = null;
		}
	}

	gc_collect_cycles();
}

function cleanupSqliteTestDb(string $dbPath): void
{
	foreach (['', '-wal', '-shm'] as $suffix) {
		$path = $dbPath . $suffix;

		for ($attempt = 0; $attempt < 5; $attempt++) {
			clearstatcache(true, $path);
			if (!file_exists($path)) {
				break;
			}

			if (@unlink($path)) {
				break;
			}

			usleep(50_000);
		}
	}
}

function cleanupTestTree(string $dir): void
{
	if (!is_dir($dir)) {
		return;
	}

	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST,
	);

	foreach ($files as $file) {
		$path = $file->getPathname();
		if ($file->isDir()) {
			@rmdir($path);
		} else {
			@unlink($path);
		}
	}

	@rmdir($dir);
}

function createFakeComposerBinary(): string
{
	$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fake-composer-' . bin2hex(random_bytes(8));

	if (PHP_OS_FAMILY === 'Windows') {
		$path = $base . '.bat';
		file_put_contents($path, "@echo off\r\nexit /b 0\r\n");
		return $path;
	}

	$path = $base;
	file_put_contents($path, "#!/bin/sh\nexit 0\n");
	chmod($path, 0755);

	return $path;
}
