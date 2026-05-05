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

function deposit(string $accountId, int $amount, string $key): void
{
    test()->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$accountId}/deposits", [
            'amount' => $amount,
            'idempotency_key' => $key,
        ])->assertStatus(201);
}

it('returns an empty data array for a fresh account', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/accounts/{$this->user->id}/ledger")
        ->assertStatus(200)
        ->assertJson(['data' => [], 'next_cursor' => null, 'prev_cursor' => null]);
});

it('lists ledger rows with all required fields, newest first', function (): void {
    deposit($this->user->id, 1_000, 'k1');
    deposit($this->user->id, 2_000, 'k2');
    deposit($this->user->id, 3_000, 'k3');

    $response = $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/accounts/{$this->user->id}/ledger")
        ->assertStatus(200);

    $data = $response->json('data');
    expect($data)->toHaveCount(3);

    foreach ($data as $row) {
        expect($row)->toHaveKeys(['id', 'transfer_id', 'transfer_type', 'direction', 'amount', 'created_at']);
        expect($row['direction'])->toBe('CREDIT');
        expect($row['transfer_type'])->toBe('DEPOSIT');
        expect(Str::isUuid($row['transfer_id']))->toBeTrue();
    }

    expect($data[0]['amount'])->toBe(3_000);
    expect($data[1]['amount'])->toBe(2_000);
    expect($data[2]['amount'])->toBe(1_000);
});

it('paginates with cursors yielding stable, non-overlapping pages', function (): void {
    for ($i = 1; $i <= 12; $i++) {
        deposit($this->user->id, $i * 100, "k-{$i}");
    }

    $page1 = $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/accounts/{$this->user->id}/ledger?limit=5")
        ->assertStatus(200);

    expect($page1->json('data'))->toHaveCount(5);
    expect($page1->json('next_cursor'))->not->toBeNull();

    $cursor1 = $page1->json('next_cursor');
    $page2 = $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/accounts/{$this->user->id}/ledger?limit=5&cursor={$cursor1}")
        ->assertStatus(200);

    expect($page2->json('data'))->toHaveCount(5);

    $cursor2 = $page2->json('next_cursor');
    $page3 = $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/accounts/{$this->user->id}/ledger?limit=5&cursor={$cursor2}")
        ->assertStatus(200);

    expect($page3->json('data'))->toHaveCount(2);
    expect($page3->json('next_cursor'))->toBeNull();

    // No overlap across pages.
    $idsAcrossPages = collect()
        ->merge(collect($page1->json('data'))->pluck('id'))
        ->merge(collect($page2->json('data'))->pluck('id'))
        ->merge(collect($page3->json('data'))->pluck('id'));

    expect($idsAcrossPages->count())->toBe(12);
    expect($idsAcrossPages->unique()->count())->toBe(12);
});

it('clamps limit to a maximum of 100', function (): void {
    for ($i = 1; $i <= 5; $i++) {
        deposit($this->user->id, 100, "lc-{$i}");
    }

    $response = $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/accounts/{$this->user->id}/ledger?limit=999")
        ->assertStatus(200);

    expect($response->json('data'))->toHaveCount(5);
    expect($response->json('next_cursor'))->toBeNull();
});

it('isolates ledger entries per account', function (): void {
    $bob = Account::create(['name' => 'bob']);
    deposit($this->user->id, 100, 'iso-1');
    deposit($bob->id, 200, 'iso-2');

    $aliceLedger = $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/accounts/{$this->user->id}/ledger")
        ->json('data');

    expect($aliceLedger)->toHaveCount(1);
    expect($aliceLedger[0]['amount'])->toBe(100);
});

it('returns 404 for an unknown account id', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->getJson('/api/v1/accounts/'.(string) Str::uuid7().'/ledger')
        ->assertStatus(404);
});

it('returns 404 for the system account', function (): void {
    $system = Account::where('is_system', true)->first();

    $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/accounts/{$system->id}/ledger")
        ->assertStatus(404);
});

it('rejects unauthenticated requests with 401', function (): void {
    $this->getJson("/api/v1/accounts/{$this->user->id}/ledger")
        ->assertStatus(401);
});
