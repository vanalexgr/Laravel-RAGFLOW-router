# Audited clinical snippet library — candidate extraction

> ⛔ **UNVERIFIED — clinician sign-off required.**

These are candidate assertions extracted from the existing OpenWebUI adapter blueprint. They are not
new guideline content, are not approved evidence, and must not be emitted while
`GATE_V2_AUDITED_SNIPPETS_ENABLED=false` (the default).

TODO(human): A named clinician must verify each candidate against an authoritative source, record the
source/version and approval date, and approve enabling the runtime flag.

## Candidate ANTICOAG-DOAC-001

Source: `openwebui_tools/vascular_mcp_adapter.py` lines 1935–1936 and 2091.

> For standard DOACs (apixaban, rivaroxaban, edoxaban), the adapter states cessation is typically 48 hours before major surgery for normal renal function and restart is 24–72 hours post-operatively when haemostasis is secure.

Status: **UNVERIFIED — clinician sign-off required.**

## Candidate ANTICOAG-BRIDGE-002

Source: `openwebui_tools/vascular_mcp_adapter.py` lines 1938 and 2092.

> The adapter states that routine bridging is not indicated for atrial fibrillation treated with a DOAC.

Status: **UNVERIFIED — clinician sign-off required.**

## Candidate ANTICOAG-TRIPLE-003

Source: `openwebui_tools/vascular_mcp_adapter.py` lines 1942 and 2093.

> The adapter warns to avoid triple therapy consisting of anticoagulation plus dual antiplatelet therapy because of high bleeding risk.

Status: **UNVERIFIED — clinician sign-off required.**

## Candidate AAA-RUPTURE-RISK-004

Source: `openwebui_tools/vascular_mcp_adapter.py` line 1963.

> The adapter uses an asymptomatic 5.8 cm AAA short-term rupture-risk exemplar of approximately 1% per month when explaining a limb-first sequencing decision.

Status: **UNVERIFIED — clinician sign-off required.**
