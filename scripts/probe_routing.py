import httpx
import asyncio
import json
import re

OPENWEBUI_URL = "https://chat.clinicalguidelines.io/api"
API_KEY = "OPENAI_API_KEY_REDACTED"
MODEL_ID = "gpt-5-chat"

# Tricky queries to test routing
QUERIES = [
    {
        "q": "aneurysm involving the subclavian artery",
        "expected": "aortic_arch", 
        "bad": "vascular_graft_infections"
    },
    {
        "q": "May-Thurner syndrome management",
        "expected": "chronic_venous_disease",
        "bad": "descending_thoracic_aorta"
    },
    {
        "q": "Stroke patient with acute proximal DVT",
        "expected": "carotid_vertebral", # Or venous_thrombosis IF not excluded
        "note": "Check if venous_thrombosis is excluded"
    },
    {
        "q": "treatment for AAA",
        "expected": "abdominal_aortic_aneurysm",
        "bad": "None"
    }
]

async def probe_routing(client, test_case):
    query = test_case['q']
    print(f"\nTesting: '{query}'")
    
    try:
        async with client.stream("POST", 
            f"{OPENWEBUI_URL}/chat/completions",
            headers={
                "Authorization": f"Bearer {API_KEY}",
                "Content-Type": "application/json"
            },
            json={
                "model": MODEL_ID,
                "messages": [{"role": "user", "content": query}],
                "stream": True # Force Stream to get status updates
            },
            timeout=30.0
        ) as response:
            
            selected_guidelines = "Unknown"
            status_updates = []
            
            async for line in response.aiter_lines():
                if line.startswith("data: "):
                    data_str = line[6:]
                    if data_str.strip() == "[DONE]":
                        break
                    try:
                        data_obj = json.loads(data_str)
                        
                        # OpenWebUI often sends status updates in a specific way, 
                        # sometimes as a 'citation' or just part of the stream?
                        # Actually, the esvs_rag_filter emits status messages.
                        # These usually show up in the UI, but standard OpenAI API 
                        # might not relay them unless they are part of the content 
                        # or specific tool outputs.
                        
                        # However, let's check chunks.
                        choices = data_obj.get("choices", [])
                        if choices:
                             delta = choices[0].get("delta", {})
                             # Sometimes filter status is not passed to API client easily.
                             # BUT, the filter code says: await self._emit_status(...)
                             # Retrieve the answer content to see if it mentions "No guideline found"
                             
                        # Wait, if we can't see status updates via API, 
                        # we have to infer from the ANSWER or CITATIONS.
                        # The answer usually starts with "According to [Guideline Name]..." if successful.
                        pass
                        
                    except:
                        pass
            
            # Since we might not get the status events in a standard OpenAI client stream
            # (unless OpenWebUI implements them as custom events),
            # let's look at the FINAL ANSWER accumulation.
            
            # Re-run as non-stream to get full answer quickly for analysis
            response_full = await client.post(
                f"{OPENWEBUI_URL}/chat/completions",
                headers={
                    "Authorization": f"Bearer {API_KEY}",
                    "Content-Type": "application/json"
                },
                json={
                    "model": MODEL_ID,
                    "messages": [{"role": "user", "content": query}],
                    "stream": False 
                },
                timeout=30.0
            )
            
            if response_full.status_code == 200:
                full_data = response_full.json()
                content = full_data['choices'][0]['message']['content']
                print(f"Answer snippet: {content[:150]}...")
                
                # Heuristic analysis of the answer
                if "WITHOUT evidence" in content:
                    print("❌ Result: NO GUIDELINES SELECTED (Fallback Triggered)")
                else:
                    print("✅ Result: Guidelines likely selected.")
                    # Try to guess which one from text
                    lower_content = content.lower()
                    if "aortic arch" in lower_content:
                        print("   -> Detected: Aortic Arch")
                    if "venous" in lower_content:
                        print("   -> Detected: Venous")
                    if "aneurysm" in lower_content:
                        print("   -> Detected: Aneurysm")
                    if "graft infection" in lower_content:
                        print("   -> Detected: Graft Infection")
                        
            else:
                print(f"❌ API Error: {response_full.status_code}")

    except Exception as e:
        print(f"❌ Error: {e}")

async def main():
    async with httpx.AsyncClient() as client:
        for test in QUERIES:
            await probe_routing(client, test)

if __name__ == "__main__":
    asyncio.run(main())
