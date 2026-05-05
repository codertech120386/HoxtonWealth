<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PingController
{
    #[OA\Get(
        path: '/api/v1/ping',
        summary: 'Liveness probe',
        description: 'Returns ok=true when the API key is valid. Useful for verifying X-Api-Key configuration.',
        security: [['apiKey' => []]],
        tags: ['Health'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                ]),
            ),
            new OA\Response(response: 401, description: 'Missing or invalid X-Api-Key'),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
