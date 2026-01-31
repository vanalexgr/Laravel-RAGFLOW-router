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

if __name__ == "__main__":
    # Test 1: Standard Question
    test_retrieval("What is PAD?")
    
    # Test 2: Very generic (should return something if dataset exists)
    test_retrieval("aortic", threshold=0.01)
