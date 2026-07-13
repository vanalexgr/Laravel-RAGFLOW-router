<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class HttpLogging
{
    private array $sensitiveHeaders = [
        'authorization',
        'x-api-key',
        'api-key',
        'cookie',
        'set-cookie',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $this->logRequest($request, $response, $duration);

        return $response;
    }

    private function logRequest(Request $request, Response $response, float $durationMs): void
    {
        $logData = [
            'timestamp' => now()->toIso8601String(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'query_params' => $request->query(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'request_body' => $this->getRequestBody($request),
            'response' => [
                'status' => $response->getStatusCode(),
                'headers' => $this->sanitizeHeaders($response->headers->all()),
                'body' => $this->getResponseBody($response),
            ],
            'duration_ms' => $durationMs,
            'client_ip' => $request->ip(),
        ];

        Log::channel('http')->info('HTTP Request', $logData);
    }

    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $key => $values) {
            $lowerKey = strtolower($key);

            if (in_array($lowerKey, $this->sensitiveHeaders)) {
                $sanitized[$key] = ['[REDACTED]'];
            } else {
                $sanitized[$key] = $values;
            }
        }

        return $sanitized;
    }

    private function getRequestBody(Request $request): mixed
    {
        if (! config('logging.http_log_bodies', false)) {
            $body = $request->all();

            return [
                'question_length' => is_string($body['question'] ?? null)
                    ? mb_strlen($body['question'])
                    : 0,
                'history_count' => is_array($body['history'] ?? null)
                    ? count($body['history'])
                    : 0,
                'keys' => array_keys($body),
            ];
        }

        $contentType = $request->header('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $json = $request->json()->all();

            return $this->redactSensitiveFields($json);
        }

        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            return $this->redactSensitiveFields($request->all());
        }

        return null;
    }

    private function getResponseBody(Response $response): mixed
    {
        $content = $response->getContent();

        if (! config('logging.http_log_bodies', false)) {
            return [
                'status' => $response->getStatusCode(),
                'byte_length' => strlen($content),
            ];
        }

        $contentType = $response->headers->get('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->truncateResponse($decoded);
            }
        }

        if (strlen($content) > 2000) {
            return substr($content, 0, 2000).'... [truncated]';
        }

        return $content;
    }

    private function redactSensitiveFields(array $data): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'api_key', 'apikey'];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);

            foreach ($sensitiveFields as $sensitive) {
                if (str_contains($lowerKey, $sensitive)) {
                    $data[$key] = '[REDACTED]';
                    break;
                }
            }

            if (is_array($value)) {
                $data[$key] = $this->redactSensitiveFields($value);
            }
        }

        return $data;
    }

    private function truncateResponse(array $data, int $maxLength = 2000): array
    {
        $json = json_encode($data);

        if (strlen($json) <= $maxLength) {
            return $data;
        }

        if (isset($data['choices']) && is_array($data['choices'])) {
            foreach ($data['choices'] as $i => $choice) {
                if (isset($choice['message']['content']) && is_string($choice['message']['content'])) {
                    $content = $choice['message']['content'];
                    if (strlen($content) > 500) {
                        $data['choices'][$i]['message']['content'] = substr($content, 0, 500).'... [truncated]';
                    }
                }
            }
        }

        return $data;
    }
}
