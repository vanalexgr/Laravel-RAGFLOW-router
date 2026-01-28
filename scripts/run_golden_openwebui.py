import httpx
import asyncio
import re
import json
import os
from datetime import datetime

OPENWEBUI_URL = "https://chat.clinicalguidelines.io/api"
API_KEY = "sk-08ac6476e41c4ea4b220e35c4ea3ac81"
MODEL_ID = "gpt-5-chat"
DATASET_PATH = "/home/vga/LAVAREL/Laravel-RAGFLOW-router/tests/golden_dataset.php"
OUTPUT_FILE = "/home/vga/LAVAREL/Laravel-RAGFLOW-router/golden_api_results.jsonl"

def parse_golden_dataset(path):
    with open(path, 'r') as f:
        content = f.read()
    
    # Use regex to find queries
    # Matches: 'query' => '...',
    queries = re.findall(r"'query'\s*=>\s*'([^']*)'", content)
    expecteds = re.findall(r"'expected'\s*=>\s*([^,\]]*)", content)
    
    results = []
    for i in range(len(queries)):
        expected_raw = expecteds[i].strip().strip("'").strip('"')
        results.append({
            "query": queries[i],
            "expected": expected_raw
        })
    return results

async def run_test(client, test_item, semaphore):
    async with semaphore:
        query = test_item['query']
        print(f"Testing: {query[:50]}...")
        
        start_time = datetime.now()
        try:
            response = await client.post(
                f"{OPENWEBUI_URL}/chat/completions",
                headers={
                    "Authorization": f"Bearer {API_KEY}",
                    "Content-Type": "application/json"
                },
                json={
                    "model": MODEL_ID,
                    "messages": [{"role": "user", "content": query}],
                    "stream": True
                },
                timeout=300.0
            )
            
            duration = (datetime.now() - start_time).total_seconds()
            
            if response.status_code == 200:
                try:
                    # Try standard JSON first
                    result = response.json()
                    answer = result['choices'][0]['message']['content']
                    
                    output = {
                        "query": query,
                        "expected": test_item['expected'],
                        "status": "success",
                        "answer_preview": answer[:200] + "...",
                        "duration": duration
                    }
                except Exception:
                    # Try SSE parsing (OpenWebUI sometimes forces stream)
                    lines = response.text.splitlines()
                    full_content = ""
                    for line in lines:
                        if line.startswith("data: "):
                            data_str = line[6:]
                            if data_str.strip() == "[DONE]":
                                break
                            try:
                                data_obj = json.loads(data_str)
                                choices = data_obj.get("choices", [])
                                if choices:
                                    delta = choices[0].get("delta", {})
                                    content = delta.get("content", "")
                                    if content:
                                        full_content += content
                                    
                                    # Handle case where it's non-streamed but returned as data: JSON
                                    message = choices[0].get("message", {})
                                    if message.get("content"):
                                        full_content += message.get("content")
                            except:
                                continue
                    
                    if full_content:
                        output = {
                            "query": query,
                            "expected": test_item['expected'],
                            "status": "success",
                            "answer_preview": full_content[:200] + "...",
                            "duration": duration,
                            "format": "sse"
                        }
                    else:
                        output = {
                            "query": query,
                            "expected": test_item['expected'],
                            "status": "parse_error",
                            "error": "Failed to parse JSON or SSE",
                            "response_snippet": response.text[:200]
                        }
            else:
                print(f"API Error {response.status_code} for {query[:20]}: {response.text[:100]}")
                output = {
                    "query": query,
                    "expected": test_item['expected'],
                    "status": "error",
                    "code": response.status_code,
                    "error": response.text
                }
        except Exception as e:
            output = {
                "query": query,
                "expected": test_item['expected'],
                "status": "exception",
                "error": str(e)
            }
            
        with open(OUTPUT_FILE, 'a') as f:
            f.write(json.dumps(output) + "\n")
        
        return output

async def main():
    if os.path.exists(OUTPUT_FILE):
        os.remove(OUTPUT_FILE)
        
    tests = parse_golden_dataset(DATASET_PATH)
    print(f"Loaded {len(tests)} tests from golden dataset.")
    
    # Run a subset or all? Let's start with a small batch to ensure it works
    # User said "golden set", but 400+ might take a while.
    # I'll run the first 20 as a representative sample and then ask
    
    semaphore = asyncio.Semaphore(3) # Concurrency of 3
    
    async with httpx.AsyncClient() as client:
        tasks = []
        for test in tests: # Run ALL tests
            tasks.append(run_test(client, test, semaphore))
        
        results = await asyncio.gather(*tasks)
        
        success_count = sum(1 for r in results if r.get('status') == 'success')
        print(f"\nFinal Results: {success_count}/{len(tests)} succeeded.")

if __name__ == "__main__":
    asyncio.run(main())
