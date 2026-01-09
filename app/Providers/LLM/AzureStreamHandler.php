<?php

namespace App\Providers\LLM;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\OpenAI\Concerns\BuildsTools;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Chunk;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class AzureStreamHandler
{
    use BuildsTools;

    public function __construct(protected PendingRequest $client)
    {
    }

    public function handle(Request $request): Generator
    {
        $messages = $this->buildMessages($request);

        $payload = array_merge([
            'messages' => $messages,
            'stream' => true,
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'max_tokens' => $request->maxTokens(),
            'top_p' => $request->topP(),
            'tools' => $this->buildTools($request),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
        ]));

        $response = $this->client
            ->withOptions(['stream' => true])
            ->post('chat/completions', $payload);

        $body = $response->getBody();

        while (!$body->eof()) {
            $line = $body->read(8192);
            $lines = explode("\n", $line);

            foreach ($lines as $l) {
                $l = trim($l);
                if (empty($l) || $l === 'data: [DONE]') {
                    continue;
                }

                if (str_starts_with($l, 'data: ')) {
                    $json = substr($l, 6);
                    $data = json_decode($json, true);

                    if ($data === null) {
                        continue;
                    }

                    $delta = data_get($data, 'choices.0.delta', []);
                    $content = data_get($delta, 'content', '');
                    $finishReason = data_get($data, 'choices.0.finish_reason');

                    if ($content !== '' || $finishReason !== null) {
                        yield new Chunk(
                            text: $content,
                            finishReason: $finishReason ? $this->mapFinishReason($finishReason) : null,
                            usage: null,
                        );
                    }
                }
            }
        }
    }

    protected function mapFinishReason(string $reason): FinishReason
    {
        return match ($reason) {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
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
}
