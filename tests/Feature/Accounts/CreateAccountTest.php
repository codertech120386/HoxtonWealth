<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\Account;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.api_keys', 'test-key');
});

it('returns 201 with id, name, and balance:0 on valid input', function (): void {
    $response = $this->withHeader('X-Api-Key', 'test-key')
        ->postJson('/api/v1/accounts', ['name' => 'Alice']);

    $response->assertStatus(201)
        ->assertJsonStructure(['id', 'name', 'balance']);

    expect($response->json('name'))->toBe('Alice');
    expect($response->json('balance'))->toBe(0);
    expect(Str::isUuid($response->json('id')))->toBeTrue();

    expect(Account::where('id', $response->json('id'))->exists())->toBeTrue();
});

it('writes an AccountCreated audit log row carrying the request correlation id', function (): void {
    $cid = (string) Str::uuid7();

    $response = $this->withHeader('X-Api-Key', 'test-key')
        ->withHeader('X-Correlation-Id', $cid)
        ->postJson('/api/v1/accounts', ['name' => 'Bob'])
        ->assertStatus(201);

    $log = AuditLog::where('event_type', AuditEventType::AccountCreated->value)->first();
    expect($log)->not->toBeNull();
    expect($log->account_id)->toBe($response->json('id'));
    expect($log->correlation_id)->toBe($cid);
    expect($log->payload)->toBe(['name' => 'Bob']);
});

it('returns 422 when name is missing', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson('/api/v1/accounts', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('returns 422 when name is empty string', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson('/api/v1/accounts', ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('returns 422 when name exceeds 120 chars', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson('/api/v1/accounts', ['name' => str_repeat('x', 121)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('rejects unauthenticated requests with 401', function (): void {
    $this->postJson('/api/v1/accounts', ['name' => 'Eve'])
        ->assertStatus(401);

    expect(Account::count())->toBe(0);
});

it('does not allow setting is_system through the endpoint', function (): void {
    $response = $this->withHeader('X-Api-Key', 'test-key')
        ->postJson('/api/v1/accounts', ['name' => 'Mallory', 'is_system' => true])
        ->assertStatus(201);

    $account = Account::find($response->json('id'));
    expect($account->is_system)->toBeFalse();
});
