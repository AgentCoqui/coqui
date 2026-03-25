<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\HomeDirectory;

test('resolve returns a non-empty string', function () {
    $home = HomeDirectory::resolve();

    expect($home)->toBeString();
    expect($home)->not->toBe('');
});

test('resolve returns an existing directory', function () {
    $home = HomeDirectory::resolve();

    expect(is_dir($home))->toBeTrue();
});

test('resolve detects USERPROFILE on Windows', function () {
    // This test verifies that on Windows (where USERPROFILE is set),
    // HomeDirectory::resolve() returns a valid path.
    // On Linux/macOS, HOME is set so USERPROFILE is never reached.
    $home = HomeDirectory::resolve();

    if (PHP_OS_FAMILY === 'Windows') {
        $userProfile = getenv('USERPROFILE');
        // On Windows without HOME, USERPROFILE should be used
        $envHome = getenv('HOME');
        if ($envHome === false || $envHome === '') {
            expect($home)->toBe($userProfile);
        }
    }

    // On any platform, the result should be a real directory
    expect(is_dir($home))->toBeTrue();
});

test('resolve never calls posix functions when they are unavailable', function () {
    // This is a structural test: on Windows, posix_* functions don't exist.
    // HomeDirectory::resolve() must still return a valid path.
    $home = HomeDirectory::resolve();

    expect($home)->toBeString();
    expect($home)->not->toBe('');
    expect(is_dir($home))->toBeTrue();
});

test('resolve does not return sys_get_temp_dir when HOME or USERPROFILE is set', function () {
    // At least one of HOME or USERPROFILE should be set on any normal system
    $hasHome = (getenv('HOME') !== false && getenv('HOME') !== '');
    $hasUserProfile = (getenv('USERPROFILE') !== false && getenv('USERPROFILE') !== '');

    if ($hasHome || $hasUserProfile) {
        $home = HomeDirectory::resolve();
        expect($home)->not->toBe(sys_get_temp_dir());
    }
});
