<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

test('CI runs migrations outside PHPUnit using only an isolated SQLite database', function () {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/tests.yml');
    $environment = $workflow['jobs']['ci']['env'];

    // Validate before launching Artisan: never let this regression touch MySQL.
    expect($environment['APP_ENV'])->toBe('testing');
    expect($environment['DB_CONNECTION'])->toBe('sqlite');
    expect($environment['DB_DATABASE'])->toBe(':memory:');
    expect($environment['DB_URL'])->toBe('');
    expect(is_file($root.'/'.$environment['APP_CONFIG_CACHE']))->toBeFalse();

    $process = new Process(
        [PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'],
        $root,
        $environment,
    );
    $process->setTimeout(60);
    $process->run();

    expect($process->isSuccessful(), $process->getOutput().$process->getErrorOutput())->toBeTrue();
    expect($process->getOutput())->toContain('create_support_tickets_table', 'add_verification_source_to_support_tickets_table');
});
