<?php

return [
    'recommendations_dataset' => 'bc4896bdf5fb11f084fe32d89964721d',

    'categories' => [
        'aortic_arterial_central' => [
            'name' => 'Aortic & Arterial (Central)',
            'guidelines' => [
                'aortic_arch' => [
                    'id' => 'be20b02cdc4311f09021f2381272676b',
                    'name' => 'Aortic Arch',
                    'key_concepts' => ['Zone 0-4 anatomy', 'Frozen Elephant Trunk', 'FET', 'Total Endovascular Arch Repair', 'dissection management', 'arch aneurysm', 'hybrid arch'],
                ],
                'descending_thoracic_aorta' => [
                    'id' => 'fd679d82dc3311f09021f2381272676b',
                    'name' => 'Descending Thoracic Aorta',
                    'key_concepts' => ['Type B Dissection', 'TBAD', 'Intramural Hematoma', 'IMH', 'TEVAR', 'Spinal Cord Ischemia', 'thoracic aneurysm', 'penetrating ulcer'],
                ],
                'abdominal_aortic_aneurysm' => [
                    'id' => '1e8b73dcf49911f09b845ef3771a102d',
                    'name' => 'Abdominal Aortic Aneurysm',
                    'key_concepts' => ['EVAR', 'Open Repair', 'surveillance', '5.0cm', '5.5cm', 'endoleaks', 'AAA', 'rupture', 'abdominal aneurysm'],
                ],
                'mesenteric_renal' => [
                    'id' => 'd94f2a06dc4111f09021f2381272676b',
                    'name' => 'Mesenteric & Renal',
                    'key_concepts' => ['Chronic Mesenteric Ischemia', 'Acute Mesenteric Ischemia', 'CMI', 'AMI', 'Renal Artery Stenosis', 'RAS', 'visceral aneurysms', 'celiac', 'SMA', 'bowel ischemia'],
                ],
            ],
        ],
        'peripheral_carotid' => [
            'name' => 'Peripheral & Carotid',
            'guidelines' => [
                'carotid_vertebral' => [
                    'id' => '29b2a1e84ed111f0b3bb3aabfab5e99c',
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
                    'id' => '7dcce66ef3eb11f0b82c5ef3771a102d',
                    'name' => 'Acute Limb Ischaemia',
                    'key_concepts' => ['6 Ps', 'Rutherford classification', 'thrombolysis', 'embolectomy', 'acute limb', 'ALI', 'pulseless', 'pallor', 'pain', 'paresthesia', 'paralysis', 'poikilothermia'],
                ],
            ],
        ],
        'venous_thrombosis' => [
            'name' => 'Venous & Thrombosis',
            'guidelines' => [
                'antithrombotic_therapy' => [
                    'id' => 'b404c5e0585611f0b053823a24ef0d59',
                    'name' => 'Antithrombotic Therapy',
                    'key_concepts' => ['DOACs', 'warfarin', 'triple therapy', 'cancer-associated thrombosis', 'anticoagulation', 'aspirin', 'clopidogrel', 'dual antiplatelet'],
                ],
                'venous_thrombosis' => [
                    'id' => '7104532adc4311f09021f2381272676b',
                    'name' => 'Venous Thrombosis (DVT/PE)',
                    'key_concepts' => ['DVT', 'PE', 'deep vein thrombosis', 'pulmonary embolism', 'IVC filters', 'PTS', 'post-thrombotic syndrome', 'catheter-directed thrombolysis'],
                ],
                'chronic_venous_disease' => [
                    'id' => 'ecb621444d8f11f09f7a2e382eabde98',
                    'name' => 'Chronic Venous Disease',
                    'key_concepts' => ['Varicose veins', 'CEAP classification', 'ablation', 'sclerotherapy', 'venous ulcer', 'reflux', 'great saphenous vein', 'GSV', 'SSV'],
                ],
            ],
        ],
        'specialty' => [
            'name' => 'Specialty',
            'guidelines' => [
                'vascular_trauma' => [
                    'id' => '8f58aeadec9411f0a38066bc68590b9b',
                    'name' => 'Vascular Trauma',
                    'key_concepts' => ['REBOA', 'mangled extremity', 'MESS', 'hard signs', 'soft signs', 'penetrating trauma', 'blunt trauma', 'vascular injury', 'hemorrhage control'],
                ],
                'vascular_graft_infections' => [
                    'id' => '29981e72dc4311f09021f2381272676b',
                    'name' => 'Vascular Graft Infections',
                    'key_concepts' => ['MAGIC criteria', 'graft excision', 'antibiotic protocols', 'graft infection', 'prosthetic infection', 'aortic graft infection'],
                ],
                'vascular_access' => [
                    'id' => 'bbe0b3a0f39611f08b265ef3771a102d',
                    'name' => 'Vascular Access',
                    'key_concepts' => ['AV fistula', 'AVF', 'dialysis access', 'hemodialysis', 'graft', 'steal syndrome', 'access thrombosis'],
                ],
            ],
        ],
    ],

    'all_guideline_datasets' => [
        'be20b02cdc4311f09021f2381272676b',
        'fd679d82dc3311f09021f2381272676b',
        '1e8b73dcf49911f09b845ef3771a102d',
        'd94f2a06dc4111f09021f2381272676b',
        '29b2a1e84ed111f0b3bb3aabfab5e99c',
        'c7c42f76507211f0b6356a892e29a549',
        'acd1930edc3411f09021f2381272676b',
        '7dcce66ef3eb11f0b82c5ef3771a102d',
        'b404c5e0585611f0b053823a24ef0d59',
        '7104532adc4311f09021f2381272676b',
        'ecb621444d8f11f09f7a2e382eabde98',
        '8f58aeadec9411f0a38066bc68590b9b',
        '29981e72dc4311f09021f2381272676b',
        'bbe0b3a0f39611f08b265ef3771a102d',
    ],
];
