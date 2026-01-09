<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = env('API_SECRET_KEY');
        
        if (empty($apiKey)) {
            return $next($request);
        }

        $providedKey = $request->bearerToken() ?? $request->header('X-API-Key');

        if (empty($providedKey) || $providedKey !== $apiKey) {
            return response()->json([
                'error' => [
                    'message' => 'Invalid or missing API key. Use Authorization: Bearer YOUR_KEY header.',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401);
        }

        return $next($request);
    }
}
