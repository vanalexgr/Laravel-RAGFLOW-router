import requests
import json
import os

# CONFIG (From .env)
API_KEY = "RAGFLOW_API_KEY_REDACTED"
BASE_URL = "https://ragflow.clinicalguidelines.io/api/v1"
DATASET_ID = "4fff3622eb1b11f09021f2381272676b" # From config/guidelines.php

def test_retrieval(question, threshold=0.1):
    url = f"{BASE_URL}/retrieval"
    headers = {
        "Authorization": f"Bearer {API_KEY}",
        "Content-Type": "application/json"
    }
    payload = {
        "question": question,
        "dataset_ids": [DATASET_ID],
        "similarity_threshold": threshold,
        "top_k": 5,
        "page": 1,
        "size": 10
    }
    
    print(f"\n--- Testing Query: '{question}' (Threshold: {threshold}) ---")
    print(f"URL: {url}")
    # print(f"Payload: {json.dumps(payload, indent=2)}")
    
    try:
        response = requests.post(url, headers=headers, json=payload)
        response.raise_for_status()
        data = response.json()
        
        chunks = data.get("data", {}).get("chunks", [])
        print(f"Status: {response.status_code}")
        print(f"Chunks Found: {len(chunks)}")
        
        if len(chunks) > 0:
            print("Successfully retrieved content!")
            for i, chunk in enumerate(chunks[:2]):
                content = chunk.get("content_with_weight", chunk.get("content", ""))
                print(f"\nChunk [{i+1}] Preview:\n{content[:200]}...")
                print(f"Meta: {chunk.keys()}")
        else:
            print("WARNING: No chunks found. Dataset might be empty or query mismatch.")
            
    except Exception as e:
        print(f"ERROR: {e}")
        if hasattr(e, 'response') and e.response:
            print(f"Response: {e.response.text}")

def list_datasets():
    url = f"{BASE_URL}/datasets"
    headers = {
        "Authorization": f"Bearer {API_KEY}",
        "Content-Type": "application/json"
    }
    print(f"\n--- Listing Available Datasets ---")
    try:
        response = requests.get(url, headers=headers, params={"page": 1, "size": 100})
        response.raise_for_status()
        data = response.json()
        
        datasets = data.get("data", [])
        print(f"Total Datasets: {len(datasets)}")
        for ds in datasets:
            print(f"ID: {ds.get('id')} | Name: {ds.get('name')} | Chunks: {ds.get('chunk_count')}")
            
    except Exception as e:
        print(f"ERROR Listing Datasets: {e}")
        if hasattr(e, 'response') and e.response:
            print(f"Response: {e.response.text}")

if __name__ == "__main__":
    # Test 1: List datasets (might fail if permissions restricted)
    list_datasets()
    
    # Test 2: Test Asymptomatic PAD Dataset (Known Working for Narrative)
    # ID from config/guidelines.php
    PAD_ID = "c7c42f76507211f0b6356a892e29a549"
    print(f"\n--- CONTROL TEST: Asymptomatic PAD (ID: {PAD_ID}) ---")
    dataset_bkp = DATASET_ID
    DATASET_ID = PAD_ID
    test_retrieval("What is PAD?", threshold=0.1)
    
    # Test 3: Test Recommendations Dataset (New Fixed ID)
    DATASET_ID = "bc4896bdf5fb11f084fe32d89964721d"
    print(f"\n--- FIX VERIFICATION: Recommendations (ID: {DATASET_ID}) ---")
    test_retrieval("What is PAD?", threshold=0.1)
    test_retrieval("recommendation", threshold=0.01)
