<?php

$markdownPath = __DIR__ . '/../storage/semantic_routing_keywords_all_guidelines.md';
$outputDir = __DIR__ . '/../storage/keywords';

if (!file_exists($markdownPath)) {
    die("Markdown file not found at $markdownPath\n");
}

$content = file_get_contents($markdownPath);

// Define guideline mapping (Markdown Header -> JSON Filename)
// Note: Some markdown headers might be partial matches, so we use logic to map them.
$headerMap = [
    'Abdominal Aorto-Iliac Artery Aneurysms (AAA)' => 'abdominal_aortic_aneurysm',
    'Chronic Venous Disease' => 'chronic_venous_disease',
    'Atherosclerotic Carotid & Vertebral Artery Disease' => 'carotid_vertebral',
    'Asymptomatic PAD & Intermittent Claudication' => 'asymptomatic_pad',
    'Antithrombotic Therapy for Vascular Diseases' => 'antithrombotic_therapy',
    'Acute Limb Ischaemia (ALI)' => 'acute_limb_ischaemia',
    'Descending Thoracic Aorta Diseases' => 'descending_thoracic_aorta',
    'Chronic Limb-Threatening Ischaemia (CLTI)' => 'clti',
    'Mesenteric & Renal Arteries and Veins' => 'mesenteric_renal',
    'Vascular Trauma' => 'vascular_trauma',
    'Vascular Access' => 'vascular_access',
    'VGEI (Graft & Endograft Infections)' => 'vascular_graft_infections',
    'Venous Thrombosis' => 'venous_thrombosis',
    'Aortic Arch Pathologies' => 'aortic_arch'
];

// Helper to clean keywords
function cleanKeyword($k)
{
    return trim(str_replace([';', '•', '- '], '', $k));
}

// Split content by guideline headers
$sections = preg_split('/^## /m', $content);

