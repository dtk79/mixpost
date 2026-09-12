<?php

use Illuminate\Support\Facades\Route;

// This is the source-controlled mirror of /root/mixpost/web.php. It is mounted
// read-only over /var/www/html/routes/web.php by the production Compose stack.
Route::get('/api/health', function () {
    return response()
        ->json([
            'ok' => true,
            'service' => 'mixpost',
        ])
        ->header('Cache-Control', 'no-store');
});

Route::get('/', function () {
    return view('home');
});
