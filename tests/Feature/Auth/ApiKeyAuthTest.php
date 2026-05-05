<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('app.api_keys', 'valid-key-1,valid-key-2');
});

it('returns 401 when X-Api-Key header is missing', function (): void {
    $this->getJson('/api/v1/ping')
        ->assertStatus(401)
        ->assertJson(['error' => 'Unauthorized']);
});

it('returns 401 when X-Api-Key is wrong', function (): void {
    $this->withHeader('X-Api-Key', 'not-a-real-key')
        ->getJson('/api/v1/ping')
        ->assertStatus(401);
});

it('returns 200 when X-Api-Key matches any configured key', function (): void {
    $this->withHeader('X-Api-Key', 'valid-key-2')
        ->getJson('/api/v1/ping')
        ->assertStatus(200)
        ->assertJson(['ok' => true]);
});

it('always echoes X-Correlation-Id, even on 401', function (): void {
    $response = $this->getJson('/api/v1/ping');

    $response->assertStatus(401);
    expect($response->headers->get('X-Correlation-Id'))->not->toBeEmpty();
});
