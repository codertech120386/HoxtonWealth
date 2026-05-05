<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Transfer;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.api_keys', 'key-a,key-b');
    Config::set('app.transfer_rate_limit', 3);
    Queue::fake();
    $this->seed(SystemAccountSeeder::class);
    $this->alice = Account::create(['name' => 'alice']);
    $this->bob = Account::create(['name' => 'bob']);

    // Each test starts with empty rate-limit buckets — the cache driver is
    // 'array' for tests, but make this explicit so a stray hit from an earlier
    // test never leaks across.
    RateLimiter::clear('transfers:'.hash('sha256', 'key-a'));
    RateLimiter::clear('transfers:'.hash('sha256', 'key-b'));
});

function transferBody(string $alice, string $bob, string $key): array
{
    return [
        'from_account_id' => $alice,
        'to_account_id' => $bob,
        'amount' => 100,
        'idempotency_key' => $key,
    ];
}

it('returns 202 for the first N requests and 429 for the (N+1)th', function (): void {
    for ($i = 1; $i <= 3; $i++) {
        $this->withHeader('X-Api-Key', 'key-a')
            ->postJson('/api/v1/transfers', transferBody($this->alice->id, $this->bob->id, "k-{$i}"))
            ->assertStatus(202);
    }

    $response = $this->withHeader('X-Api-Key', 'key-a')
        ->postJson('/api/v1/transfers', transferBody($this->alice->id, $this->bob->id, 'k-4'));

    $response->assertStatus(429);
    expect($response->headers->get('Retry-After'))->not->toBeNull();
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);

    expect(Transfer::count())->toBe(3);
});

it('keeps separate buckets for separate API keys', function (): void {
    // Exhaust key-a.
    for ($i = 1; $i <= 3; $i++) {
        $this->withHeader('X-Api-Key', 'key-a')
            ->postJson('/api/v1/transfers', transferBody($this->alice->id, $this->bob->id, "a-{$i}"))
            ->assertStatus(202);
    }
    $this->withHeader('X-Api-Key', 'key-a')
        ->postJson('/api/v1/transfers', transferBody($this->alice->id, $this->bob->id, 'a-4'))
        ->assertStatus(429);

    // key-b still has its full quota.
    $this->withHeader('X-Api-Key', 'key-b')
        ->postJson('/api/v1/transfers', transferBody($this->alice->id, $this->bob->id, 'b-1'))
        ->assertStatus(202);
});

it('does not rate-limit when the request is unauthenticated (401 wins first)', function (): void {
    // No X-Api-Key. Should always 401, never 429, even on a burst.
    for ($i = 1; $i <= 10; $i++) {
        $this->postJson('/api/v1/transfers', transferBody($this->alice->id, $this->bob->id, "u-{$i}"))
            ->assertStatus(401);
    }
});

it('does not rate-limit GET /transfers/{id}', function (): void {
    $first = $this->withHeader('X-Api-Key', 'key-a')
        ->postJson('/api/v1/transfers', transferBody($this->alice->id, $this->bob->id, 'k-status'))
        ->assertStatus(202);

    // Many status reads should not be throttled — only POST /transfers is.
    for ($i = 1; $i <= 10; $i++) {
        $this->withHeader('X-Api-Key', 'key-a')
            ->getJson('/api/v1/transfers/'.$first->json('transfer_id'))
            ->assertStatus(200);
    }
});
