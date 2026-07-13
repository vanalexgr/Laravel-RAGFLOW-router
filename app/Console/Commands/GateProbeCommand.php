<?php

namespace App\Console\Commands;

use App\Services\AgenticGate\AgenticGateService;
use App\Services\PreRetrievalService;
use Illuminate\Console\Command;

/**
 * PROTOTYPE harness: run the current gate (PreRetrievalService) and the new
 * agentic gate (AgenticGateService) side by side on the same case so their
 * clarification questions and reasoning can be compared.
 *
 *   php artisan gate:probe "65 yo, 65% right carotid stenosis, aphasia, left handed"
 *   php artisan gate:probe "..." --history="prior turn" --history="another"
 *   php artisan gate:probe "..." --json
 */
class GateProbeCommand extends Command
{
    protected $signature = 'gate:probe
        {case : the clinical case / question}
        {--history=* : prior conversation turns (repeatable)}
        {--json : dump the raw agentic-gate JSON}';

    protected $description = 'Compare the current gate vs the prototype agentic gate on a case';

    public function handle(PreRetrievalService $current): int
    {
        $case = (string) $this->argument('case');
        $history = array_values(array_filter((array) $this->option('history')));

        // Build the agentic gate with the real guideline keys so its routing
        // aligns with production routing.
        $guidelineKeys = [];
        foreach (config('guidelines.categories', []) as $category) {
            foreach (($category['guidelines'] ?? []) as $key => $info) {
                $guidelineKeys[$key] = $info['name'] ?? $key;
            }
        }
        $agentic = new AgenticGateService($guidelineKeys);

        $this->line('');
        $this->info('CASE: '.$case);
        if ($history) {
            $this->line('HISTORY: '.implode(' | ', $history));
        }

        // ---- CURRENT GATE ------------------------------------------------
        $this->line('');
        $this->comment('════ CURRENT GATE (PreRetrievalService — closed whitelist) ════');
        $t0 = microtime(true);
        $old = $current->analyse($case, $history);
        $oldMs = (int) round((microtime(true) - $t0) * 1000);

        $this->line('soft_warn : '.($old->softWarn ? 'true (asking)' : 'false (proceed)'));
        $this->line('diagnosis : '.$old->provisionalDiagnosis);
        $this->line('guidelines: '.implode(', ', $old->guidelines));
        $this->line('questions :');
        foreach ($old->clarificationQuestions ?: ['(none)'] as $q) {
            $this->line('  - '.$q);
        }
        $this->line("latency   : {$oldMs} ms");

        // ---- AGENTIC GATE ------------------------------------------------
        $this->line('');
        $this->comment('════ AGENTIC GATE (orient → probe → proceed) ════');
        $t1 = microtime(true);
        $new = $agentic->probe($case, $history);
        $newMs = (int) round((microtime(true) - $t1) * 1000);

        if (! empty($new['error'])) {
            $this->error('gate error: '.$new['error']);
        }

        $this->line('decision   : '.strtoupper($new['decision'] ?? 'n/a')
            .'   (confidence '.number_format((float) ($new['confidence'] ?? 0), 2)
            .', high-impact unknowns '.($new['high_impact_unknown_count'] ?? 0).')');
        $this->line('differential: '.implode('; ', $new['differential'] ?? []));
        $this->line('routed      : '.implode(', ', $new['routed_guidelines'] ?? []));

        $this->line('decision pathways:');
        foreach ($new['decision_pathways'] ?? [] as $p) {
            if (! is_array($p)) {
                continue;
            }
            $this->line('  • '.($p['pathway'] ?? '?').' — '.($p['guideline_basis'] ?? ''));
        }

        $this->line('unknowns (ranked):');
        foreach ($new['unknowns'] ?? [] as $u) {
            if (! is_array($u)) {
                continue;
            }
            $mark = ($u['currently_known'] ?? false) ? '[known]' : '['.strtoupper($u['branch_impact'] ?? '?').']';
            $this->line('  '.$mark.' '.($u['variable'] ?? '?').' — '.($u['why_it_changes_management'] ?? ''));
        }

        $this->line('questions to ask:');
        foreach ($new['questions'] ?: [['question' => '(none — would proceed)']] as $q) {
            $text = is_array($q) ? ($q['question'] ?? '') : (string) $q;
            $this->line('  - '.$text);
        }

        $this->line('provisional answer (proceed path):');
        $this->line('  '.wordwrap((string) ($new['provisional_answer'] ?? ''), 100, "\n  ", true));
        if (! empty($new['assumptions'])) {
            $this->line('assumptions: '.implode('; ', $new['assumptions']));
        }
        $this->line("latency    : {$newMs} ms");

        if ($this->option('json')) {
            $this->line('');
            $this->comment('════ RAW AGENTIC JSON ════');
            $this->line((string) json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->line('');

        return self::SUCCESS;
    }
}
