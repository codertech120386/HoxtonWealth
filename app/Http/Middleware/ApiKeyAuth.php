<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = (string) $request->header('X-Api-Key', '');

        if ($provided === '') {
            return $this->unauthorized();
        }

        $providedHash = hash('sha256', $provided);

        $configured = (string) config('app.api_keys', '');
        $allowed = array_filter(array_map('trim', explode(',', $configured)));

        foreach ($allowed as $key) {
            if (hash_equals(hash('sha256', $key), $providedHash)) {
                $request->attributes->set('api_key_hash', $providedHash);

                return $next($request);
            }
        }

        return $this->unauthorized();
    }

    private function unauthorized(): Response
    {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
}
