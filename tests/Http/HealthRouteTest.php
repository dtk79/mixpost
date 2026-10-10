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

it('preserves the production health registration and home route overrides', function () {
    $provider = file_get_contents(__DIR__.'/../../ops/production-overrides/AppServiceProvider.php');
    $routes = file_get_contents(__DIR__.'/../../ops/production-overrides/web.php');

    expect($provider)
        ->toContain("Route::get('/api/health'")
        ->toContain('static fn () => new JsonResponse(')
        ->toContain("'ok' => true")
        ->toContain("'service' => 'mixpost'")
        ->toContain("['Cache-Control' => 'no-store']")
        ->toContain("->name('peachy.health')");

    expect($routes)
        ->toContain("Route::get('/',")
        ->toContain("return view('home');");
});
