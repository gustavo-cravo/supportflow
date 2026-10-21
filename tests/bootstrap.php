<?php

// PHPUnit's force="true" alone does not replace inherited $_SERVER entries.
// Laravel checks those entries too; override all three sources before booting.
foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_CONFIG_CACHE' => __DIR__.'/Support/no-config-cache.php',
    'APP_ROUTES_CACHE' => __DIR__.'/Support/no-routes-cache.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require dirname(__DIR__).'/vendor/autoload.php';
