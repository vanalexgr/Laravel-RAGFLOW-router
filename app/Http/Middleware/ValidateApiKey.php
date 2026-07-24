<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    /** Request attribute holding the authenticated caller's credential fingerprint. */
    public const CALLER_ATTRIBUTE = 'api_caller_fingerprint';

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = config('services.api.key');

        if (empty($apiKey)) {
            return response()->json([
                'error' => [
                    'message' => 'API authentication is not configured on this server.',
                    'type' => 'server_error',
                    'code' => 'api_key_not_configured',
                ],
            ], 503);
        }

        $providedKey = $request->bearerToken() ?? $request->header('X-API-Key');

        if (empty($providedKey) || !hash_equals($apiKey, $providedKey)) {
            return response()->json([
                'error' => [
                    'message' => 'Invalid or missing API key. Use Authorization: Bearer YOUR_KEY header.',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401);
        }

        // Publish a non-reversible fingerprint of the credential that authenticated
        // this request, so downstream code can scope per-caller state without
        // handling the key itself. Credential handling stays in this one place.
        $request->attributes->set(self::CALLER_ATTRIBUTE, hash('sha256', $providedKey));

        return $next($request);
    }
}
