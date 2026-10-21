<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

// Run from the repository root: php -S 127.0.0.1:8011 tests/Support/browser-qa-router.php
$app = require __DIR__.'/isolated-app.php';
if (! is_file(config('database.connections.sqlite.database'))) {
    http_response_code(503);
    exit('Initialize the isolated browser QA database first.');
}
$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-SupportFlow-QA', 'isolated-sqlite');
$response->send();
$kernel->terminate($request, $response);
