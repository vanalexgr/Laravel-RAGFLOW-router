<?php

namespace App\Providers\LLM;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsTools;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Maps\ToolCallMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

class AzureTextHandler
{
    use BuildsTools;
    use CallsTools;
    use ProcessRateLimits;
    use ValidatesResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $message = data_get($data, 'choices.0.message', []);
        $toolCalls = data_get($message, 'tool_calls', []);

        $responseMessage = new AssistantMessage(
            content: data_get($message, 'content') ?? '',
            toolCalls: ToolCallMap::map(
                array_map(fn($tc) => [
                    'type' => 'function_call',
                    'id' => $tc['id'],
                    'call_id' => $tc['id'],
                    'name' => $tc['function']['name'],
                    'arguments' => $tc['function']['arguments'],
                ], $toolCalls),
                []
            ),
        );

        $request->addMessage($responseMessage);

        return match ($this->mapFinishReason($data)) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request, $response),
            FinishReason::Stop => $this->handleStop($data, $request, $response),
            FinishReason::Length => throw new PrismException('Azure OpenAI: max tokens exceeded'),
            default => throw new PrismException('Azure OpenAI: unknown finish reason'),
        };
    }

    protected function mapFinishReason(array $data): FinishReason
    {
        $reason = data_get($data, 'choices.0.finish_reason');

        return match ($reason) {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    protected function handleToolCalls(array $data, Request $request, ClientResponse $clientResponse): Response
    {
        $message = data_get($data, 'choices.0.message', []);
        $toolCalls = data_get($message, 'tool_calls', []);

        $mappedToolCalls = ToolCallMap::map(
            array_map(fn($tc) => [
                'type' => 'function_call',
                'id' => $tc['id'],
                'call_id' => $tc['id'],
                'name' => $tc['function']['name'],
                'arguments' => $tc['function']['arguments'],
            ], $toolCalls),
            []
        );

        $toolResults = $this->callTools($request->tools(), $mappedToolCalls);

        $request->addMessage(new ToolResultMessage($toolResults));

        $this->addStep($data, $request, $clientResponse, $toolResults);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    protected function handleStop(array $data, Request $request, ClientResponse $clientResponse): Response
    {
        $this->addStep($data, $request, $clientResponse);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        $messages = $this->buildMessages($request);

        $payload = array_merge([
            'messages' => $messages,
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'max_tokens' => $request->maxTokens(),
            'top_p' => $request->topP(),
            'tools' => $this->buildTools($request),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
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
                $msg = [
                    'role' => 'assistant',
                    'content' => $message->content,
                ];
                if (!empty($message->toolCalls)) {
                    $msg['tool_calls'] = array_map(fn($tc) => [
                        'id' => $tc->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $tc->name,
                            'arguments' => $tc->arguments(),
                        ],
                    ], $message->toolCalls);
                }
                $messages[] = $msg;
            } elseif ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $result->toolCallId,
                        'content' => $result->result,
                    ];
                }
            }
        }

        return $messages;
    }

    protected function addStep(
        array $data,
        Request $request,
        ClientResponse $clientResponse,
        array $toolResults = []
    ): void {
        $message = data_get($data, 'choices.0.message', []);
        $toolCalls = data_get($message, 'tool_calls', []);

        $this->responseBuilder->addStep(new Step(
            text: data_get($message, 'content') ?? '',
            finishReason: $this->mapFinishReason($data),
            toolCalls: ToolCallMap::map(
                array_map(fn($tc) => [
                    'type' => 'function_call',
                    'id' => $tc['id'],
                    'call_id' => $tc['id'],
                    'name' => $tc['function']['name'],
                    'arguments' => $tc['function']['arguments'],
                ], $toolCalls),
                []
            ),
            toolResults: $toolResults,
            usage: new Usage(
                promptTokens: data_get($data, 'usage.prompt_tokens', 0),
                completionTokens: data_get($data, 'usage.completion_tokens', 0),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($clientResponse),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
        ));
    }
}
