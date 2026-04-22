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

test('normalizes path separators and drive letter casing for comparison', function () {
    expect(PathHelper::normalizeForComparison('C:\\Users\\Foo\\workspace'))->toBe('c:/Users/Foo/workspace')
        ->and(PathHelper::normalizeForComparison('/tmp/workspace'))->toBe('/tmp/workspace');
});

test('detects absolute paths on Unix and Windows', function () {
    expect(PathHelper::isAbsolutePath('/tmp/workspace'))->toBeTrue()
        ->and(PathHelper::isAbsolutePath('C:\\Users\\Foo\\workspace'))->toBeTrue()
        ->and(PathHelper::isAbsolutePath('workspace/file.txt'))->toBeFalse();
});

test('checks whether paths stay within a base path', function () {
    expect(PathHelper::isWithinBasePath('C:\\Users\\Foo\\workspace\\images\\example.png', 'c:/Users/Foo/workspace'))->toBeTrue()
        ->and(PathHelper::isWithinBasePath('/tmp/workspace/images/example.png', '/tmp/workspace'))->toBeTrue()
        ->and(PathHelper::isWithinBasePath('/tmp/other/example.png', '/tmp/workspace'))->toBeFalse();
});

test('converts local file urls into normalized filesystem paths', function () {
    expect(PathHelper::fileUrlToPath('file:///tmp/example.png'))->toBe('/tmp/example.png')
        ->and(PathHelper::fileUrlToPath('file://localhost/tmp/example.png'))->toBe('/tmp/example.png')
        ->and(PathHelper::fileUrlToPath('file://C:\\Users\\Foo\\example.png'))->toBe('c:/Users/Foo/example.png')
        ->and(PathHelper::fileUrlToPath('file:///C:\\Users\\Foo\\example.png'))->toBe('c:/Users/Foo/example.png');
});

test('rejects non-local file urls', function () {
    expect(PathHelper::fileUrlToPath('file://server/share/example.png'))->toBeNull()
        ->and(PathHelper::fileUrlToPath('https://example.com/image.png'))->toBeNull();
});
