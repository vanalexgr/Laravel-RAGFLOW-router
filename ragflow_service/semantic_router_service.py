import os
import logging
from typing import Optional
from semantic_router import Route
from semantic_router.routers import SemanticRouter
from semantic_router.encoders import FastEmbedEncoder

logger = logging.getLogger("semantic_router_service")

GUIDELINES_CONFIG = {
    "aortic_arch": {
        "id": "5b51acbfffa411f0905532d89964721d",
        "name": "Aortic Arch",
        "utterances": [
            "Aortic arch aneurysm",
            "Aortic arch dissection",
            "Type A dissection",
            "Type B dissection arch involvement",
            "Intramural hematoma",
            "Penetrating atherosclerotic ulcer PAU",
            "Kommerell diverticulum",
            "Aberrant subclavian artery",
            "Bovine arch anatomy",
            "Arch zone 0",
            "Arch zone 1",
            "Arch zone 2",
            "Arch zone 3",
            "Supra-aortic trunk debranching",
            "Carotid-subclavian bypass",
            "Carotid-subclavian transposition",
            "Total arch replacement",
            "Frozen elephant trunk FET",
            "Hybrid arch repair",
            "Thoracic endovascular aortic repair TEVAR",
            "Branched arch endograft",
            "Fenestrated arch endograft",
            "Chimney periscope graft",
            "In situ fenestration",
            "Left subclavian artery LSA coverage",
            "LSA revascularization",
            "Selective antegrade cerebral perfusion",
            "Deep hypothermic circulatory arrest DHCA",
            "Cerebral oximetry NIRS",
            "Spinal cord ischemia prevention",
            "Stroke risk arch",
            "Recurrent laryngeal nerve injury",
            "Endoleak type I",
            "Endoleak type II",
            "Endoleak type III",
            "Retrograde Type A dissection",
            "Bird-beak configuration",
            "Landing zone adequacy",
            "Proximal seal length",
            "Computed tomography angiography CTA",
            "Magnetic resonance angiography MRA",
            "Transesophageal echocardiography TEE",
            "Intravascular ultrasound IVUS",
            "Aortic arch atheroma",
            "Embolic protection device",
            "Access vessel evaluation",
            "Iliac conduit",
            "Post-implant surveillance",
            "Supra-aortic vessel stenosis",
            "Aortic mural thrombus arch",
        ],
    },
    "descending_thoracic_aorta": {
        "id": "fd679d82dc3311f09021f2381272676b",
        "name": "Descending Thoracic Aorta",
        "utterances": [
            "Descending thoracic aortic aneurysm DTAA",
            "Thoracic aortic dissection Stanford B",
            "Complicated Type B dissection",
            "Uncomplicated Type B dissection",
            "Malperfusion syndrome",
            "Rapid aortic expansion",
            "Rupture risk",
            "Aortic rupture",
            "Thoracic intramural hematoma",
            "Penetrating atherosclerotic ulcer thoracic",
            "Thoracic pseudoaneurysm",
            "Traumatic transection isthmus",
            "TEVAR",
            "Open thoracic aortic repair",
            "Left thoracotomy",
            "Aortic cross-clamping",
            "Cerebrospinal fluid CSF drainage",
            "Spinal cord ischemia",
            "Left heart bypass",
            "Distal perfusion",
            "Endograft sizing",
            "Oversizing percentage",
            "Landing zone length",
            "Distal seal zone",
            "Proximal seal zone",
            "Endograft migration",
            "Type I endoleak",
            "Type II endoleak",
            "Type III endoleak",
            "False lumen thrombosis",
            "True lumen expansion",
            "Entry tear coverage",
            "PETTICOAT technique",
            "Bare-metal dissection stent",
            "STABILISE technique",
            "Branch vessel stenting",
            "Visceral malperfusion",
            "Renal malperfusion",
            "Lower limb malperfusion",
            "CTA surveillance",
            "Aortic diameter threshold",
            "Growth rate mm year",
            "Blood pressure control",
            "Anti-impulse therapy beta-blocker",
            "Pain refractory to therapy",
            "Refractory hypertension",
            "Access complications",
            "Iliac artery tortuosity",
            "Retrograde Type A dissection",
            "End-organ ischemia",
        ],
    },
    "abdominal_aortic_aneurysm": {
        "id": "7fb152c6ffbd11f0b2af32d89964721d",
        "name": "Abdominal Aortic Aneurysm",
        "utterances": [
            "Abdominal aortic aneurysm AAA",
            "Infrarenal AAA",
            "Juxtarenal AAA",
            "Pararenal AAA",
            "Suprarenal AAA",
            "Mycotic aortic aneurysm",
            "Inflammatory AAA",
            "Ruptured AAA rAAA",
            "Symptomatic AAA",
            "Aneurysm diameter threshold",
            "Growth rate cm year",
            "Aneurysm sac expansion",
            "Saccular aneurysm",
            "Aorto-iliac aneurysm",
            "Common iliac artery aneurysm",
            "Endovascular aneurysm repair EVAR",
            "Open surgical repair OSR",
            "Fenestrated EVAR fEVAR",
            "Branched EVAR bEVAR",
            "Chimney EVAR ChEVAR",
            "Iliac branch device IBD",
            "Endoanchors",
            "Aortic neck length",
            "Neck angulation",
            "Neck diameter",
            "Hostile neck",
            "Proximal sealing zone",
            "Distal landing zone",
            "Access vessel diameter",
            "Percutaneous EVAR pEVAR",
            "Endoleak type I",
            "Endoleak type II",
            "Endoleak type III",
            "Endotension type V",
            "Graft migration",
            "Limb occlusion",
            "Graft kinking",
            "Surveillance imaging protocol",
            "CTA follow-up",
            "Duplex ultrasound follow-up",
            "Contrast-induced nephropathy",
            "Renal function preservation",
            "Perioperative cardiac risk",
            "Anaesthetic strategy",
            "Aortic clamp position",
            "Aorto-bi-iliac graft",
            "Aorto-bifemoral graft",
            "Laparotomy",
            "Retroperitoneal approach",
        ],
    },
    "mesenteric_renal": {
        "id": "681e339bffa311f08abc32d89964721d",
        "name": "Mesenteric & Renal",
        "utterances": [
            "Coeliac artery coeliac trunk CA",
            "Superior mesenteric artery SMA",
            "Inferior mesenteric artery IMA",
            "Splenic artery",
            "Hepatic artery common proper hepatic",
            "Left gastric artery",
            "Gastroduodenal artery GDA",
            "Pancreaticoduodenal arteries superior inferior",
            "Pancreaticoduodenal arcades Rio Branco arcade",
            "Arc of Bühler",
            "Marginal artery of Drummond",
            "Arc of Riolan",
            "Villemin arcade",
            "Griffith's point watershed splenic flexure",
            "Sudeck's point rectosigmoid watershed",
            "Portal vein PV",
            "Splenic vein SV",
            "Superior mesenteric vein SMV",
            "Inferior mesenteric vein IMV",
            "Portomesenteric venous confluence",
            "Renal artery main renal artery",
            "Accessory renal artery",
            "Renal artery ostium",
            "Left renal vein LRV",
            "Median arcuate ligament MAL aorto-SMA angle",
            "Mesenteric artery occlusive disease MAOD",
            "Chronic mesenteric ischaemia CMI",
            "Acute mesenteric ischaemia AMI",
            "Acute-on-chronic mesenteric ischaemia",
            "Embolic SMA occlusion",
            "Thrombotic SMA occlusion atherosclerotic ostial disease",
            "Non-occlusive mesenteric ischaemia NOMI",
            "Mesenteric venous thrombosis MVT splanchnic vein thrombosis",
            "Median arcuate ligament syndrome MALS coeliac compression",
            "Visceral artery aneurysm VAA",
            "Splenic artery aneurysm SAA pregnancy risk",
            "Hepatic artery aneurysm HAA",
            "Coeliac artery aneurysm",
            "Pancreaticoduodenal artery aneurysm",
            "Superior mesenteric artery aneurysm",
            "Renal artery aneurysm RAA",
            "Mycotic visceral artery aneurysm",
            "Isolated dissection of SMA or coeliac artery",
            "Renal artery dissection",
            "Renal artery stenosis RAS atherosclerotic",
            "Renal artery stenosis fibromuscular dysplasia FMD",
            "Resistant hypertension renovascular",
            "Flash pulmonary oedema RAS-related",
            "Nutcracker syndrome NCS nutcracker phenomenon",
            "CTA arterial venous phases NOMI signs pneumatosis portal venous gas",
        ],
    },
    "carotid_vertebral": {
        "id": "87c72055ffbe11f095ef32d89964721d",
        "name": "Carotid & Vertebral",
        "utterances": [
            "Carotid artery stenosis",
            "Symptomatic carotid stenosis",
            "Asymptomatic carotid stenosis",
            "Transient ischemic attack TIA",
            "Ischemic stroke",
            "Amaurosis fugax",
            "Plaque vulnerability",
            "Intraplaque hemorrhage",
            "Ulcerated plaque",
            "Near-occlusion",
            "Tandem lesions",
            "Carotid endarterectomy CEA",
            "Carotid artery stenting CAS",
            "Transcarotid artery revascularization TCAR",
            "Eversion endarterectomy",
            "Patch angioplasty",
            "Shunt during CEA",
            "Regional anesthesia CEA",
            "General anesthesia CEA",
            "Cranial nerve injury",
            "Embolic protection device",
            "Distal filter protection",
            "Proximal flow reversal",
            "Periprocedural stroke",
            "Hyperperfusion syndrome",
            "Cerebral hemorrhage",
            "Restenosis after CEA",
            "In-stent restenosis carotid",
            "Duplex velocity criteria",
            "CTA carotids",
            "MRA carotids",
            "Vertebral artery stenosis",
            "Vertebrobasilar insufficiency",
            "Posterior circulation stroke",
            "Subclavian steal syndrome",
            "Ostial vertebral lesion",
            "Extracranial vertebral artery",
            "Intracranial vertebral artery",
            "Vertebral artery stenting",
            "Subclavian artery stenosis",
            "Best medical therapy BMT",
            "Antiplatelet therapy",
            "Statin therapy",
            "Blood pressure control",
            "Smoking cessation",
            "Carotid plaque imaging MRI",
            "Microembolic signals TCD",
            "Timing of CEA after stroke",
            "High surgical risk criteria",
            "Contralateral carotid occlusion",
        ],
    },
    "asymptomatic_pad": {
        "id": "c7c42f76507211f0b6356a892e29a549",
        "name": "Asymptomatic PAD",
        "utterances": [
            "Peripheral arterial disease PAD",
            "Asymptomatic PAD",
            "Lower extremity atherosclerosis",
            "Ankle-brachial index ABI",
            "ABI less than 0.90",
            "Toe-brachial index TBI",
            "Pulse volume recording PVR",
            "Doppler waveform analysis",
            "Claudication screening",
            "High-risk populations smokers",
            "Diabetes mellitus PAD risk",
            "Chronic kidney disease PAD risk",
            "Cardiovascular risk reduction",
            "Best medical therapy BMT",
            "High-intensity statin",
            "LDL-C target",
            "Antihypertensive therapy",
            "Glycemic control",
            "Smoking cessation counseling",
            "Supervised exercise therapy",
            "Physical activity prescription",
            "Dietary intervention",
            "Antiplatelet therapy primary prevention",
            "Aspirin in PAD",
            "Clopidogrel therapy",
            "Dual pathway inhibition DPI",
            "Rivaroxaban 2.5 mg bid",
            "Bleeding risk assessment",
            "Gastroprotection PPI",
            "Carotid coronary polyvascular disease",
            "Major adverse cardiovascular events MACE",
            "Major adverse limb events MALE",
            "Acute limb ischemia risk",
            "Progression to CLTI",
            "Foot care education",
            "Neuropathy screening",
            "WIfI staging risk stratification",
            "Rutherford classification",
            "Fontaine classification",
            "Ultrasound arterial mapping",
            "CTA lower limb runoff",
            "MRA runoff",
            "Incidental PAD on imaging",
            "Pulse palpation",
            "Auscultation for femoral bruit",
            "Walking impairment questionnaire",
            "Quality of life measures VascuQoL",
            "Brachial systolic pressure",
            "Segmental pressures",
            "ASCVD risk estimation",
        ],
    },
    "clti": {
        "id": "acd1930edc3411f09021f2381272676b",
        "name": "Chronic Limb-Threatening Ischemia",
        "utterances": [
            "Chronic limb-threatening ischemia CLTI",
            "Critical limb ischemia CLI",
            "Rest pain",
            "Ischemic ulcer",
            "Gangrene",
            "Tissue loss",
            "Diabetic foot ulcer",
            "Neuroischemic ulcer",
            "Infection foot",
            "Osteomyelitis",
            "Sepsis risk",
            "WIfI classification",
            "GLASS staging",
            "Infrapopliteal disease",
            "Below-the-knee BTK arteries",
            "Pedal artery disease",
            "Angiosome concept",
            "Revascularization first strategy",
            "Endovascular-first",
            "Bypass-first",
            "Surgical bypass",
            "Autologous vein graft",
            "Great saphenous vein GSV conduit",
            "Prosthetic bypass",
            "Outflow assessment",
            "Balloon angioplasty",
            "Drug-coated balloon DCB",
            "Drug-eluting stent DES",
            "Atherectomy",
            "Intravascular lithotripsy IVL",
            "Chronic total occlusion CTO crossing",
            "Retrograde pedal access",
            "Subintimal angioplasty",
            "Minor amputation",
            "Major amputation",
            "Below-knee amputation BKA",
            "Limb salvage",
            "Wound care",
            "Debridement",
            "Offloading",
            "Multidisciplinary limb team",
            "Toe pressure",
            "Transcutaneous oxygen pressure TcPO2",
            "Skin perfusion pressure SPP",
            "Duplex ultrasound",
            "CTA runoff",
            "MRA runoff",
            "Perioperative antibiotics",
            "Antiplatelet therapy after revascularization",
            "Surveillance of bypass graft",
        ],
    },
    "acute_limb_ischaemia": {
        "id": "9eeed489ff9d11f0b82f32d89964721d",
        "name": "Acute Limb Ischaemia",
        "utterances": [
            "Acute limb ischemia ALI",
            "Sudden limb pain",
            "Pallor",
            "Pulselessness",
            "Paresthesia",
            "Paralysis",
            "Six Ps",
            "Rutherford classification ALI",
            "Viable limb I",
            "Threatened limb IIa",
            "Immediately threatened IIb",
            "Irreversible ischemia III",
            "Embolism",
            "Thrombosis in situ",
            "Thrombosed bypass graft",
            "Thrombosed stent",
            "Acute-on-chronic ischemia",
            "Popliteal artery aneurysm thrombosis",
            "Aortic saddle embolus",
            "Cardioembolic source AF",
            "Hypercoagulable state",
            "Heparin IV UFH",
            "CTA for ALI",
            "Duplex ultrasound ALI",
            "On-table angiography",
            "Lactate",
            "Compartment syndrome",
            "Fasciotomy",
            "Reperfusion injury",
            "Myonephropathic metabolic syndrome",
            "Rhabdomyolysis",
            "Acute kidney injury",
            "Surgical embolectomy Fogarty",
            "Thrombectomy",
            "Bypass surgery",
            "Endarterectomy",
            "Catheter-directed thrombolysis CDT",
            "Alteplase infusion",
            "Mechanical thrombectomy",
            "Pharmacomechanical thrombolysis",
            "Percutaneous transluminal angioplasty PTA",
            "Stenting for ALI",
            "Adjunctive antiplatelet therapy",
            "Post-revascularization anticoagulation",
            "Bleeding complications",
            "Intracranial hemorrhage risk",
            "Time to revascularization",
            "Primary amputation",
            "Limb salvage outcomes",
            "Mortality risk",
        ],
    },
    "antithrombotic_therapy": {
        "id": "b6b02fdaffad11f0885f32d89964721d",
        "name": "Antithrombotic Therapy",
        "utterances": [
            "Antithrombotic therapy",
            "Antiplatelet therapy",
            "Anticoagulation",
            "Aspirin",
            "Clopidogrel",
            "Prasugrel",
            "Ticagrelor",
            "Dual antiplatelet therapy DAPT",
            "Single antiplatelet therapy SAPT",
            "P2Y12 inhibitor",
            "Glycoprotein IIb IIIa inhibitor",
            "Vitamin K antagonist warfarin",
            "Direct oral anticoagulant DOAC",
            "Rivaroxaban",
            "Apixaban",
            "Edoxaban",
            "Dabigatran",
            "Low molecular weight heparin LMWH",
            "Unfractionated heparin UFH",
            "Fondaparinux",
            "Dual pathway inhibition DPI",
            "Rivaroxaban 2.5 mg bid aspirin",
            "COMPASS regimen",
            "VOYAGER PAD regimen",
            "Bleeding risk stratification",
            "HAS-BLED score",
            "Gastroprotection PPI",
            "Perioperative bridging",
            "Heparin bridging",
            "Reversal agents PCC",
            "Idarucizumab",
            "Andexanet alfa",
            "Antithrombotics after EVAR TEVAR",
            "Antithrombotics after peripheral stenting",
            "Antithrombotics after bypass graft",
            "Graft patency",
            "In-stent thrombosis prevention",
            "Post-intervention DAPT duration",
            "Drug-coated balloon DCB antiplatelets",
            "Endarterectomy antiplatelets",
            "Carotid stent DAPT",
            "Atrial fibrillation AF anticoagulation",
            "Venous thromboembolism prophylaxis",
            "Cancer-associated thrombosis",
            "Heparin-induced thrombocytopenia HIT",
            "Platelet function testing",
            "Drug interactions CYP P-gp",
            "Renal dosing adjustment",
            "Monitoring INR",
            "Time in therapeutic range TTR",
        ],
    },
    "venous_thrombosis": {
        "id": "7104532adc4311f09021f2381272676b",
        "name": "Venous Thrombosis (DVT/PE)",
        "utterances": [
            "Venous thromboembolism VTE",
            "Deep vein thrombosis DVT",
            "Pulmonary embolism PE",
            "Proximal DVT",
            "Distal calf DVT",
            "Iliofemoral DVT",
            "Subsegmental PE",
            "Massive PE",
            "Submassive intermediate-risk PE",
            "Chronic thromboembolic pulmonary hypertension CTEPH",
            "Wells score DVT",
            "Wells score PE",
            "Revised Geneva score",
            "D-dimer testing",
            "Age-adjusted D-dimer",
            "Compression ultrasound CUS",
            "CT pulmonary angiography CTPA",
            "Ventilation-perfusion V/Q scan",
            "Echocardiography RV strain",
            "Troponin in PE",
            "BNP NT-proBNP in PE",
            "Anticoagulation initiation",
            "DOAC for VTE",
            "Warfarin for VTE",
            "LMWH for VTE",
            "Cancer-associated VTE",
            "Pregnancy-associated VTE",
            "Antiphospholipid syndrome APS",
            "Thrombophilia testing",
            "Provoked VTE",
            "Unprovoked VTE",
            "Extended anticoagulation",
            "Catheter-directed thrombolysis DVT",
            "Pharmacomechanical thrombectomy DVT",
            "Iliac vein stenting",
            "May-Thurner syndrome",
            "Inferior vena cava IVC filter",
            "Temporary IVC filter",
            "Filter retrieval",
            "Post-thrombotic syndrome PTS",
            "Villalta score",
            "Compression stockings",
            "Early ambulation",
            "Bleeding risk",
            "Major bleeding",
            "Recurrent VTE",
            "Perioperative interruption",
            "Bridging anticoagulation",
            "Heparin-induced thrombocytopenia HIT",
            "Outpatient PE pathway",
        ],
    },
    "chronic_venous_disease": {
        "id": "ec53f8c1ff9811f0a09132d89964721d",
        "name": "Chronic Venous Disease",
        "utterances": [
            "Chronic venous disease CVD",
            "Chronic venous insufficiency CVI",
            "Varicose veins",
            "Saphenous vein reflux",
            "Great saphenous vein GSV",
            "Small saphenous vein SSV",
            "Accessory saphenous vein",
            "Perforator reflux",
            "Deep venous reflux",
            "Venous hypertension",
            "CEAP classification",
            "Venous Clinical Severity Score VCSS",
            "C0-C6 stages",
            "Venous ulcer C6",
            "Healed ulcer C5",
            "Edema C3",
            "Skin changes C4",
            "Lipodermatosclerosis",
            "Atrophie blanche",
            "Corona phlebectatica",
            "Duplex ultrasound mapping",
            "Reflux testing",
            "Valve closure time",
            "Venous obstruction",
            "Iliac vein compression",
            "Post-thrombotic disease",
            "Pelvic venous congestion",
            "May-Thurner syndrome chronic",
            "IVUS venous imaging",
            "CT venography",
            "MR venography",
            "Compression therapy",
            "Compression class",
            "Multilayer bandaging",
            "Intermittent pneumatic compression",
            "Venoactive drugs MPFF",
            "Sulodexide",
            "Ulcer dressings",
            "Wound debridement",
            "Skin grafting ulcer",
            "Endovenous thermal ablation EVTA",
            "Radiofrequency ablation RFA",
            "Endovenous laser ablation EVLA",
            "Cyanoacrylate closure",
            "Mechanochemical ablation MOCA",
            "Ultrasound-guided foam sclerotherapy UGFS",
            "Ambulatory phlebectomy",
            "Perforator ablation",
            "Iliac vein stenting CVD",
            "Recurrence REVAS",
        ],
    },
    "vascular_trauma": {
        "id": "8f58aeadec9411f0a38066bc68590b9b",
        "name": "Vascular Trauma",
        "utterances": [
            "Vascular trauma",
            "Blunt vascular injury",
            "Penetrating trauma",
            "Iatrogenic vascular injury",
            "Extremity vascular injury",
            "Thoracic aortic injury",
            "Abdominal vascular injury",
            "Pelvic vascular injury",
            "Junctional hemorrhage",
            "Hemorrhagic shock",
            "Hard signs of vascular injury",
            "Soft signs of vascular injury",
            "Expanding hematoma",
            "Active hemorrhage",
            "Bruit thrill",
            "Distal ischemia",
            "Pulse deficit",
            "Ankle-brachial index trauma",
            "Arterial pressure index API",
            "Compartment syndrome",
            "Damage control surgery",
            "Damage control resuscitation",
            "Massive transfusion protocol",
            "Permissive hypotension",
            "Tranexamic acid TXA",
            "Resuscitative endovascular balloon occlusion REBOA",
            "Tourniquet use",
            "Temporary intravascular shunt",
            "Fasciotomy",
            "CTA in trauma",
            "Duplex ultrasound trauma",
            "Conventional angiography",
            "On-table angiography",
            "Arterial repair",
            "Primary repair",
            "Interposition graft",
            "Vein graft reverse saphenous",
            "Prosthetic graft",
            "Patch angioplasty",
            "Ligation selected veins",
            "Venous repair",
            "Arteriovenous fistula trauma",
            "Pseudoaneurysm trauma",
            "Endovascular stent graft trauma",
            "Covered stent",
            "Infection prophylaxis",
            "Antibiotics in open wounds",
            "Anticoagulation considerations",
            "Mangled extremity severity score MESS",
            "Revascularization timing",
        ],
    },
    "vascular_graft_infections": {
        "id": "29981e72dc4311f09021f2381272676b",
        "name": "Vascular Graft Infections",
        "utterances": [
            "Vascular graft infection VGI",
            "Endograft infection",
            "Aortic graft infection",
            "Peripheral bypass graft infection",
            "Early graft infection",
            "Late graft infection",
            "Biofilm formation",
            "Staphylococcus aureus",
            "Coagulase-negative staphylococci",
            "Gram-negative bacilli",
            "Polymicrobial infection",
            "Fungal graft infection",
            "Sepsis",
            "Bacteremia",
            "Persistent fever",
            "Anastomotic pseudoaneurysm",
            "Graft-enteric fistula",
            "Aortoenteric fistula AEF",
            "Graft erosion",
            "Groin wound infection",
            "MAGIC criteria",
            "Clinical diagnosis VGI",
            "Blood cultures",
            "Graft aspirate culture",
            "Tissue sampling",
            "Leukocytosis",
            "CTA for VGI",
            "FDG-PET CT",
            "Radiolabeled white cell scan",
            "Duplex ultrasound",
            "Perigraft fluid",
            "Perigraft gas",
            "Anastomotic disruption",
            "Explantation of graft",
            "In situ reconstruction ISR",
            "Extra-anatomic bypass",
            "Axillobifemoral bypass",
            "Cryopreserved allograft",
            "Autologous femoral vein NAIS",
            "Rifampin-soaked graft",
            "Omental flap",
            "Muscle flap coverage",
            "Long-term antibiotics",
            "Suppressive antibiotic therapy",
            "Empiric broad-spectrum therapy",
            "Culture-directed therapy",
            "Antifungal therapy",
            "Reinfection",
            "Limb loss",
            "Mortality VGI",
        ],
    },
    "vascular_access": {
        "id": "bbe0b3a0f39611f08b265ef3771a102d",
        "name": "Vascular Access",
        "utterances": [
            "Vascular access",
            "Hemodialysis access",
            "Arteriovenous fistula AVF",
            "Arteriovenous graft AVG",
            "Central venous catheter CVC",
            "Tunneled dialysis catheter",
            "Non-tunneled catheter",
            "Catheter-related bloodstream infection CRBSI",
            "Access patency",
            "Primary patency",
            "Assisted primary patency",
            "Secondary patency",
            "Radiocephalic AVF",
            "Brachiocephalic AVF",
            "Brachiobasilic fistula transposition",
            "Forearm loop graft",
            "Upper arm graft",
            "Lower extremity access",
            "HeRO graft",
            "Preoperative vessel mapping",
            "Duplex ultrasound mapping",
            "Vein diameter threshold",
            "Artery diameter threshold",
            "Allen test",
            "Central venous stenosis",
            "Subclavian vein stenosis",
            "SVC obstruction",
            "Venography",
            "IVUS central veins",
            "AVF maturation",
            "Failure to mature",
            "Juxta-anastomotic stenosis",
            "Outflow stenosis",
            "Inflow stenosis",
            "Cephalic arch stenosis",
            "Aneurysm access",
            "Pseudoaneurysm access",
            "Steal syndrome DASS",
            "High-output cardiac failure",
            "Hand ischemia",
            "Percutaneous transluminal angioplasty PTA",
            "Drug-coated balloon access",
            "Stent graft access",
            "Thrombectomy access",
            "Catheter-directed thrombolysis access",
            "Banding flow reduction",
            "DRIL procedure",
            "RUDI procedure",
            "Proximalization of arterial inflow PAI",
            "Surveillance flow monitoring",
        ],
    },
}


