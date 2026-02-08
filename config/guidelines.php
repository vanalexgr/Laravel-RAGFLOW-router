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
                    'recs_doc_id' => '5c02a7e7ff6011f0829532d89964721d',  // Thoracic Aortic Pathologies Involving the Aortic Arch
                    'key_concepts' => ['Zone 0-4 anatomy', 'Frozen Elephant Trunk', 'FET', 'Total Endovascular Arch Repair', 'dissection management', 'arch aneurysm', 'hybrid arch'],
                ],
                'descending_thoracic_aorta' => [
                    'id' => '28c643e104de11f1966232d89964721d',
                    'name' => 'Descending Thoracic & Thoracoabdominal Aortic Aneurysms',
                    'recs_doc_id' => 'a6b1efd204dd11f1992e32d89964721d',
                    'key_concepts' => ['Type B Dissection', 'TBAD', 'Intramural Hematoma', 'IMH', 'TEVAR', 'Spinal Cord Ischemia', 'thoracic aneurysm', 'penetrating ulcer', 'thoracoabdominal aneurysm', 'TAAA', 'Crawford classification'],
                ],
                'abdominal_aortic_aneurysm' => [
                    'id' => '7fb152c6ffbd11f0b2af32d89964721d',
                    'name' => 'Abdominal Aortic Aneurysm',
                    'recs_doc_id' => '40a8b701ff8111f080ad32d89964721d',  // ESVS_2024_AAA
                    'key_concepts' => ['EVAR', 'Open Repair', 'surveillance', '5.0cm', '5.5cm', 'endoleaks', 'AAA', 'rupture', 'abdominal aneurysm'],
                ],
                'mesenteric_renal' => [
                    'id' => '681e339bffa311f08abc32d89964721d',
                    'name' => 'Mesenteric & Renal',
                    'recs_doc_id' => '5d49bb90ff5a11f0bda432d89964721d',  // Mesenteric and Renal Arteries
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
                    'recs_doc_id' => '4f5cce1cffbd11f0b3e232d89964721d',  // Management of Atherosclerotic Carotid and Vertebral Artery Disease
                    'key_concepts' => ['Stroke', 'TIA', 'CEA', 'CAS', 'TCAR', 'symptomatic stenosis', 'asymptomatic stenosis', 'carotid endarterectomy', 'carotid stenting', 'vertebral artery'],
                ],
                'asymptomatic_pad' => [
                    'id' => 'c7c42f76507211f0b6356a892e29a549',
                    'name' => 'Asymptomatic PAD',
                    'recs_doc_id' => '6e360863f89811f0bb3c32d89964721d',  // Asymptomatic Lower Limb Peripheral Arterial Disease and Intermittent Claudication
                    'key_concepts' => ['peripheral arterial disease', 'PAD', 'LEAD', 'lower extremity arterial disease', 'Supervised Exercise Therapy', 'SET', 'risk factor optimization', 'claudication', 'intermittent claudication', 'walking distance', 'ABI screening', 'ankle brachial index', 'asymptomatic PAD'],
                ],
                'clti' => [
                    'id' => 'acd1930edc3411f09021f2381272676b',
                    'name' => 'Chronic Limb-Threatening Ischemia',
                    'recs_doc_id' => '31f83c34052911f18ceb32d89964721d',
                    'key_concepts' => ['WIfI classification', 'angiosome', 'heel ulcer', 'tissue loss', 'rest pain', 'gangrene', 'limb salvage', 'critical limb ischemia', 'CLI', 'CLTI'],
                ],
                'acute_limb_ischaemia' => [
                    'id' => '9eeed489ff9d11f0b82f32d89964721d',
                    'name' => 'Acute Limb Ischaemia',
                    'recs_doc_id' => '0fb7a35eff9711f08d5232d89964721d',  // ESVS_2020_ALI
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
                    'recs_doc_id' => '40795f9affad11f0a4d332d89964721d',  // Antithrombotic Therapy for Vascular Diseases
                    'key_concepts' => ['DOACs', 'warfarin', 'triple therapy', 'cancer-associated thrombosis', 'anticoagulation', 'aspirin', 'clopidogrel', 'dual antiplatelet'],
                ],
                'venous_thrombosis' => [
                    'id' => '7104532adc4311f09021f2381272676b',
                    'name' => 'Venous Thrombosis (DVT/PE)',
                    'recs_doc_id' => 'NEED_VENOUS_THROMBOSIS_DOC_ID',
                    'key_concepts' => ['DVT', 'PE', 'deep vein thrombosis', 'pulmonary embolism', 'IVC filters', 'PTS', 'post-thrombotic syndrome', 'catheter-directed thrombolysis'],
                ],
                'chronic_venous_disease' => [
                    'id' => 'ec53f8c1ff9811f0a09132d89964721d',
                    'name' => 'Chronic Venous Disease',
                    'recs_doc_id' => 'd0eb8a25ff9211f096f132d89964721d',  // Chronic Venous Disease of the Lower Limbs
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
                    'recs_doc_id' => '3bc100f9007f11f1b97432d89964721d',  // Management of Vascular Trauma
                    'key_concepts' => ['REBOA', 'mangled extremity', 'MESS', 'hard signs', 'soft signs', 'penetrating trauma', 'blunt trauma', 'vascular injury', 'hemorrhage control'],
                ],
                'vascular_graft_infections' => [
                    'id' => '29981e72dc4311f09021f2381272676b',
                    'name' => 'Vascular Graft Infections',
                    'recs_doc_id' => '4fec29b2ff8211f0a98232d89964721d',  // Vascular Graft and Endograft Infection
                    'key_concepts' => ['MAGIC criteria', 'graft excision', 'antibiotic protocols', 'graft infection', 'prosthetic infection', 'aortic graft infection'],
                ],
                'vascular_access' => [
                    'id' => '079b4aea008311f1b45632d89964721d',
                    'name' => 'Vascular Access',
                    'recs_doc_id' => 'dbaf171a008c11f1aeff32d89964721d',  // Vascular Access
                    'key_concepts' => ['AV fistula', 'AVF', 'dialysis access', 'hemodialysis', 'graft', 'steal syndrome', 'access thrombosis'],
                ],
            ],
        ],
    ],

    'all_guideline_datasets' => [
        '5b51acbfffa411f0905532d89964721d',
        '28c643e104de11f1966232d89964721d',
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
