<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Config::set('app.api_keys', 'valid-key-1');
});

it('generates a UUID when no X-Correlation-Id header is sent', function (): void {
    $response = $this->withHeader('X-Api-Key', 'valid-key-1')
        ->getJson('/api/v1/ping')
        ->assertStatus(200);

    $cid = $response->headers->get('X-Correlation-Id');
    expect($cid)->not->toBeNull();
    expect(Str::isUuid($cid))->toBeTrue();
});

it('echoes back a valid X-Correlation-Id when supplied', function (): void {
    $supplied = (string) Str::uuid7();

    $response = $this->withHeader('X-Api-Key', 'valid-key-1')
        ->withHeader('X-Correlation-Id', $supplied)
        ->getJson('/api/v1/ping')
        ->assertStatus(200);

    expect($response->headers->get('X-Correlation-Id'))->toBe($supplied);
});

it('replaces an invalid X-Correlation-Id with a fresh UUID', function (): void {
    $response = $this->withHeader('X-Api-Key', 'valid-key-1')
        ->withHeader('X-Correlation-Id', 'not-a-uuid')
        ->getJson('/api/v1/ping')
        ->assertStatus(200);

    $cid = $response->headers->get('X-Correlation-Id');
    expect($cid)->not->toBe('not-a-uuid');
    expect(Str::isUuid($cid))->toBeTrue();
});
