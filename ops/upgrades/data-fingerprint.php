<?php

// Read-only fingerprints of stable fields: output contains hashes and counts, never credentials.
// Run before and after migrations with HTTP, scheduler and workers stopped.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = [
    'users' => ['id', 'email', 'password'],
    'mixpost_workspaces' => ['id', 'uuid', 'name'],
    'mixpost_accounts' => ['id', 'uuid', 'provider', 'provider_id', 'access_token'],
    'mixpost_posts' => ['id', 'uuid', 'user_id', 'status', 'scheduled_at', 'published_at'],
    'mixpost_post_accounts' => ['id', 'post_id', 'account_id', 'provider_post_id', 'errors'],
    'mixpost_media' => ['id', 'uuid', 'path', 'disk'],
];

$result = [];
foreach ($tables as $table => $columns) {
    $hash = hash_init('sha256');
    $count = 0;
    foreach (DB::table($table)->orderBy('id')->select($columns)->cursor() as $row) {
        hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
        $count++;
    }
    $result[$table] = ['count' => $count, 'sha256' => hash_final($hash)];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