foreach ($sections as $section) {
    $lines = explode("\n", $section);
    $headerLine = trim($lines[0]);

    $jsonFilename = null;
    $guidelineName = $headerLine;

    foreach ($headerMap as $key => $filename) {
        if (strpos($headerLine, $key) !== false) {
            $jsonFilename = $filename;
            break;
        }
    }

    if (!$jsonFilename)
        continue;

    echo "Processing: $guidelineName -> $jsonFilename\n";

    $keywords = [];
    $currentTier = 'tier1_core'; // Default

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line))
            continue;

        if (strpos($line, '### Tier 1') !== false) {
            $currentTier = 'tier1_core';
        } elseif (strpos($line, '### Tier 2') !== false) {
            $currentTier = 'tier2_specific';
        } elseif (strpos($line, '### Tier 3') !== false) {
            $currentTier = 'tier3_complications';
        } elseif (strpos($line, '### Tier 4') !== false) {
            $currentTier = 'tier4_procedures';
        } elseif (strpos($line, '### Tier 5') !== false) {
            $currentTier = 'tier5_extra';
        } elseif (strpos($line, '### Tier 6') !== false) { // Handle Tier 6/7 as extra
            $currentTier = 'tier6_extra';
        } elseif (strpos($line, '- ') === 0) {
            // It's a keyword line, split by semicolon
            $parts = explode(';', $line);
            foreach ($parts as $part) {
                $k = cleanKeyword($part);
                if (!empty($k)) {
                    $keywords[$currentTier][] = $k;
                }
            }
        }
    }

    // --- INJECT MISSING KEYWORDS (Fixing gaps in Markdown) ---
    if ($jsonFilename === 'vascular_trauma') {
        // Markdown matched "vascular trauma" generic, but missed specific mechanisms
        $mechanisms = ['motorcycle crash', 'road traffic accident', 'car crash', 'gunshot wound', 'stab wound', 'penetrating injury', 'blunt injury'];
        foreach ($mechanisms as $m) {
            if (!in_array($m, $keywords['tier1_core'])) {
                $keywords['tier1_core'][] = $m;
            }
        }
    }

    // --- CLEANUP SPECIFIC FALSE POSITIVES ---

    // LIST OF GENERIC DIAGNOSTICS/TERMS TO REMOVE (unless qualified)
    $generics = ['cta', 'mra', 'dsa', 'dus', 'duplex', 'ultrasound', 'fast', 'reboa', 'txa', 'statins', 'antiplatelet', 'best medical therapy', 'lipid lowering', 'contrast nephropathy', 'kidney function'];

    // Helper to clean array
    $cleanArray = function ($arr) use ($generics) {
        return array_values(array_filter($arr, function ($k) use ($generics) {
            $lower = strtolower($k);
            if (in_array($lower, $generics))
                return false;
            return true;
        }));
    };

    // Clean all tiers
    foreach ($keywords as $tier => $kws) {
        $keywords[$tier] = $cleanArray($kws);
    }

    // FIX Test 1: Trauma matching TEVAR
    if ($jsonFilename === 'vascular_trauma') {
        $keywords['tier1_core'] = array_values(array_filter($keywords['tier1_core'], function ($k) {
            $bad = ['tevar', 'reboa', 'txa', 'bp/hr control', 'lsa coverage/revascularisation', 'hematoma', 'ruptured', 'rupture'];
            return !in_array(strtolower($k), $bad);
        }));
    }

    // FIX Test 2: Carotid matching CTA/generic terms
    if ($jsonFilename === 'carotid_vertebral') {
        // Also remove 'asymptomatic carotid stenosis' if query is specific? No, that's fine.
        // But ensure TIA/Stroke are core.
        // Generics removal above handles CTA, DSA, etc.
    }

    // FIX Test 2: DTA should match Type B stronger
    if ($jsonFilename === 'descending_thoracic_aorta') {
        if (!in_array('type B aortic dissection', $keywords['tier1_core']))
            $keywords['tier1_core'][] = 'type B aortic dissection';
        if (!in_array('TBAD', $keywords['tier1_core']))
            $keywords['tier1_core'][] = 'TBAD';
        if (!in_array('acute type B aortic dissection', $keywords['tier1_core']))
            $keywords['tier1_core'][] = 'acute type B aortic dissection';
    }

    // FIX Test 2: Arch matching Type B (explicit exclusion)
    if ($jsonFilename === 'aortic_arch') {
        $excludeKeywords = ['type B aortic dissection', 'TBAD', 'descending thoracic', 'type B'];
    }

    // FIX Test 3: Trauma exclusions (keep existing logic)
    if ($jsonFilename === 'vascular_trauma') {
        $excludeKeywords = [
            'ruptured AAA',
            'AAA rupture',
            'spontaneous',
            'degenerative',
            'type B dissection',
            'atherosclerotic'
        ];
    } else {
        if ($jsonFilename !== 'aortic_arch' && $jsonFilename !== 'clti' && $jsonFilename !== 'asymptomatic_pad' && $jsonFilename !== 'descending_thoracic_aorta') {
            $excludeKeywords = []; // Reset unless specific guideline has them
        }
    }


    // Add specific exclusions for other guidelines if needed (borrowing from phase 3b/c logic)
    if ($jsonFilename === 'clti') {
        $excludeKeywords = ['intermittent claudication', 'asymptomatic PAD'];
    }
    if ($jsonFilename === 'asymptomatic_pad') {
        $excludeKeywords = ['rest pain', 'tissue loss', 'gangrene', 'CLTI', 'critical limb ischemia'];
    }
    if ($jsonFilename === 'descending_thoracic_aorta') {
        $excludeKeywords = array_merge($excludeKeywords ?? [], ['ascending aorta', 'type A dissection', 'abdominal aortic aneurysm']);
    }

    // Construct JSON data
    $jsonData = [
        'guideline_key' => $jsonFilename,
        'guideline_name' => $guidelineName,
        'version' => date('Y-m-d'),
        'source' => 'semantic_routing_keywords_all_guidelines.md',
        'keywords' => $keywords,
        'exclude_keywords' => $excludeKeywords
    ];

    // Write file
    file_put_contents("$outputDir/$jsonFilename.json", json_encode($jsonData, JSON_PRETTY_PRINT));
    echo "Updated $jsonFilename.json\n";
}

echo "\nDone!\n";
