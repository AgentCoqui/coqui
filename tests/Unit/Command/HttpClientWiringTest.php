<?php

declare(strict_types=1);

test('non-repl agent entrypoints inject ReactHttpClientAdapter', function () {
    $apiCommandSource = file_get_contents(__DIR__ . '/../../../src/Command/ApiCommand.php');
    $factorySource = file_get_contents(__DIR__ . '/../../../src/Command/AgentRunnerFactory.php');
    $turnRunSource = file_get_contents(__DIR__ . '/../../../src/Command/TurnRunCommand.php');
    $taskRunSource = file_get_contents(__DIR__ . '/../../../src/Command/TaskRunCommand.php');

    expect($apiCommandSource)->toContain('use CoquiBot\\Coqui\\Provider\\ReactHttpClientAdapter;')
        ->and($apiCommandSource)->toContain('new ProviderFactory($boot->config(), new ReactHttpClientAdapter())');

    expect($factorySource)->toContain('use CoquiBot\\Coqui\\Provider\\ReactHttpClientAdapter;')
        ->and($factorySource)->toContain('httpClient: $httpClient ?? new ReactHttpClientAdapter()');

    expect($turnRunSource)->toContain('AgentRunnerFactory::create(');
    expect($taskRunSource)->toContain('AgentRunnerFactory::create(');
});