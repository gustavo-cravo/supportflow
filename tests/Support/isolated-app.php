<?php

use Illuminate\Contracts\Console\Kernel;

// Only used by the disposable browser QA server. Never load the main .env.
$root = dirname(__DIR__, 2);
$database = $root.'/storage/framework/testing/browser-qa.sqlite';
foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_CONFIG_CACHE' => $root.'/storage/framework/testing/no-config-cache.php',
    'APP_ROUTES_CACHE' => $root.'/storage/framework/testing/no-routes-cache.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($root.'/tests/Support');
$app->loadEnvironmentFrom('.env.browser-qa-does-not-exist');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== $database
    || config('database.connections.sqlite.url')) {
    throw new RuntimeException('Browser QA refuses to run outside its isolated SQLite database.');
}

return $app;
