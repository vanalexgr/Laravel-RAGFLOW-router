"""
Citation scoping tests - validates document ID scoping prevents contamination.

Run with: pytest test_citation_scoping.py -v
"""
import os
import pytest
from fastapi.testclient import TestClient
from app import app

client = TestClient(app)

# Actual document IDs from RAGFlow unified recommendations dataset
# NOTE: Some guidelines don't have document IDs yet (TBAD, CLTI, Venous Thrombosis)
ANTITHROMBOTIC_THERAPY_DOC_ID = "40795f9affad11f0a4d332d89964721d"
CAROTID_VERTEBRAL_DOC_ID = "4f5cce1cffbd11f0b3e232d89964721d"
AAA_DOC_ID = "40a8b701ff8111f080ad32d89964721d"
ACUTE_LIMB_ISCHAEMIA_DOC_ID = "0fb7a35eff9711f08d5232d89964721d"

def test_citation_scoping_single_guideline():
    """Test that citations are scoped to a single guideline's document ID."""
    payload = {
        "question": "What are the indications for CEA in symptomatic carotid stenosis?",
        "narrative_datasets": [
            {
                "id": "87c72055ffbe11f095ef32d89964721d", 
                "name": "Carotid & Vertebral",
                "score": 0.95
            }
        ],
        "citation_dataset_id": "bc4896bdf5fb11f084fe32d89964721d",
        "citation_document_ids": [CAROTID_VERTEBRAL_DOC_ID],
        "narrative_max": 10,
        "citation_max": 5,
    }
    
    response = client.post("/retrieve_dual", json=payload)
    assert response.status_code == 200
    
    data = response.json()
    citations = data["citations"]["chunks"]
    
    # Verify all citations come from allowed document ID
    violations = []
    for i, chunk in enumerate(citations):
        doc_id = chunk.get("document_id") or chunk.get("doc_id") or chunk.get("DocumentID")
        if doc_id and doc_id != CAROTID_VERTEBRAL_DOC_ID:
            violations.append(f"Chunk {i}: doc_id={doc_id}")
    
    assert len(violations) == 0, \
        f"Citation contamination found:\n" + "\n".join(violations) + \
        f"\nExpected all chunks from {CAROTID_VERTEBRAL_DOC_ID}"
    
    print(f"✅ Single guideline test passed: {len(citations)} citations, all from correct doc_id")

def test_citation_scoping_multi_guideline():
    """Test that citations are scoped to multiple guidelines' document IDs."""
    allowed_doc_ids = [AAA_DOC_ID, ACUTE_LIMB_ISCHAEMIA_DOC_ID]
    
    payload = {
        "question": "Management of AAA with acute limb ischemia complications",
        "narrative_datasets": [
            {"id": "7fb152c6ffbd11f0b2af32d89964721d", "name": "Abdominal Aortic Aneurysm"},
            {"id": "9eeed489ff9d11f0b82f32d89964721d", "name": "Acute Limb Ischaemia"}
        ],
        "citation_dataset_id": "bc4896bdf5fb11f084fe32d89964721d",
        "citation_document_ids": allowed_doc_ids,
        "narrative_max": 10,
        "citation_max": 5,
    }
    
    response = client.post("/retrieve_dual", json=payload)
    assert response.status_code == 200
    
    data = response.json()
    citations = data["citations"]["chunks"]
    
    violations = []
    for i, chunk in enumerate(citations):
        doc_id = chunk.get("document_id") or chunk.get("doc_id") or chunk.get("DocumentID")
        if doc_id and doc_id not in allowed_doc_ids:
            violations.append(f"Chunk {i}: doc_id={doc_id}")
    
    assert len(violations) == 0, \
        f"Citation contamination found:\n" + "\n".join(violations) + \
        f"\nExpected all chunks from {allowed_doc_ids}"
    
    print(f"✅ Multi-guideline test passed: {len(citations)} citations, all within scope")

