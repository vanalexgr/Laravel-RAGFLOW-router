# HIPAA Compliance Documentation

## Overview

This document describes the Protected Health Information (PHI) de-identification measures implemented in the ESVS Vascular Guidelines Consultation System. These measures follow the HIPAA Safe Harbor method for de-identification, enabling the system to process clinical queries while protecting patient privacy.

## De-identification Strategy

### Safe Harbor Method (45 CFR 164.514(b)(2))

The system implements automatic de-identification following the HIPAA Safe Harbor method, which requires removal or generalization of 18 specific identifiers. Our implementation scrubs these identifiers from user queries **before** they are sent to external services (Azure OpenAI, RAGFlow).

### Data Flow

```
User Query (may contain PHI)
    ↓
┌─────────────────────────────┐
│   PHIScrubberService        │
│   (Local processing only)   │
│   - Pattern matching        │
│   - Name detection          │
│   - Age generalization      │
└─────────────────────────────┘
    ↓
De-identified Query
    ↓
┌─────────────────────────────┐
│   Azure OpenAI / RAGFlow    │
│   (External services)       │
│   - Receives only scrubbed  │
│     de-identified text      │
└─────────────────────────────┘
```

## Identifiers Handled

### Removed and Replaced

| Identifier | Detection Method | Replacement |
|------------|-----------------|-------------|
| Names | Dictionary matching (5000+ common names) | `[NAME]` |
| Full Dates | Regex patterns (MM/DD/YYYY, ISO, etc.) | `[DATE]` |
| Social Security Numbers | Regex (XXX-XX-XXXX) | `[SSN]` |
| Medical Record Numbers | Regex (MRN, Patient ID, Chart #) | `[MRN]` |
| Phone/Fax Numbers | Regex (various formats) | `[PHONE]` |
| Email Addresses | Regex | `[EMAIL]` |
| IP Addresses | Regex (IPv4) | `[IP]` |
| URLs | Regex (http/https) | `[URL]` |
| Street Addresses | Regex + state detection | `[ADDRESS]` / `[LOCATION]` |
| Cities | Dictionary (200+ major US cities) | `[CITY]` |
| ZIP Codes | Regex (5/9 digit validation) | `[ZIP]` |
| Counties | Regex pattern matching | `[COUNTY]` |
| Device Identifiers | Regex (IMEI, Serial, MAC) | `[DEVICE_ID]` |
| Vehicle Identifiers | Regex (VIN, License Plate) | `[VEHICLE_ID]` |
| Biometric Data | Regex (fingerprint, DNA, retina) | `[BIOMETRIC]` |
| License Numbers | Regex (DL, DEA, NPI) | `[LICENSE]` |
| Account Numbers | Regex (Account, Policy, Member ID) | `[ACCOUNT]` |

### Preserved with Modification

| Identifier | Handling | Rationale |
|------------|----------|-----------|
| **Ages < 90** | Kept as-is | Clinically essential; permitted by Safe Harbor |
| **Ages ≥ 90** | Converted to "90+" | Required by Safe Harbor (rare, potentially identifying) |
| **Year of birth** | Kept when alone | Permitted without full date (month/day removed) |

### Clinical Information Preserved

The following clinical information is intentionally **preserved** as it is essential for accurate guideline retrieval:

- Diagnoses and conditions (e.g., "carotid stenosis", "AAA")
- Procedures (e.g., "CEA", "EVAR", "bypass")
- Symptoms (e.g., "claudication", "rest pain")
- Anatomical terms (e.g., "femoral", "iliac", "aortic")
- Clinical measurements (e.g., "70% stenosis", "5.5cm aneurysm")
- Risk factors (e.g., "diabetic", "hypertensive", "smoker")
- Medications and treatments

## Implementation Details

### PHIScrubberService Class

Location: `app/Services/PHIScrubberService.php`

Key methods:
- `scrub(string $text)`: Main entry point, returns scrubbed text with audit info
- `logAudit(string $correlationId, array $scrubResult)`: Logs de-identification activity

### Name Detection

The system uses a dictionary-based approach with 5000+ common first and last names from diverse cultural backgrounds. Names are detected when:

1. A capitalized word matches the first name dictionary
2. The following word matches the last name dictionary
3. Both appear consecutively

Location: `storage/app/phi/common_names.json`

### Integration Points

PHI scrubbing is applied at:

1. **Retrieve Endpoint** (`/api/v1/retrieve`): All queries are scrubbed before guideline routing and RAGFlow retrieval
2. **Chat Completions** (`/api/v1/chat/completions`): Can be enabled for the agent pathway

## Audit Trail

### What is Logged

- Correlation ID for request tracing
- Original text length (character count only)
- Scrubbed text length
- Total redaction count
- Breakdown by category (names, dates, SSN, etc.)
- Timestamp

### What is NOT Logged

- Original PHI values (never stored or logged)
- The actual content that was redacted
- Any information that could reconstruct the original PHI

### Example Audit Log Entry

```json
{
    "message": "[PHI SCRUBBER] De-identification applied",
    "correlation_id": "a1b2c3d4",
    "original_length": 156,
    "scrubbed_length": 142,
    "total_redactions": 3,
    "redaction_counts": {
        "names": 1,
        "dates": 1,
        "ages_over_90": 1
    }
}
```

## API Response

The retrieve endpoint includes PHI scrubbing status in responses:

```json
{
    "success": true,
    "question": "[NAME], 90+yo female with carotid stenosis",
    "phi_scrubbed": true,
    "phi_redaction_count": 2,
    "selected_guidelines": {...},
    "narrative_chunks": [...],
    "citation_chunks": [...]
}
```

## Limitations

### Coverage Assessment

**High Coverage (Pattern-based):**
- Names (5000+ common names dictionary)
- Dates (multiple formats including month-year)
- SSN, MRN, phone, email, IP
- Major US cities (200+ in dictionary)
- Street addresses with common suffixes
- ZIP codes, counties

**Limited Coverage (May Require Enhancement):**
- Unusual names not in dictionary
- Small towns and cities not in the 200+ list
- Hospital names, clinic names
- Relative dates ("last Tuesday", "3 weeks ago")
- Contextual identifiers in narrative text

### Compliance Level

This implementation provides **meaningful PHI protection** suitable for:
- Clinical query interfaces (transient text, no PHI storage)
- Internal tools with trained users
- Environments with supplementary organizational policies

**For full Safe Harbor certification**, organizations should consider:
1. Comprehensive geographic datasets (30,000+ US localities)
2. NER-based entity detection (spaCy, Presidio, or similar)
3. Expert Determination by qualified statistician

### Recommendations for Enhanced Compliance

**Technical Enhancements (Roadmap):**
1. **Expanded geographic data**: Integrate US Census/USPS place names for complete city coverage
2. **Offline NER**: Add spaCy or Presidio as secondary detection layer
3. **Hospital/facility dictionary**: Common healthcare facility names

**Organizational Requirements (Mandatory):**
1. **User training**: Instruct users to avoid including identifying information
2. **Terms of use**: Prohibit entry of patient identifiers in queries
3. **Consent workflow**: If PHI may be entered, obtain explicit consent
4. **Incident response**: Procedures for handling PHI exposure incidents
5. **BAAs**: Obtain Business Associate Agreements from Azure and RAGFlow providers
6. **Regular audits**: Review redaction logs and assess residual risk

## Compliance Checklist

### Technical Safeguards ✓

- [x] PHI automatically de-identified before external API calls
- [x] Audit logging of de-identification events
- [x] Correlation IDs for request tracing
- [x] No PHI stored in logs or databases
- [x] HTTPS encryption in transit

### Administrative (Organization Responsibility)

- [ ] Obtain BAA from Azure OpenAI (Microsoft offers this)
- [ ] Obtain BAA from RAGFlow provider (or self-host)
- [ ] Obtain BAA from hosting provider
- [ ] Implement staff training on HIPAA policies
- [ ] Establish incident response procedures
- [ ] Conduct regular security assessments

### Physical Safeguards (Infrastructure)

- [ ] Secure data center (if self-hosted)
- [ ] Access controls to production environment
- [ ] Workstation security policies

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-01-11 | Initial de-identification implementation |

## References

- [HHS HIPAA De-identification Guidance](https://www.hhs.gov/hipaa/for-professionals/privacy/special-topics/de-identification/index.html)
- [45 CFR 164.514(b) - Safe Harbor Method](https://www.law.cornell.edu/cfr/text/45/164.514)
- [NIST AI Risk Management Framework](https://www.nist.gov/itl/ai-risk-management-framework)
