<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Middleware\CorrelationId;
use App\Http\Requests\Api\V1\InitiateTransferRequest;
use App\Models\Account;
use App\Models\Transfer;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TransferController
{
    public function __construct(private readonly TransferService $transfers) {}

    #[OA\Post(
        path: '/api/v1/transfers',
        summary: 'Initiate an asynchronous transfer between two user accounts',
        description: 'Persists a PENDING transfer and returns immediately. A background worker (ProcessTransferJob) settles it: locks both accounts, verifies sender balance, posts double-entry ledger rows, and marks the transfer COMPLETED or FAILED. Idempotent on idempotency_key.',
        security: [['apiKey' => []]],
        tags: ['Transfers'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['from_account_id', 'to_account_id', 'amount', 'idempotency_key'],
                properties: [
                    new OA\Property(property: 'from_account_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'to_account_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'amount', type: 'integer', minimum: 1, example: 5000),
                    new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 120, example: 'transfer-abc-001'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 202,
                description: 'Accepted; processing asynchronously.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'transfer_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'status', type: 'string', example: 'PENDING'),
                ]),
            ),
            new OA\Response(response: 200, description: 'Idempotent replay — same idempotency_key was already accepted.'),
            new OA\Response(response: 401, description: 'Missing or invalid X-Api-Key'),
            new OA\Response(response: 404, description: 'One of the accounts does not exist, or one side is the system account'),
            new OA\Response(response: 422, description: 'Validation error (missing fields, amount ≤ 0, or from == to)'),
            new OA\Response(response: 429, description: 'Per-API-key rate limit exceeded; retry after the duration in the Retry-After header.'),
        ],
    )]
    public function store(InitiateTransferRequest $request): JsonResponse
    {
        $from = Account::find($request->validated('from_account_id'));
        $to = Account::find($request->validated('to_account_id'));

        if ($from === null || $to === null || $from->is_system || $to->is_system) {
            throw new NotFoundHttpException();
        }

        $idempotencyKey = (string) $request->validated('idempotency_key');
        $isReplay = Transfer::where('idempotency_key', $idempotencyKey)->exists();

        $transfer = $this->transfers->initiateTransfer(
            fromAccount: $from,
            toAccount: $to,
            amount: (int) $request->validated('amount'),
            idempotencyKey: $idempotencyKey,
            correlationId: (string) $request->attributes->get(CorrelationId::ATTR),
        );

        return response()->json([
            'transfer_id' => $transfer->id,
            'status' => $transfer->status->value,
        ], $isReplay ? 200 : 202);
    }

    #[OA\Get(
        path: '/api/v1/transfers/{id}',
        summary: 'Get transfer status',
        description: 'Returns the current state of any row in the transfers table — async user-to-user transfers (PENDING/PROCESSING/COMPLETED/FAILED) and synchronous deposits (always COMPLETED).',
        security: [['apiKey' => []]],
        tags: ['Transfers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'type', type: 'string', enum: ['TRANSFER', 'DEPOSIT']),
                    new OA\Property(property: 'status', type: 'string', enum: ['PENDING', 'PROCESSING', 'COMPLETED', 'FAILED']),
                    new OA\Property(property: 'from_account_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'to_account_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'amount', type: 'integer'),
                    new OA\Property(property: 'error_reason', type: 'string', nullable: true),
                    new OA\Property(property: 'attempts', type: 'integer'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Missing or invalid X-Api-Key'),
            new OA\Response(response: 404, description: 'Transfer not found'),
        ],
    )]
    public function show(string $id): JsonResponse
    {
        $transfer = Transfer::find($id);
        if ($transfer === null) {
            throw new NotFoundHttpException();
        }

        return response()->json([
            'id' => $transfer->id,
            'type' => $transfer->type->value,
            'status' => $transfer->status->value,
            'from_account_id' => $transfer->from_account_id,
            'to_account_id' => $transfer->to_account_id,
            'amount' => $transfer->amount,
            'error_reason' => $transfer->error_reason,
            'attempts' => $transfer->attempts,
            'created_at' => $transfer->created_at->toIso8601String(),
            'updated_at' => $transfer->updated_at->toIso8601String(),
        ]);
    }
}
