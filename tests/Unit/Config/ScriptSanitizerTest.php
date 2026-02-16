<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ScriptSanitizer;

test('allows safe code', function () {
    $sanitizer = new ScriptSanitizer();

    expect($sanitizer->isSafe('echo "Hello, world!";'))->toBeTrue();
    expect($sanitizer->isSafe('$client = new \Cloudflare\Client();'))->toBeTrue();
    expect($sanitizer->isSafe('$result = $api->listZones();'))->toBeTrue();
    expect($sanitizer->isSafe('$data = json_decode($response, true);'))->toBeTrue();
});

test('denies eval calls', function () {
    $sanitizer = new ScriptSanitizer();

    expect($sanitizer->isSafe('eval($code);'))->toBeFalse();
    expect($sanitizer->isSafe('eval ("malicious code");'))->toBeFalse();
});

test('denies exec family', function () {
    $sanitizer = new ScriptSanitizer();

    expect($sanitizer->isSafe('exec("rm -rf /");'))->toBeFalse();
    expect($sanitizer->isSafe('system("whoami");'))->toBeFalse();
    expect($sanitizer->isSafe('passthru("cat /etc/passwd");'))->toBeFalse();
    expect($sanitizer->isSafe('shell_exec("ls");'))->toBeFalse();
    expect($sanitizer->isSafe('proc_open("bash", $desc, $pipes);'))->toBeFalse();
    expect($sanitizer->isSafe('popen("command", "r");'))->toBeFalse();
});

test('denies backtick execution', function () {
    $sanitizer = new ScriptSanitizer();

    expect($sanitizer->isSafe('$output = `whoami`;'))->toBeFalse();
});

test('denies filesystem write functions', function () {
    $sanitizer = new ScriptSanitizer();

    expect($sanitizer->isSafe('file_put_contents("output.json", $data);'))->toBeFalse();
    expect($sanitizer->isSafe("file_put_contents('/etc/passwd', 'x');"))->toBeFalse();
    expect($sanitizer->isSafe('fopen("file.txt", "w");'))->toBeFalse();
    expect($sanitizer->isSafe('fwrite($fp, $data);'))->toBeFalse();
    expect($sanitizer->isSafe('mkdir("/tmp/evil");'))->toBeFalse();
    expect($sanitizer->isSafe('unlink("file.txt");'))->toBeFalse();
    expect($sanitizer->isSafe('rename("old.txt", "new.txt");'))->toBeFalse();
    expect($sanitizer->isSafe('copy("src.txt", "dst.txt");'))->toBeFalse();
});

test('denies directory traversal in includes', function () {
    $sanitizer = new ScriptSanitizer();

    expect($sanitizer->isSafe("require('/etc/passwd');"))->toBeFalse();
    expect($sanitizer->isSafe("include('/var/www/config.php');"))->toBeFalse();
});

test('denies privilege escalation patterns', function () {
    $sanitizer = new ScriptSanitizer();

    $issues = $sanitizer->validate('// sudo rm -rf /');
    expect($issues)->not->toBeEmpty();
});

test('denies putenv and ini_set', function () {
    $sanitizer = new ScriptSanitizer();

    expect($sanitizer->isSafe('putenv("PATH=/evil");'))->toBeFalse();
    expect($sanitizer->isSafe('ini_set("disable_functions", "");'))->toBeFalse();
});

test('returns specific issue descriptions', function () {
    $sanitizer = new ScriptSanitizer();

    $issues = $sanitizer->validate('eval($code); exec("whoami");');

    expect($issues)->toHaveCount(2);
    expect($issues[0])->toContain('eval');
    expect($issues[1])->toContain('exec');
});
