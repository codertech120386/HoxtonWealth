<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Middleware\CorrelationId;
use App\Http\Requests\Api\V1\CreateAccountRequest;
use App\Models\Account;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AccountController
{
    public function __construct(private readonly AccountService $accounts) {}

    #[OA\Post(
        path: '/api/v1/accounts',
        summary: 'Create an account',
        description: 'Creates a regular (non-system) account with zero starting balance.',
        security: [['apiKey' => []]],
        tags: ['Accounts'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Alice'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '019df700-1234-7abc-8def-0123456789ab'),
                    new OA\Property(property: 'name', type: 'string', example: 'Alice'),
                    new OA\Property(property: 'balance', type: 'integer', example: 0),
                ]),
            ),
            new OA\Response(response: 401, description: 'Missing or invalid X-Api-Key'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(CreateAccountRequest $request): JsonResponse
    {
        $correlationId = (string) $request->attributes->get(CorrelationId::ATTR);
        $account = $this->accounts->create(
            name: (string) $request->validated('name'),
            correlationId: $correlationId,
        );

        return response()->json([
            'id' => $account->id,
            'name' => $account->name,
            'balance' => 0,
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/accounts/{id}',
        summary: 'Get account with current balance',
        description: 'Balance is computed live from ledger_entries (signed sum: credit + / debit -).',
        security: [['apiKey' => []]],
        tags: ['Accounts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'balance', type: 'integer'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Missing or invalid X-Api-Key'),
            new OA\Response(response: 404, description: 'Account not found, or target is the system account'),
        ],
    )]
    public function show(string $id): JsonResponse
    {
        $account = Account::find($id);
        if ($account === null || $account->is_system) {
            throw new NotFoundHttpException();
        }

        return response()->json([
            'id' => $account->id,
            'name' => $account->name,
            'balance' => $account->getBalance(),
        ]);
    }
}
