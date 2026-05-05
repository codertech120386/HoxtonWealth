<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Middleware\CorrelationId;
use App\Http\Requests\Api\V1\DepositRequest;
use App\Models\Account;
use App\Models\Transfer;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DepositController
{
    public function __construct(private readonly TransferService $transfers) {}

    #[OA\Post(
        path: '/api/v1/accounts/{id}/deposits',
        summary: 'Deposit funds into an account',
        description: 'Synchronously credits the account and debits the system account in a single transaction. Idempotent on the idempotency_key: replaying the same key returns the original transfer without writing again.',
        security: [['apiKey' => []]],
        tags: ['Deposits'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'idempotency_key'],
                properties: [
                    new OA\Property(property: 'amount', type: 'integer', minimum: 1, example: 10000, description: 'Amount in minor units (cents).'),
                    new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 120, example: 'deposit-abc-001'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Deposit completed (first request for this idempotency_key).',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'transfer_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'status', type: 'string', example: 'COMPLETED'),
                    new OA\Property(property: 'balance', type: 'integer', example: 10000),
                ]),
            ),
            new OA\Response(
                response: 200,
                description: 'Idempotent replay — same idempotency_key was already processed.',
            ),
            new OA\Response(response: 401, description: 'Missing or invalid X-Api-Key'),
            new OA\Response(response: 404, description: 'Account not found, or target is the system account'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(DepositRequest $request, string $accountId): JsonResponse
    {
        $account = Account::find($accountId);
        if ($account === null || $account->is_system) {
            throw new NotFoundHttpException();
        }

        $idempotencyKey = (string) $request->validated('idempotency_key');
        $isReplay = Transfer::where('idempotency_key', $idempotencyKey)->exists();

        $transfer = $this->transfers->deposit(
            userAccount: $account,
            amount: (int) $request->validated('amount'),
            idempotencyKey: $idempotencyKey,
            correlationId: (string) $request->attributes->get(CorrelationId::ATTR),
        );

        return response()->json([
            'transfer_id' => $transfer->id,
            'status' => $transfer->status->value,
            'balance' => $account->getBalance(),
        ], $isReplay ? 200 : 201);
    }
}
