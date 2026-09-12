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