class SemanticRouterService:
    _instance: Optional["SemanticRouterService"] = None
    _router: Optional[SemanticRouter] = None
    _initialized: bool = False

    def __new__(cls):
        if cls._instance is None:
            cls._instance = super().__new__(cls)
        return cls._instance

    def initialize(self):
        if self._initialized:
            logger.info("SemanticRouterService already initialized")
            return

        logger.info("Initializing SemanticRouterService with FastEmbed (local embeddings)...")

        try:
            # Use multilingual embedding model for cross-language query support
            # Supports queries in Greek, German, French, Spanish, Italian, Portuguese, etc.
            encoder = FastEmbedEncoder(model_name="intfloat/multilingual-e5-large")

            routes = []
            for key, config in GUIDELINES_CONFIG.items():
                route = Route(
                    name=key,
                    utterances=config["utterances"],
                )
                routes.append(route)
                logger.info(f"  Created route: {key} ({len(config['utterances'])} utterances)")

            self._router = SemanticRouter(
                encoder=encoder,
                routes=routes,
                auto_sync="local",
            )

            self._initialized = True
            logger.info(f"SemanticRouterService initialized with {len(routes)} routes")

        except Exception as e:
            logger.error(f"Failed to initialize SemanticRouterService: {e}")
            raise

    @property
    def is_initialized(self) -> bool:
        return self._initialized

    @property
    def model_name(self) -> str:
        """Return the embedding model name being used."""
        return "intfloat/multilingual-e5-large" if self._initialized else "not_initialized"

    def get_status(self) -> dict:
        """Return status info for health checks."""
        return {
            "initialized": self._initialized,
            "model_name": self.model_name,
            "routes_count": len(GUIDELINES_CONFIG) if self._initialized else 0,
            "multilingual_support": self._initialized,  # multilingual model is always used when initialized
        }

    def route(self, query: str, top_k: int = 1) -> list[dict]:
        if not self._initialized or not self._router:
            raise RuntimeError("SemanticRouterService not initialized")

        result = self._router(query)

        matched_key = None
        confidence = None

        if isinstance(result, list) and len(result) > 0:
            first = result[0]
            matched_key = getattr(first, "name", None)
            confidence = getattr(first, "similarity", None) or getattr(first, "score", None)
        elif hasattr(result, "name"):
            matched_key = result.name  # type: ignore
            confidence = getattr(result, "similarity", None) or getattr(result, "score", None)

        if matched_key is None:
            return []

        if matched_key in GUIDELINES_CONFIG:
            config = GUIDELINES_CONFIG[matched_key]
            return [{
                "guideline_key": matched_key,
                "guideline_id": config["id"],
                "guideline_name": config["name"],
                "confidence": confidence,
            }]

        return []

    def route_multi(self, query: str, max_routes: int = 4, min_score_threshold: float = 0.68, min_confidence: float = 0.35) -> list[dict]:
        """
        Route a query to multiple guidelines using absolute score floor selection.
        
        Selection logic:
        1. Always include the top matching guideline (primary)
        2. Include ALL additional guidelines scoring above min_score_threshold (secondaries)
        3. This ensures complex multi-domain queries get all relevant guidelines
        4. Primary guideline is marked with is_primary=True for weighted chunk allocation
        
        Args:
            query: The clinical question
            max_routes: Maximum number of guidelines to return (default 4)
            min_score_threshold: Absolute score floor - include all guidelines above this (default 0.70)
            min_confidence: Minimum absolute similarity threshold for any result (default 0.35)
        """
        if not self._initialized or not self._router:
            raise RuntimeError("SemanticRouterService not initialized")

        try:
            import numpy as np
            
            query_embedding = np.array(self._router.encoder([query])[0])
            
            route_scores = []
            index = self._router.index
            
            if hasattr(index, 'index') and index.index is not None:
                all_embeddings = np.array(index.index)
                route_names = index.routes if hasattr(index, 'routes') else []
                
                for i, emb in enumerate(all_embeddings):
                    similarity = float(np.dot(query_embedding, emb) / (np.linalg.norm(query_embedding) * np.linalg.norm(emb)))
                    if i < len(route_names):
                        route_scores.append((route_names[i], similarity))
                
                aggregated = {}
                for route_name, score in route_scores:
                    if route_name not in aggregated:
                        aggregated[route_name] = score
                    else:
                        aggregated[route_name] = max(aggregated[route_name], score)
                
                sorted_routes = sorted(aggregated.items(), key=lambda x: x[1], reverse=True)
                
                if not sorted_routes:
                    return self.route(query, top_k=1)
                
                results = []
                for idx, (route_name, score) in enumerate(sorted_routes[:max_routes]):
                    # Include if: (1) it's the top scorer, OR (2) score is above absolute threshold
                    is_top = (idx == 0)
                    above_floor = (score >= min_score_threshold)
                    
                    if (is_top or above_floor) and score >= min_confidence and route_name in GUIDELINES_CONFIG:
                        config = GUIDELINES_CONFIG[route_name]
                        results.append({
                            "guideline_key": route_name,
                            "guideline_id": config["id"],
                            "guideline_name": config["name"],
                            "confidence": round(score, 4),
                            "is_primary": is_top,
                        })
                
                if results:
                    logger.info(f"route_multi: query='{query[:60]}...', selected={[r['guideline_key'] for r in results]}, scores={[r['confidence'] for r in results]}, floor={min_score_threshold}")
                    return results
            
            return self.route(query, top_k=1)
            
        except Exception as e:
            logger.warning(f"route_multi failed, falling back to single route: {e}")
            return self.route(query, top_k=1)

    def get_guideline_by_key(self, key: str) -> Optional[dict]:
        if key in GUIDELINES_CONFIG:
            config = GUIDELINES_CONFIG[key]
            return {
                "guideline_key": key,
                "guideline_id": config["id"],
                "guideline_name": config["name"],
            }
        return None

    def get_all_guidelines(self) -> list[dict]:
        return [
            {
                "guideline_key": key,
                "guideline_id": config["id"],
                "guideline_name": config["name"],
            }
            for key, config in GUIDELINES_CONFIG.items()
        ]


semantic_router_service = SemanticRouterService()
