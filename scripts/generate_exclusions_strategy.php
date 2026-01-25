<?php

require __DIR__ . '/../vendor/autoload.php';

// Domain definitions
$domains = [
    'lower_limb' => ['guidelines' => ['asymptomatic_pad', 'clti', 'acute_limb_ischaemia', 'popliteal_aneurysm'], 'keywords' => ['claudication', 'foot', 'leg', 'toe', 'ankle', 'femoral', 'popliteal', 'tibial', 'pedal']],
    'carotid' => ['guidelines' => ['carotid_vertebral'], 'keywords' => ['carotid', 'vertebral', 'stroke', 'amaurosis', 'cerebral', 'neck']],
    'aortic' => ['guidelines' => ['abdominal_aortic_aneurysm', 'descending_thoracic_aorta', 'mesenteric_renal'], 'keywords' => ['aorta', 'aortic', 'abdominal', 'thoracic', 'aneurysm', 'evars', 'tevar']],
    'venous' => ['guidelines' => ['chronic_venous_disease', 'venous_thrombosis'], 'keywords' => ['vein', 'venous', 'dvt', 'varicose', 'reflux', 'thrombosis']],
    'access' => ['guidelines' => ['vascular_access'], 'keywords' => ['fistula', 'graft', 'dialysis', 'access']],
    'trauma' => ['guidelines' => ['vascular_trauma'], 'keywords' => ['trauma', 'injury', 'gunshot', 'stab', 'crash']],
];

// Conflicts (A excludes B)
$conflicts = [
    'lower_limb' => ['carotid', 'venous', 'access'], // PAD shouldn't match Carotid or Veins
    'carotid' => ['lower_limb', 'venous', 'access', 'aortic'], // Carotid is very specific
    'venous' => ['lower_limb', 'carotid', 'aortic'], // Veins !== Arteries
    'aortic' => ['carotid', 'venous'], // Aorta covers legs somewhat (iliac), so don't exclude lower_limb
    'trauma' => ['lower_limb', 'aortic', 'carotid', 'venous'], // PROPOSED: Trauma excludes CHRONIC conditions of these types
];

echo "Generating Exclusion Matrix...\n";

foreach ($domains as $name => $domain) {
    $myGuidelines = $domain['guidelines'];

    // Calculate exclusions
    $excludeKeywords = [];
    foreach ($conflicts[$name] ?? [] as $enemyDomainName) {
        $enemyKeywords = $domains[$enemyDomainName]['keywords'];
        $excludeKeywords = array_merge($excludeKeywords, $enemyKeywords);
    }
    $excludeKeywords = array_unique($excludeKeywords);

    echo "\nDomain: " . strtoupper($name) . "\n";
    echo "  Applied to: " . implode(', ', $myGuidelines) . "\n";
    echo "  Excludes: " . implode(', ', $excludeKeywords) . "\n";

    // In a real script, we would now load the JSON files for $myGuidelines
    // and inject these keys into "exclude_keywords".
}

echo "\n\nThis script is a PROTOTYPE. To apply these rules, we would iterate through storage/keywords/*.json and update them.\n";
echo "Recommended Action: Create a 'DomainMap' config and run a command to enforce strict separations.\n";