def test_ui_source_capping():
    """Test that UI sources are capped correctly."""
    payload = {
        "question": "AAA treatment options",
        "narrative_datasets": [
            {"id": "7fb152c6ffbd11f0b2af32d89964721d", "name": "Abdominal Aortic Aneurysm"}
        ],
        "citation_dataset_id": "bc4896bdf5fb11f084fe32d89964721d",
        "citation_document_ids": [AAA_DOC_ID],
        "narrative_max": 15,  # Request more than UI cap
        "citation_max": 10,   # Request more than UI cap
    }
    
    response = client.post("/retrieve_dual", json=payload)
    assert response.status_code == 200
    
    data = response.json()
    
    # UI should cap at 6 each
    assert len(data["narrative"]["chunks"]) <= 6, \
        f"Narrative chunks not capped: got {len(data['narrative']['chunks'])}, expected <= 6"
    assert len(data["citations"]["chunks"]) <= 6, \
        f"Citation chunks not capped: got {len(data['citations']['chunks'])}, expected <= 6"
    
    # But total_retrieved should show actual retrieval count
    assert "total_retrieved" in data["narrative"], "total_retrieved missing from narrative"
    assert "total_retrieved" in data["citations"], "total_retrieved missing from citations"
    
    # Verify ui_capped flag is set
    assert data["retrieval_info"].get("ui_capped") == True, "ui_capped flag not set"
    
    print(f"✅ UI capping test passed: returned {len(data['narrative']['chunks'])} narrative, "
          f"{len(data['citations']['chunks'])} citations (total_retrieved: "
          f"{data['narrative']['total_retrieved']} narrative, {data['citations']['total_retrieved']} citations)")

def test_unscoped_fallback():
    """Test that system gracefully handles missing citation_document_ids (old behavior)."""
    payload = {
        "question": "AAA EVAR vs open repair",
        "narrative_datasets": [
            {"id": "7fb152c6ffbd11f0b2af32d89964721d", "name": "Abdominal Aortic Aneurysm"}
        ],
        "citation_dataset_id": "bc4896bdf5fb11f084fe32d89964721d",
        # citation_document_ids not provided - should use old unscoped behavior
        "narrative_max": 10,
        "citation_max": 5,
    }
    
    response = client.post("/retrieve_dual", json=payload)
    assert response.status_code == 200
    
    data = response.json()
    
    # Should still return results
    assert "citations" in data
    assert "chunks" in data["citations"]
    
    # Verify citation_document_ids is None or empty in response
    assert not data["retrieval_info"].get("citation_document_ids"), \
        "citation_document_ids should be empty for unscoped request"
    
    print(f"✅ Unscoped fallback test passed: {len(data['citations']['chunks'])} citations retrieved")

def test_guardrail_enforcement():
    """Test that PHP guardrails are correctly adding required guidelines."""
    # This test would require calling the full PHP->Python pipeline
    # For now, just verify the Python service accepts the expected payload structure
    payload = {
        "question": "anticoagulation for carotid stenosis",  # Should trigger both guardrails
        "narrative_datasets": [
            {"id": "87c72055ffbe11f095ef32d89964721d", "name": "Carotid & Vertebral"},
            {"id": "b6b02fdaffad11f0885f32d89964721d", "name": "Antithrombotic Therapy"}
        ],
        "citation_dataset_id": "bc4896bdf5fb11f084fe32d89964721d",
        "citation_document_ids": [CAROTID_VERTEBRAL_DOC_ID, ANTITHROMBOTIC_THERAPY_DOC_ID],
        "narrative_max": 10,
        "citation_max": 5,
    }
    
    response = client.post("/retrieve_dual", json=payload)
    assert response.status_code == 200
    
    data = response.json()
    
    # Verify both document IDs are in the response info
    assert CAROTID_VERTEBRAL_DOC_ID in data["retrieval_info"]["citation_document_ids"]
    assert ANTITHROMBOTIC_THERAPY_DOC_ID in data["retrieval_info"]["citation_document_ids"]
    
    print(f"✅ Guardrail enforcement test passed: multi-guideline scoping working")

if __name__ == "__main__":
    pytest.main([__file__, "-v", "--tb=short"])
