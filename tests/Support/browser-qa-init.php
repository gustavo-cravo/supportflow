<?php

use Illuminate\Contracts\Console\Kernel;

$app = require __DIR__.'/isolated-app.php';
$database = config('database.connections.sqlite.database');
if (file_exists($database)) {
    fwrite(STDERR, "Isolated database already exists; preserved without resetting.\n");
    exit(1);
}
if (! is_dir(dirname($database))) {
    mkdir(dirname($database), 0777, true);
}
touch($database);
exit($app->make(Kernel::class)->call('migrate', ['--force' => true]));
