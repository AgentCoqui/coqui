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

/**
	* Create a non-blocking duplex stream pair for interactive input tests.
	*
	* Prefers Unix domain socket pairs when available, but falls back to a local
	* TCP listener on platforms where STREAM_PF_UNIX pairs are unavailable.
	*
	* @return array{0: resource, 1: resource}
	*/
function createNonBlockingStreamPair(): array
{
	$pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
	if (
		is_array($pair)
		&& isset($pair[0], $pair[1])
		&& is_resource($pair[0])
		&& is_resource($pair[1])
	) {
		stream_set_blocking($pair[0], false);
		stream_set_blocking($pair[1], false);

		return [$pair[0], $pair[1]];
	}

	$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
	assert(is_resource($server), sprintf('Failed to create TCP test socket server: %s (%d)', $error, $errno));

	$address = stream_socket_get_name($server, false);
	assert(is_string($address) && $address !== '');

	$writer = @stream_socket_client('tcp://' . $address, $errno, $error);
	assert(is_resource($writer), sprintf('Failed to connect TCP test socket client: %s (%d)', $error, $errno));

	$reader = @stream_socket_accept($server, 1.0);
	@fclose($server);
	assert(is_resource($reader), 'Failed to accept TCP test socket connection.');

	stream_set_blocking($reader, false);
	stream_set_blocking($writer, false);

	return [$reader, $writer];
}
