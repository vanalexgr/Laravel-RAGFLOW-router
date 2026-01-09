<?php

namespace App\Agents;

use Vizra\VizraADK\Agents\BaseLlmAgent;
use App\Tools\ConsultGuidelineTool;

class VascularExpertAgent extends BaseLlmAgent
{
    protected string $name = 'vascular_expert';

    protected string $description = 'Expert consultant for vascular surgery guidelines using ESVS documents.';

    protected string $instructions = 
        "You are a highly experienced Vascular Surgeon acting as a guideline consultant.

         YOUR PROCESS:
         1. Analyze the user's clinical question.
         2. Determine which ESVS guideline topic applies (e.g., Carotid, Aortic, Trauma). 
            - If the question covers multiple areas, call the tool multiple times.
         3. Use the 'consult_guideline' tool to retrieve the official rules.
         4. Synthesize the answer based ONLY on the tool output. 
         5. Cite specific Recommendations (e.g., 'Rec 12') in your answer.

         NEVER hallucinate rules. If the tool returns no info, state that.";

    protected ?string $provider = 'azure';
    
    protected string $model = 'gpt-5-chat';

    protected array $tools = [
        ConsultGuidelineTool::class,
    ];
}