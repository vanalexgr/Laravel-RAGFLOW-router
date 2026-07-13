<?php

namespace App\Agents;

use App\Agents\Tools\RetrieveClinicalEvidenceTool;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Structured\PendingRequest as StructuredPendingRequest;
use Prism\Prism\Text\PendingRequest as TextPendingRequest;
use Vizra\VizraADK\Agents\BaseLlmAgent;
use Vizra\VizraADK\System\AgentContext;

class VascularConsultAgent extends BaseLlmAgent
{
    protected string $name = 'vascular_consult';

    protected string $description = 'ESVS vascular surgery decision-support agent.';

    protected ?string $provider = 'openai';

    protected string $model = 'gpt-5-chat';

    protected bool $includeConversationHistory = true;

    protected int $historyLimit = 12;

    protected string $contextStrategy = 'recent';

    protected int $maxSteps = 4;

    protected array $tools = [
        RetrieveClinicalEvidenceTool::class,
    ];

    public function __construct()
    {
        $this->provider = (string) config('vizra-adk.agents.vascular_consult.provider', 'openai');
        $this->model = (string) config(
            'vizra-adk.agents.vascular_consult.model',
            config('vizra-adk.default_model', 'gpt-5-chat')
        );

        parent::__construct();
    }

    public function getInstructions(): string
    {
        $path = resource_path('prompts/vascular_agent_system.md');
        if (is_file($path)) {
            $instructions = trim((string) file_get_contents($path));
            if ($instructions !== '') {
                return $instructions;
            }
        }

        return parent::getInstructions();
    }

    protected function buildPrismRequest(AgentContext $context, array $messages): TextPendingRequest|StructuredPendingRequest
    {
        $request = parent::buildPrismRequest($context, $messages);

        if ($this->shouldForceEvidenceRetrieval($context)) {
            $request->withToolChoice(ToolChoice::Any);
        }

        return $request;
    }

    private function shouldForceEvidenceRetrieval(AgentContext $context): bool
    {
        $input = strtolower(trim((string) ($context->getUserInput() ?? '')));
        if ($input === '') {
            return true;
        }

        if (preg_match(
            '/\b(what can (you|this app) do|what does this app do|how do i use|how can this app help|what model|who is|president|prompt|instructions)\b/i',
            $input
        ) === 1) {
            return false;
        }

        $hasPriorTurns = $context->getConversationHistory()->count() > 0
            || $context->getState('client_history', []) !== [];

        if ($hasPriorTurns) {
            return true;
        }

        $isSparsePatientCase = preg_match(
            '/\b(my patient|this patient|the patient|patient\b|case\b|\d{1,3}\s*(?:year[- ]old|yo)|male\b|female\b|man\b|woman\b)\b/i',
            $input
        ) === 1 && strlen($input) < 180;

        return ! $isSparsePatientCase;
    }
}
