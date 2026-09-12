<?php

it('answers health checks without rendering the application', function () {
    $this->getJson(route('mixpost.health'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            'ok' => true,
            'service' => 'mixpost',
        ]);
});

it('keeps the mounted production route override under source control', function () {
    $override = file_get_contents(__DIR__.'/../../ops/production-overrides/web.php');

    expect($override)
        ->toContain("Route::get('/api/health'")
        ->toContain("'ok' => true")
        ->toContain("'service' => 'mixpost'")
        ->toContain("->header('Cache-Control', 'no-store')")
        ->toContain("Route::get('/',")
        ->toContain("return view('home');");
});
