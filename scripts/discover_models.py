import httpx
import sys

OPENWEBUI_URL = "https://chat.clinicalguidelines.io/api"
API_KEY = "sk-08ac6476e41c4ea4b220e35c4ea3ac81"

async def list_models():
    async with httpx.AsyncClient() as client:
        try:
            response = await client.get(
                f"{OPENWEBUI_URL}/models",
                headers={"Authorization": f"Bearer {API_KEY}"}
            )
            if response.status_code == 200:
                models = response.json()
                print("Available Models:")
                for model in models.get('data', []):
                    print(f"- {model['id']} ({model.get('name', 'No name')})")
            else:
                print(f"Error: {response.status_code}")
                print(response.text)
        except Exception as e:
            print(f"Connection failed: {e}")

if __name__ == "__main__":
    import asyncio
    asyncio.run(list_models())
