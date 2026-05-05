<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Hoxton Wallet & Ledger API',
    description: 'Event-driven, double-entry ledger-backed wallet platform. All endpoints require the X-Api-Key header.',
)]
#[OA\Server(
    url: 'http://localhost:8080',
    description: 'Local Docker stack',
)]
#[OA\SecurityScheme(
    securityScheme: 'apiKey',
    type: 'apiKey',
    in: 'header',
    name: 'X-Api-Key',
    description: 'Static API key. Configure values via the API_KEYS env var (comma-separated).',
)]
final class OpenApiSpec
{
    // Holder class for global OpenAPI annotations only.
}
