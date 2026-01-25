<?php

$files = [
    'carotid_vertebral' => [
        'guideline_name' => 'Carotid & Vertebral Artery Disease',
        'keywords' => [
            'tier1_core' => ['carotid stenosis', 'carotid artery stenosis', 'Internal Carotid Artery stenosis', 'ICA stenosis', 'symptomatic carotid stenosis', 'asymptomatic carotid stenosis', 'TIA', 'transient ischemic attack', 'stroke', 'cerebrovascular accident', 'CVA', 'amaurosis fugax', 'vertebral artery disease', 'subclavian steal'],
            'tier2_specific' => ['carotid endarterectomy', 'CEA', 'carotid artery stenting', 'CAS', 'carotid stent', 'vertebral artery stenting', 'carotid duplex', 'Peak Systolic Velocity', 'PSV', 'NASCET', 'ECST', 'carotid plaque', 'unstable plaque'],
            'tier3_complications' => ['cranial nerve injury', 'hypoglossal nerve injury', 'hyperperfusion syndrome', 'in-stent restenosis', 'carotid blowout'],
            'tier4_procedures' => ['transcarotid artery revascularization', 'TCAR', 'carotid bypass', 'vertebral transposition']
        ]
    ],
    'clti' => [
        'guideline_name' => 'Chronic Limb-Threatening Ischemia',
        'keywords' => [
            'tier1_core' => ['CLTI', 'chronic limb-threatening ischemia', 'critical limb ischemia', 'CLI', 'rest pain', 'ischemic rest pain', 'tissue loss', 'gangrene', 'non-healing ulcer', 'foot ulcer', 'diabetic foot ulcer'],
            'tier2_specific' => ['WIfI classification', 'Rutherford classification', 'Rutherford 4', 'Rutherford 5', 'Rutherford 6', 'ankle pressure', 'toe pressure', 'TcPO2', 'transcutaneous oxygen', 'limb salvage', 'amputation free survival'],
            'tier3_complications' => ['wound infection', 'osteomyelitis', 'graft failure', 'bypass occlusion'],
            'tier4_procedures' => ['infrainguinal bypass', 'fem-pop bypass', 'fem-distal bypass', 'angioplasty', 'tibial angioplasty', 'pedal arch angioplasty', 'minor amputation', 'major amputation', 'Below Knee Amputation', 'Above Knee Amputation', 'BKA', 'AKA']
        ],
        'exclude_keywords' => ['intermittent claudication', 'asymptomatic PAD']
    ],
    'venous_thrombosis' => [
        'guideline_name' => 'Venous Thrombosis (DVT/PE)',
        'keywords' => [
            'tier1_core' => ['DVT', 'deep vein thrombosis', 'deep venous thrombosis', 'PE', 'pulmonary embolism', 'VTE', 'venous thromboembolism', 'superficial vein thrombosis', 'SVT', 'thrombophlebitis'],
            'tier2_specific' => ['provoked DVT', 'unprovoked DVT', 'cancer-associated thrombosis', 'Wells score', 'D-dimer', 'CTPA', 'V/Q scan', 'compression ultrasound', 'anticoagulation', 'DOAC', 'warfarin', 'heparin', 'LMWH'],
            'tier3_complications' => ['post-thrombotic syndrome', 'PTS', 'CTEPH', 'chronic thromboembolic pulmonary hypertension', 'HIT', 'heparin induced thrombocytopenia'],
            'tier4_procedures' => ['catheter-directed thrombolysis', 'CDT', 'pharmaco-mechanical thrombectomy', 'IVC filter', 'inferior vena cava filter', 'venous stent', 'May-Thurner syndrome']
        ]
    ],
    'chronic_venous_disease' => [
        'guideline_name' => 'Chronic Venous Disease',
        'keywords' => [
            'tier1_core' => ['varicose veins', 'chronic venous insufficiency', 'CVI', 'chronic venous disease', 'CVD', 'venous ulcer', 'leg ulcer', 'venous stasis', 'spider veins', 'telangiectasia', 'reticular veins'],
            'tier2_specific' => ['CEAP classification', 'venous reflux', 'superficial reflux', 'deep reflux', 'perforator reflux', 'great saphenous vein', 'GSV', 'small saphenous vein', 'SSV', 'compression stockings', 'compression therapy'],
            'tier3_complications' => ['lipodermatosclerosis', 'atrophie blanche', 'venous eczema', 'stasis dermatitis', 'bleeding varix'],
            'tier4_procedures' => ['endovenous ablation', 'EVLA', 'RFA', 'sclerotherapy', 'foam sclerotherapy', 'venous stripping', 'phlebectomy', 'venous stent', 'iliac vein stenting']
        ]
    ],
    'descending_thoracic_aorta' => [
        'guideline_name' => 'Descending Thoracic Aorta',
        'keywords' => [
            'tier1_core' => ['descending thoracic aneurysm', 'DTAA', 'thoracic aortic aneurysm', 'type B dissection', 'TBAD', 'type B aortic dissection', 'uncomplicated type B', 'complicated type B', 'penetrating aortic ulcer', 'PAU thoracic', 'intramural hematoma', 'IMH thoracic'],
            'tier2_specific' => ['TEVAR', 'thoracic endovascular aortic repair', 'stent graft', 'aortic remodeling', 'false lumen thrombosis', 'spinal cord ischemia', 'cerebrospinal fluid drain', 'CSF drain', 'lumbar drain'],
            'tier3_complications' => ['retrograde type A dissection', 'RTAD', 'stent graft induced new entry', 'SINE', 'aortoesophageal fistula', 'aortobronchial fistula', 'paraplegia'],
            'tier4_procedures' => ['left subclavian revascularization', 'carotid-subclavian bypass', 'TEVAR landing zone']
        ],
        'exclude_keywords' => ['ascending aorta', 'type A dissection', 'abdominal aortic aneurysm']
    ],
    'aortic_arch' => [
        'guideline_name' => 'Aortic Arch',
        'keywords' => [
            'tier1_core' => ['aortic arch aneurysm', 'arch aneurysm', 'aortic arch dissection', 'isolated arch dissection', 'penetrating aortic ulcer arch', 'PAU arch'],
            'tier2_specific' => ['zone 0', 'zone 1', 'zone 2', 'frozen elephant trunk', 'FET', 'total arch replacement', 'hemiarch replacement', 'Ishimaru zones'],
            'tier3_complications' => ['stroke', 'cerebral embolization', 'recurrent laryngeal nerve injury', 'vocal cord paralysis'],
            'tier4_procedures' => ['hybrid arch repair', 'arch debranching', 'supra-aortic debranching', 'fenestrated arch', 'branched arch', 'chimney graft', 'periscope graft', 'in-situ fenestration']
        ]
    ],
    'mesenteric_renal' => [
        'guideline_name' => 'Mesenteric & Renal Arteries',
        'keywords' => [
            'tier1_core' => ['mesenteric ischemia', 'chronic mesenteric ischemia', 'CMI', 'acute mesenteric ischemia', 'AMI', 'intestinal angina', 'renal artery stenosis', 'RAS', 'renovascular hypertension'],
            'tier2_specific' => ['superior mesenteric artery', 'SMA stenosis', 'celiac artery stenosis', 'celiac axis compression', 'median arcuate ligament syndrome', 'MALS', 'fibromuscular dysplasia', 'FMD', 'visceral aneurysm', 'splenic artery aneurysm', 'renal artery aneurysm'],
            'tier3_complications' => ['bowel infarction', 'short bowel syndrome', 'renal failure', 'kidney atrophy', 'flash pulmonary edema'],
            'tier4_procedures' => ['mesenteric bypass', 'mesenteric stenting', 'renal artery stenting', 'renal bypass', 'nephrectomy', 'visceral artery embolization']
        ]
    ],
    'asymptomatic_pad' => [
        'guideline_name' => 'Asymptomatic PAD & Claudication',
        'keywords' => [
            'tier1_core' => ['peripheral arterial disease', 'PAD', 'intermittent claudication', 'claudication', 'walking pain', 'leg cramps', 'asymptomatic PAD', 'ABI screening'],
            'tier2_specific' => ['ankle-brachial index', 'ABI', 'treadmill test', 'walking distance', 'claudication distance', 'supervised exercise therapy', 'SET', 'best medical therapy', 'risk factor modification', 'cilostazol', 'naftidrofuryl'],
            'tier3_complications' => ['progression to CLTI', 'cardiovascular events', 'MI', 'stroke'],
            'tier4_procedures' => ['revascularization for claudication', 'angioplasty', 'stenting', 'bypass surgery']
        ],
        'exclude_keywords' => ['rest pain', 'tissue loss', 'gangrene', 'CLTI', 'critical limb ischemia']
    ],
    'vascular_access' => [
        'guideline_name' => 'Vascular Access',
        'keywords' => [
            'tier1_core' => ['vascular access', 'hemodialysis access', 'dialysis access', 'arteriovenous fistula', 'AVF', 'arteriovenous graft', 'AVG', 'central venous catheter', 'tunnelled catheter', 'permcath'],
            'tier2_specific' => ['fistula maturation', 'rule of 6s', 'access surveillance', 'access flow', 'fistulogram', 'access stenosis', 'access thrombosis', 'steal syndrome', 'distal hypoperfusion ischemic syndrome', 'DHIS'],
            'tier3_complications' => ['access infection', 'pseudoaneurysm', 'venous hypertension', 'central vein stenosis', 'high output heart failure'],
            'tier4_procedures' => ['fistula creation', 'graft placement', 'fistuloplasty', 'thrombectomy', 'DRIL procedure', 'PAI', 'RUDI', 'miller banding', 'access ligation']
        ]
    ],
    'vascular_trauma' => [
        'guideline_name' => 'Vascular Trauma',
        'keywords' => [
            'tier1_core' => ['vascular trauma', 'arterial injury', 'venous injury', 'gunshot wound', 'GSW', 'stab wound', 'penetrating trauma', 'blunt trauma', 'crush injury', 'road traffic accident', 'motorcycle crash', 'car crash'],
            'tier2_specific' => ['hard signs of vascular injury', 'soft signs', 'pulsatile bleeding', 'expanding hematoma', 'thrill', 'bruit', 'absent pulse', 'shock', 'hypotension', 'hemorrhage control', 'damage control surgery'],
            'tier3_complications' => ['compartment syndrome', 'rhabdomyolysis', 'amputation', 'pseudoaneurysm', 'arteriovenous fistula traumatic'],
            'tier4_procedures' => ['temporary intravascular shunt', 'shunt', 'vessel ligation', 'vein graft', 'fasciotomy', 'REBOA']
        ]
    ],
    'acute_limb_ischaemia' => [
        'guideline_name' => 'Acute Limb Ischaemia',
        'keywords' => [
            'tier1_core' => ['acute limb ischemia', 'ALI', 'sudden leg pain', 'cold leg', 'pulseless leg', 'Rutherford classification ALI', 'category I', 'category IIa', 'category IIb', 'category III'],
            'tier2_specific' => ['embolectomy', 'thrombectomy', 'thrombolysis', 'catheter-directed thrombolysis', 'fasciotomy', 'compartment syndrome', 'reperfusion injury'],
            'tier3_complications' => ['rhabdomyolysis', 'hyperkalemia', 'myoglobinuria', 'acute kidney injury', 'amputation'],
            'tier4_procedures' => ['Fogarty catheter', 'bypass', 'primary amputation']
        ]
    ],
    'vascular_graft_infections' => [
        'guideline_name' => 'Vascular Graft Infections',
        'keywords' => [
            'tier1_core' => ['vascular graft infection', 'VGI', 'aortic graft infection', 'prosthetic graft infection', 'endograft infection', 'infected EVAR', 'infected TEVAR', 'mycotic aneurysm'],
            'tier2_specific' => ['groin infection', 'szilagyi classification', 'samson classification', 'magic criteria', 'PET-CT', 'leukocyte scan', 'perigraft fluid', 'air in graft'],
            'tier3_complications' => ['aorto-enteric fistula', 'AEF', 'graft blowout', 'sepsis', 'septic emboli'],
            'tier4_procedures' => ['graft explantation', 'in-situ reconstruction', 'extra-anatomic bypass', 'cryopreserved homograft', 'rifampicin soaked graft', 'femoral vein harvest', 'muscle flap coverage']
        ]
    ],
    'antithrombotic_therapy' => [
        'guideline_name' => 'Antithrombotic Therapy',
        'keywords' => [
            'tier1_core' => ['antithrombotic therapy', 'antiplatelet therapy', 'anticoagulation', 'vascular prevention', 'cardiovascular risk reduction'],
            'tier2_specific' => ['aspirin', 'clopidogrel', 'DAPT', 'dual antiplatelet therapy', 'SAPT', 'single antiplatelet therapy', 'statin', 'atorvastatin', 'rosuvastatin', 'rivaroxaban', 'compass trial', 'voyager pad'],
            'tier3_complications' => ['bleeding risk', 'major bleeding', 'intracranial hemorrhage', 'gastrointestinal bleeding'],
            'tier4_procedures' => ['perioperative management', 'bridging anticoagulation', 'protamine reversal']
        ]
    ]
];

if (!is_dir('storage/keywords')) {
    mkdir('storage/keywords', 0755, true);
}

foreach ($files as $name => $data) {
    if ($name === 'abdominal_aortic_aneurysm')
        continue; // Already exists

    $content = [
        'guideline_key' => $name,
        'guideline_name' => $data['guideline_name'],
        'version' => date('Y-m-d'),
        'source' => 'Generated Keyword Set',
        'keywords' => $data['keywords'],
        'exclude_keywords' => $data['exclude_keywords'] ?? []
    ];

    file_put_contents("storage/keywords/{$name}.json", json_encode($content, JSON_PRETTY_PRINT));
    echo "Created storage/keywords/{$name}.json\n";
}
