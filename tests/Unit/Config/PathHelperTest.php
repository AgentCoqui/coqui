<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\PathHelper;

test('trims trailing forward slash from Unix path', function () {
    expect(PathHelper::trimTrailingSlash('/home/user/workspace/'))->toBe('/home/user/workspace');
});

test('trims trailing backslash from Windows path', function () {
    expect(PathHelper::trimTrailingSlash('C:\\Users\\foo\\workspace\\'))->toBe('C:\\Users\\foo\\workspace');
});

test('trims multiple trailing slashes', function () {
    expect(PathHelper::trimTrailingSlash('/home/user/workspace///'))->toBe('/home/user/workspace');
});

test('trims multiple trailing backslashes', function () {
    expect(PathHelper::trimTrailingSlash('C:\\Users\\foo\\\\'))->toBe('C:\\Users\\foo');
});

test('trims mixed trailing separators', function () {
    expect(PathHelper::trimTrailingSlash('/home/user/workspace/\\/'))->toBe('/home/user/workspace');
});

test('preserves Unix root path', function () {
    expect(PathHelper::trimTrailingSlash('/'))->toBe('/');
});

test('preserves Windows drive root', function () {
    expect(PathHelper::trimTrailingSlash('C:\\'))->toBe('C:\\');
    expect(PathHelper::trimTrailingSlash('D:\\'))->toBe('D:\\');
});

test('preserves Windows drive root with forward slash', function () {
    expect(PathHelper::trimTrailingSlash('C:/'))->toBe('C:\\');
});

test('no-op when no trailing separator', function () {
    expect(PathHelper::trimTrailingSlash('/home/user/workspace'))->toBe('/home/user/workspace');
    expect(PathHelper::trimTrailingSlash('C:\\Users\\foo'))->toBe('C:\\Users\\foo');
});

test('handles empty string', function () {
    expect(PathHelper::trimTrailingSlash(''))->toBe('');
});

test('handles path with only backslashes', function () {
    expect(PathHelper::trimTrailingSlash('\\\\'))->toBe('');
});

test('handles lowercase drive letter', function () {
    expect(PathHelper::trimTrailingSlash('c:\\'))->toBe('c:\\');
});

test('handles relative path with trailing slash', function () {
    expect(PathHelper::trimTrailingSlash('workspace/'))->toBe('workspace');
    expect(PathHelper::trimTrailingSlash('workspace\\'))->toBe('workspace');
});
