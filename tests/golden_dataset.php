<?php

return [
    // --- BATCH 1: THE ORIGINAL STRESS TEST 10 (Proven Hard Cases) ---
    [
        'query' => 'management of graft infection after TEVAR',
        'expected' => 'vascular_graft_infections',
        'desc' => 'Post-TEVAR Infection (Target: VGEI, Avoid: DTA)'
    ],
    [
        'query' => 'type B aortic dissection with visceral malperfusion',
        'expected' => 'descending_thoracic_aorta',
        'desc' => 'Type B + Complication (Target: DTA, Avoid: Arch)'
    ],
    [
        'query' => 'ruptured abdominal aortic aneurysm management',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'Ruptured AAA (Target: AAA, Avoid: Trauma)'
    ],
    [
        'query' => 'motorcycle crash with acute limb ischaemia',
        'expected' => 'vascular_trauma',
        'desc' => 'Trauma + ALI (Target: Trauma, Avoid: ALI)'
    ],
    [
        'query' => 'acute limb ischaemia classification and treatment',
        'expected' => 'acute_limb_ischaemia',
        'desc' => 'ALI Standard (Target: ALI)'
    ],
    [
        'query' => 'CLTI vs intermittent claudication diagnosis',
        'expected' => 'clti',
        'desc' => 'CLTI vs Claudication (Target: CLTI)'
    ],
    [
        'query' => 'medical therapy for intermittent claudication',
        'expected' => 'asymptomatic_pad',
        'desc' => 'Claudication Therapy (Target: Asymptomatic PAD)'
    ],
    [
        'query' => 'antithrombotic therapy for carotid stenosis',
        'expected' => 'carotid_vertebral',
        'desc' => 'Carotid + Drugs (Target: Carotid)'
    ],
    [
        'query' => 'chronic venous disease and DVT management',
        'expected' => 'venous_thrombosis',
        'desc' => 'DVT + Chronic (Target: Venous Thrombosis)'
    ],
    [
        'query' => 'infected dialysis access fistula',
        'expected' => 'vascular_access',
        'desc' => 'Access Infection (Target: Access, Avoid: VGEI)'
    ],

    // --- BATCH 2: ACRONYM HEAVY (Phase 5 Verification) ---
    [
        'query' => 'management of sTBAD',
        'expected' => 'descending_thoracic_aorta',
        'desc' => 'sTBAD -> Symptomatic Type B (Target: DTA)'
    ],
    [
        'query' => 'EVLA for GSV reflux',
        'expected' => 'chronic_venous_disease',
        'desc' => 'EVLA/GSV -> Varicose Veins (Target: CVD)'
    ],
    [
        'query' => 'CAS vs CEA for symptomatic carotid stenosis',
        'expected' => 'carotid_vertebral',
        'desc' => 'CAS/CEA Acronyms (Target: Carotid)'
    ],
    [
        'query' => 'rAAA emergency repair guidelines',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'rAAA -> Ruptured AAA (Target: AAA)'
    ],
    [
        'query' => 'DOAC therapy for VTE',
        'expected' => 'venous_thrombosis',
        'desc' => 'DOAC/VTE (Target: Venous Thrombosis)'
    ],
    [
        'query' => 'BEVAR for juxtarenal aneurysm',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'BEVAR -> Branched EVAR (Target: AAA)'
    ],
    [
        'query' => 'FEVAR for pararenal AAA',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'FEVAR -> Fenestrated EVAR (Target: AAA)'
    ],
    [
        'query' => 'medical management of uTBAD',
        'expected' => 'descending_thoracic_aorta',
        'desc' => 'uTBAD -> Uncomplicated Type B (Target: DTA)'
    ],
    [
        'query' => 'IVUS during EVAR',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'IVUS/EVAR (Target: AAA)'
    ],
    [
        'query' => 'TAAA open repair complications',
        'expected' => 'descending_thoracic_aorta',
        'desc' => 'TAAA -> Thoracoabdominal (Target: DTA)'
    ],

    // --- BATCH 3: TRAUMA VS ELECTIVE BOUNDARIES ---
    [
        'query' => 'blunt thoracic aortic injury management',
        'expected' => 'vascular_trauma',
        'desc' => 'BTAI (Target: Trauma, Avoid: DTA)'
    ],
    [
        'query' => 'gunshot wound to the femoral artery',
        'expected' => 'vascular_trauma',
        'desc' => 'GSW Femoral (Target: Trauma, Avoid: PAD)'
    ],
    [
        'query' => 'traumatic carotid dissection',
        'expected' => 'vascular_trauma',
        'desc' => 'Traumatic Carotid (Target: Trauma, Avoid: Carotid)'
    ],
    [
        'query' => 'popliteal artery entrapment syndrome',
        'expected' => 'asymptomatic_pad',
        'desc' => 'Non-trauma pathology (Target: PAD, Avoid: Trauma)'
    ],
    [
        'query' => 'compartment syndrome after tibial fracture',
        'expected' => 'vascular_trauma',
        'desc' => 'Orthopaedic Trauma (Target: Trauma)'
    ],
    [
        'query' => 'iatrogenic femoral artery pseudoaneurysm',
        'expected' => 'vascular_trauma',
        'desc' => 'Iatrogenic Trauma (Target: Trauma)'
    ],

    // --- BATCH 4: INFECTION & COMPLEX CONCEPTS ---
    [
        'query' => 'aorto-enteric fistula diagnosis',
        'expected' => 'vascular_graft_infections',
        'desc' => 'AE Fistula (Target: VGEI)'
    ],
    [
        'query' => 'mycotic aortic aneurysm',
        'expected' => 'vascular_graft_infections',
        'desc' => 'Mycotic/Infected Aneurysm (Target: VGEI)'
    ],
    [
        'query' => 'central venous catheter infection',
        'expected' => 'vascular_access',
        'desc' => 'CVC Infection (Target: Access, Avoid: VGEI)'
    ],
    [
        'query' => 'prosthetic graft infection in the groin',
        'expected' => 'vascular_graft_infections',
        'desc' => 'Groin Graft Infection (Target: VGEI)'
    ],

    // --- BATCH 5: AORTIC ZONES & ARCH ---
    [
        'query' => 'aneurysm involving the subclavian artery',
        'expected' => 'aortic_arch',
        'desc' => 'Subclavian/Arch (Target: Arch)'
    ],
    [
        'query' => 'zone 2 TEVAR landing zone',
        'expected' => 'aortic_arch',
        'desc' => 'Zone 2 -> Arch involvement (Target: Arch)'
    ],
    [
        'query' => 'frozen elephant trunk procedure',
        'expected' => 'aortic_arch',
        'desc' => 'FET Procedure (Target: Arch)'
    ],
    [
        'query' => 'type A aortic dissection',
        'expected' => 'aortic_arch', // Actually, usually Type A is Arch/Ascending.
        'desc' => 'Type A (Target: Arch)'
    ],
    [
        'query' => 'retrograde type A dissection',
        'expected' => 'aortic_arch',
        'desc' => 'Retrograde Type A (Target: Arch)'
    ],

    // --- BATCH 6: MESENTERIC & RENAL ---
    [
        'query' => 'chronic mesenteric ischaemia symptoms',
        'expected' => 'mesenteric_renal',
        'desc' => 'CMI (Target: Mesenteric)'
    ],
    [
        'query' => 'renal artery stenosis hypertension',
        'expected' => 'mesenteric_renal',
        'desc' => 'RAS (Target: Mesenteric)'
    ],
    [
        'query' => 'acute mesenteric ischaemia diagnosis',
        'expected' => 'mesenteric_renal',
        'desc' => 'AMI (Target: Mesenteric)'
    ],

    // --- BATCH 7: VENOUS & LYMPHATIC ---
    [
        'query' => 'superficial vein thrombosis treatment',
        'expected' => 'venous_thrombosis',
        'desc' => 'SVT (Target: Venous Thrombosis)'
    ],
    [
        'query' => 'compression therapy for venous ulcers',
        'expected' => 'chronic_venous_disease',
        'desc' => 'Venous Ulcers (Target: CVD)'
    ],
    [
        'query' => 'may-thurner syndrome management',
        'expected' => 'chronic_venous_disease', // Or thrombosis? Usually CVD/DVT overlap.
        'desc' => 'May-Thurner (Target: CVD/Thrombosis)'
    ],
    [
        'query' => 'pelvic congestion syndrome',
        'expected' => 'chronic_venous_disease',
        'desc' => 'Pelvic Venous (Target: CVD)'
    ],
];
