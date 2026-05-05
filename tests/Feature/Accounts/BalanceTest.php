<?php

declare(strict_types=1);

use App\Models\Account;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.api_keys', 'test-key');
    $this->seed(SystemAccountSeeder::class);
    $this->user = Account::create(['name' => 'alice']);
});

it('returns 200 with id, name, and balance:0 for a fresh account', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/accounts/{$this->user->id}")
        ->assertStatus(200)
        ->assertJson([
            'id' => $this->user->id,
            'name' => 'alice',
            'balance' => 0,
        ]);
});

it('reflects deposits in the returned balance', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$this->user->id}/deposits", [
            'amount' => 12_345,
            'idempotency_key' => 'b-1',
        ])->assertStatus(201);

    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$this->user->id}/deposits", [
            'amount' => 7_655,
            'idempotency_key' => 'b-2',
        ])->assertStatus(201);

    $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/accounts/{$this->user->id}")
        ->assertStatus(200)
        ->assertJson(['balance' => 20_000]);
});

it('returns 404 for an unknown account id', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->getJson('/api/v1/accounts/'.(string) Str::uuid7())
        ->assertStatus(404);
});

it('returns 404 for the system account', function (): void {
    $system = Account::where('is_system', true)->first();

    $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/accounts/{$system->id}")
        ->assertStatus(404);
});

it('rejects unauthenticated requests with 401', function (): void {
    $this->getJson("/api/v1/accounts/{$this->user->id}")
        ->assertStatus(401);
});
