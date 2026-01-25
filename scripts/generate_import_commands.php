<?php

$files = [
    'aorto_iliac_aneurysms.md' => 'abdominal_aortic_aneurysm',
    'ALI.md' => 'acute_limb_ischaemia',
    'antithrombotic.md' => 'antithrombotic_therapy',
    'aortic_arch.md' => 'aortic_arch',
    'PAD.md' => 'asymptomatic_pad',
    'carotid.md' => 'carotid_vertebral',
    'venous_disease.md' => 'chronic_venous_disease',
    'CLTI.md' => 'clti',
    'descending thoracic.md' => 'descending_thoracic',
    'mesenteric.md' => 'mesenteric_renal',
    'vascular_access.md' => 'vascular_access',
    'graft_infections.md' => 'graft_infections',
    'infections.md' => 'graft_infections', // Secondary map? Or maybe duplicate content?
    'vascular_trauma.md' => 'vascular_trauma',
    // 'venous_thrombosis.md' is missing from the file list I saw? 
    // Wait, let me check the list again. "venous_disease.md" is there. "venous_thrombosis" is missing?
    // Let's assume one file might cover both or I missed it.
    // Re-checking list: "venous_disease.md" (14KB) likely covers CVI and DVT? 
    // Or maybe "infections.md" is separate?
];

// Re-verify list from previous step:
// ALI.md
// CLTI.md
// PAD.md
// antithrombotic.md
// aortic_arch.md
// aorto_iliac_aneurysms.md
// carotid.md
// descending thoracic.md
// graft_infections.md
// infections.md
// mesenteric.md
// vascular_access.md
// vascular_trauma.md
// venous_disease.md

// Missing: venous_thrombosis? 
// Maybe "venous_disease.md" is meant for both? Or maybe "infections.md" is VGEI and "graft_infections.md" is duplicates?
// I will map what I see.

$map = [
    'aorto_iliac_aneurysms.md' => 'abdominal_aortic_aneurysm',
    'ALI.md' => 'acute_limb_ischaemia',
    'antithrombotic.md' => 'antithrombotic_therapy',
    'aortic_arch.md' => 'aortic_arch',
    'PAD.md' => 'asymptomatic_pad',
    'carotid.md' => 'carotid_vertebral',
    'venous_disease.md' => 'chronic_venous_disease',
    'CLTI.md' => 'clti',
    'descending thoracic.md' => 'descending_thoracic',
    'mesenteric.md' => 'mesenteric_renal',
    'vascular_access.md' => 'vascular_access',
    'graft_infections.md' => 'graft_infections',
    'vascular_trauma.md' => 'vascular_trauma',
    // What about venous_thrombosis? 
    // Let's assume venous_disease.md might be for chronic.
    // Maybe user forgot DVT? Or maybe it's inside one of them.
];

// Command generator
echo "# Batch Import Script\n";
echo "cd /var/www/laravel-ragflow\n\n";

foreach ($map as $file => $guideline) {
    if (!isset($guideline))
        continue;

    // Handle spaces in filenames
    $cleanFile = str_replace(' ', '\ ', $file);

    echo "echo \"Importing $file...\"\n";
    echo "php artisan guidelines:import-abbr \"storage/abbreviations/$cleanFile\" --guideline=$guideline --format=md\n";
}

// Special case for venous_thrombosis if missing
echo "\n# Note: 'venous_thrombosis' not explicitly found. Checking 'venous_disease.md' content might be needed.\n";
