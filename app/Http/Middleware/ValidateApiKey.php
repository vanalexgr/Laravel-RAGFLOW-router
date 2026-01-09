<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = config('services.api.key');
        
        if (empty($apiKey)) {
            return $next($request);
        }

        $providedKey = $request->bearerToken() ?? $request->header('X-API-Key');

        if ($providedKey !== $apiKey) {
            return response()->json([
                'error' => [
                    'message' => 'Invalid API key',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401);
        }

        return $next($request);
    }
}
