<?php

namespace App\Providers\LLM;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class AzureStructuredHandler
{
    use ProcessRateLimits;
    use ValidatesResponse;

    public function __construct(protected PendingRequest $client)
    {
    }

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $message = data_get($data, 'choices.0.message', []);
        $content = data_get($message, 'content') ?? '';

        $request->addMessage(new AssistantMessage($content));

        return new Response(
            steps: collect([]),
            responseMessages: $request->messages(),
            text: $content,
            structured: json_decode($content, true) ?? [],
            usage: new Usage(
                promptTokens: data_get($data, 'usage.prompt_tokens', 0),
                completionTokens: data_get($data, 'usage.completion_tokens', 0),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($response),
            ),
        );
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        $messages = $this->buildMessages($request);

        $payload = array_merge([
            'messages' => $messages,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'response',
                    'strict' => true,
                    'schema' => $request->schema()->toArray(),
                ],
            ],
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'max_tokens' => $request->maxTokens(),
            'top_p' => $request->topP(),
        ]));

        return $this->client->post('chat/completions', $payload);
    }

    protected function buildMessages(Request $request): array
    {
        $messages = [];

        foreach ($request->systemPrompts() as $systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt->content,
            ];
        }

        foreach ($request->messages() as $message) {
            if ($message instanceof UserMessage) {
                $messages[] = [
                    'role' => 'user',
                    'content' => $message->content,
                ];
            } elseif ($message instanceof AssistantMessage) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $message->content,
                ];
            }
        }

        return $messages;
    }
}
