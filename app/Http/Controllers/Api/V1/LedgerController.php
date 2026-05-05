<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Account;
use App\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LedgerController
{
    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 50;

    #[OA\Get(
        path: '/api/v1/accounts/{id}/ledger',
        summary: 'Paginated ledger history for an account',
        description: 'Returns ledger entries newest first, cursor-paginated. Each row carries the parent transfer_id (the spec\'s transaction_id) and transfer_type (TRANSFER or DEPOSIT).',
        security: [['apiKey' => []]],
        tags: ['Accounts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50)),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Opaque cursor returned by the previous page.'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'data',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'transfer_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'transfer_type', type: 'string', enum: ['TRANSFER', 'DEPOSIT']),
                            new OA\Property(property: 'direction', type: 'string', enum: ['DEBIT', 'CREDIT']),
                            new OA\Property(property: 'amount', type: 'integer'),
                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        ]),
                    ),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'prev_cursor', type: 'string', nullable: true),
                ]),
            ),
            new OA\Response(response: 401, description: 'Missing or invalid X-Api-Key'),
            new OA\Response(response: 404, description: 'Account not found, or target is the system account'),
        ],
    )]
    public function index(Request $request, string $id): JsonResponse
    {
        $account = Account::find($id);
        if ($account === null || $account->is_system) {
            throw new NotFoundHttpException();
        }

        $limit = (int) $request->query('limit', (string) self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $page = LedgerEntry::query()
            ->where('account_id', $account->id)
            ->with('transfer:id,type')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate($limit);

        $data = $page->getCollection()->map(fn (LedgerEntry $e): array => [
            'id' => $e->id,
            'transfer_id' => $e->transfer_id,
            'transfer_type' => $e->transfer->type->value,
            'direction' => $e->direction->value,
            'amount' => $e->amount,
            'created_at' => $e->created_at->toIso8601String(),
        ])->all();

        return response()->json([
            'data' => $data,
            'next_cursor' => $page->nextCursor()?->encode(),
            'prev_cursor' => $page->previousCursor()?->encode(),
        ]);
    }
}
