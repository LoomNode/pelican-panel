<?php

use Illuminate\Support\Facades\Http;

it('serves a mod picture from Modrinth\'s file server to a signed-in user only', function () {
    Http::fake([
        'cdn.modrinth.com/data/fALzjamp/icon.png' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        'cdn.modrinth.com/data/fALzjamp/page.png' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
        'cdn.modrinth.com/data/fALzjamp/moved.png' => Http::response('', 302, ['Location' => 'http://127.0.0.1/']),
        'cdn.modrinth.com/data/fALzjamp/huge.png' => Http::response(str_repeat('x', 700 * 1024), 200, ['Content-Type' => 'image/png']),
    ]);

    $this->get('/mod-icon/fALzjamp/icon.png')->assertUnauthorized();
    Http::assertNothingSent();

    [$user] = generateTestAccount();
    $this->actingAs($user);

    $this->get('/mod-icon/fALzjamp/icon.png')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertContent('PNGDATA');
    expect($this->get('/mod-icon/fALzjamp/icon.png')->headers->get('Cache-Control'))->toContain('max-age=604800')->toContain('private');

    $this->get('/mod-icon/fALzjamp/page.png')->assertNotFound();
    $this->get('/mod-icon/fALzjamp/moved.png')->assertNotFound();
    $this->get('/mod-icon/fALzjamp/huge.png')->assertNotFound();
    $this->get('/mod-icon/fALzjamp/icon.svg')->assertNotFound();
    $this->get('/mod-icon/..%2F..%2Fetc/icon.png')->assertNotFound();
    Http::assertSentCount(5);
});
