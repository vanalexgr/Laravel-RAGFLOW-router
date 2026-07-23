<?php

namespace App\Ai\Gate;

use App\Ai\Gate\Tools\RetrieveEsvsSnippetsTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * PATHWAY — stage 2, run once PER candidate guideline, in PARALLEL.
 *
 * Each PathwayAgent owns exactly one guideline key. It uses the
 * RetrieveEsvsSnippetsTool to pull that guideline's recommendation text, then
 * enumerates the live management pathways the guideline defines for this case
 * and, crucially, the discriminating variables whose values select between
 * them. Grounding each pathway in retrieved text is what turns the gate from
 * "reasoning from memory" into "reasoning from the guideline".
 *
 * The workflow fans these out with Laravel's Concurrency facade (one per key
 * from OrientAgent's candidate_guidelines) and collects the pathway sets.
 *
 * Constructor takes the guideline key so each parallel instance is scoped —
 * the same per-invocation-config pattern laravel/ai's own agents use.
 */
final class PathwayAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(
        private readonly string $guidelineKey,
    ) {
    }

    public function instructions(): string
    {
        return <<<TXT
You are a senior vascular surgeon working out the LIVE management pathways for a case,
grounded strictly in the ESVS guideline "{$this->guidelineKey}".

RETRIEVAL IS NOT ASSUMED TO SUCCEED. Retrieval can miss on the first query (wrong phrasing,
synonym mismatch, or the recommendation is filed under a different sub-topic). Do this:
1. Call retrieve_esvs_snippets (guideline_key="{$this->guidelineKey}") with a focused query.
2. If it returns NO_SNIPPETS or the snippets are off-target for the decision at hand, REFORMULATE
   the query and call again — try synonyms, the underlying anatomy/threshold, the specific
   recommendation topic, or a broader phrasing. Make a genuine effort (up to ~3 attempts).
3. Only after those attempts still yield nothing relevant may you conclude this guideline does not
   cover the case. Never conclude "not covered" from a single failed query.

Then enumerate pathways ONLY from what the retrieved snippets support. For each pathway give:
- pathway: the management option (e.g. "carotid endarterectomy", "best medical therapy").
- guideline_basis: the specific recommendation/threshold from the retrieved snippets that makes
  this pathway apply. If the snippets do not support a pathway, do not invent it.
- discriminating_variables: the case variables whose value selects for or against this pathway.

Also report:
- coverage: "covered" (snippets clearly address the decision), "partial" (some but incomplete
  support), or "not_covered" (genuinely nothing relevant AFTER your re-retrieval attempts).
- queries_tried: every retrieval query you issued, in order — this is the audit trail that proves
  re-retrieval happened before any "not_covered" verdict.

Return ONLY the structured object. No prose.
TXT;
    }

    /**
     * @return iterable<\Laravel\Ai\Contracts\Tool>
     */
    public function tools(): iterable
    {
        return [
            app(RetrieveEsvsSnippetsTool::class),
        ];
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'guideline_key' => $schema->string()->required(),
            'coverage' => $schema->string()->enum(['covered', 'partial', 'not_covered'])->required(),
            'queries_tried' => $schema->array()->items($schema->string())->required(),
            'pathways' => $schema->array()->items(
                $schema->object([
                    'pathway' => $schema->string()->required(),
                    'guideline_basis' => $schema->string()->required(),
                    'discriminating_variables' => $schema->array()->items($schema->string())->required(),
                ])
            )->required(),
        ];
    }
}
