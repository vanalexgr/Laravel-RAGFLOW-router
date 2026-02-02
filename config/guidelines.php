<?php

return [
    'recommendations_dataset' => 'bc4896bdf5fb11f084fe32d89964721d',

    'categories' => [
        'aortic_arterial_central' => [
            'name' => 'Aortic & Arterial (Central)',
            'guidelines' => [
                'aortic_arch' => [
                    'id' => '5b51acbfffa411f0905532d89964721d',
                    'name' => 'Aortic Arch',
                    'key_concepts' => ['Zone 0-4 anatomy', 'Frozen Elephant Trunk', 'FET', 'Total Endovascular Arch Repair', 'dissection management', 'arch aneurysm', 'hybrid arch'],
                ],
                'descending_thoracic_aorta' => [
                    'id' => 'fd679d82dc3311f09021f2381272676b',
                    'name' => 'Descending Thoracic Aorta',
                    'key_concepts' => ['Type B Dissection', 'TBAD', 'Intramural Hematoma', 'IMH', 'TEVAR', 'Spinal Cord Ischemia', 'thoracic aneurysm', 'penetrating ulcer'],
                ],
                'abdominal_aortic_aneurysm' => [
                    'id' => '7fb152c6ffbd11f0b2af32d89964721d',
                    'name' => 'Abdominal Aortic Aneurysm',
                    'key_concepts' => ['EVAR', 'Open Repair', 'surveillance', '5.0cm', '5.5cm', 'endoleaks', 'AAA', 'rupture', 'abdominal aneurysm'],
                ],
                'mesenteric_renal' => [
                    'id' => '681e339bffa311f08abc32d89964721d',
                    'name' => 'Mesenteric & Renal',
                    'key_concepts' => ['Chronic Mesenteric Ischemia', 'Acute Mesenteric Ischemia', 'CMI', 'AMI', 'Renal Artery Stenosis', 'RAS', 'visceral aneurysms', 'celiac', 'SMA', 'bowel ischemia'],
                ],
            ],
        ],
        'peripheral_carotid' => [
            'name' => 'Peripheral & Carotid',
            'guidelines' => [
                'carotid_vertebral' => [
                    'id' => '87c72055ffbe11f095ef32d89964721d',
                    'name' => 'Carotid & Vertebral',
                    'key_concepts' => ['Stroke', 'TIA', 'CEA', 'CAS', 'TCAR', 'symptomatic stenosis', 'asymptomatic stenosis', 'carotid endarterectomy', 'carotid stenting', 'vertebral artery'],
                ],
                'asymptomatic_pad' => [
                    'id' => 'c7c42f76507211f0b6356a892e29a549',
                    'name' => 'Asymptomatic PAD',
                    'key_concepts' => ['peripheral arterial disease', 'PAD', 'LEAD', 'lower extremity arterial disease', 'Supervised Exercise Therapy', 'SET', 'risk factor optimization', 'claudication', 'intermittent claudication', 'walking distance', 'ABI screening', 'ankle brachial index', 'asymptomatic PAD'],
                ],
                'clti' => [
                    'id' => 'acd1930edc3411f09021f2381272676b',
                    'name' => 'Chronic Limb-Threatening Ischemia',
                    'key_concepts' => ['WIfI classification', 'angiosome', 'heel ulcer', 'tissue loss', 'rest pain', 'gangrene', 'limb salvage', 'critical limb ischemia', 'CLI', 'CLTI'],
                ],
                'acute_limb_ischaemia' => [
                    'id' => '9eeed489ff9d11f0b82f32d89964721d',
                    'name' => 'Acute Limb Ischaemia',
                    'key_concepts' => ['6 Ps', 'Rutherford classification', 'thrombolysis', 'embolectomy', 'acute limb', 'ALI', 'pulseless', 'pallor', 'pain', 'paresthesia', 'paralysis', 'poikilothermia'],
                ],
            ],
        ],
        'venous_thrombosis' => [
            'name' => 'Venous & Thrombosis',
            'guidelines' => [
                'antithrombotic_therapy' => [
                    'id' => 'b6b02fdaffad11f0885f32d89964721d',
                    'name' => 'Antithrombotic Therapy',
                    'key_concepts' => ['DOACs', 'warfarin', 'triple therapy', 'cancer-associated thrombosis', 'anticoagulation', 'aspirin', 'clopidogrel', 'dual antiplatelet'],
                ],
                'venous_thrombosis' => [
                    'id' => '7104532adc4311f09021f2381272676b',
                    'name' => 'Venous Thrombosis (DVT/PE)',
                    'key_concepts' => ['DVT', 'PE', 'deep vein thrombosis', 'pulmonary embolism', 'IVC filters', 'PTS', 'post-thrombotic syndrome', 'catheter-directed thrombolysis'],
                ],
                'chronic_venous_disease' => [
                    'id' => 'ec53f8c1ff9811f0a09132d89964721d',
                    'name' => 'Chronic Venous Disease',
                    'key_concepts' => ['Varicose veins', 'CEAP classification', 'ablation', 'sclerotherapy', 'venous ulcer', 'reflux', 'great saphenous vein', 'GSV', 'SSV'],
                ],
            ],
        ],
        'specialty' => [
            'name' => 'Specialty',
            'guidelines' => [
                'vascular_trauma' => [
                    'id' => '94269d17007f11f1b59a32d89964721d',
                    'name' => 'Vascular Trauma',
                    'key_concepts' => ['REBOA', 'mangled extremity', 'MESS', 'hard signs', 'soft signs', 'penetrating trauma', 'blunt trauma', 'vascular injury', 'hemorrhage control'],
                ],
                'vascular_graft_infections' => [
                    'id' => '29981e72dc4311f09021f2381272676b',
                    'name' => 'Vascular Graft Infections',
                    'key_concepts' => ['MAGIC criteria', 'graft excision', 'antibiotic protocols', 'graft infection', 'prosthetic infection', 'aortic graft infection'],
                ],
                'vascular_access' => [
                    'id' => '079b4aea008311f1b45632d89964721d',
                    'name' => 'Vascular Access',
                    'key_concepts' => ['AV fistula', 'AVF', 'dialysis access', 'hemodialysis', 'graft', 'steal syndrome', 'access thrombosis'],
                ],
            ],
        ],
    ],

    'all_guideline_datasets' => [
        '5b51acbfffa411f0905532d89964721d',
        'fd679d82dc3311f09021f2381272676b',
        '7fb152c6ffbd11f0b2af32d89964721d',
        '681e339bffa311f08abc32d89964721d',
        '87c72055ffbe11f095ef32d89964721d',
        'c7c42f76507211f0b6356a892e29a549',
        'acd1930edc3411f09021f2381272676b',
        '9eeed489ff9d11f0b82f32d89964721d',
        'b6b02fdaffad11f0885f32d89964721d',
        '7104532adc4311f09021f2381272676b',
        'ec53f8c1ff9811f0a09132d89964721d',
        '94269d17007f11f1b59a32d89964721d',
        '29981e72dc4311f09021f2381272676b',
        '079b4aea008311f1b45632d89964721d',
    ],
];
