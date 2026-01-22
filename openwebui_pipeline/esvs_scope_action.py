"""
title: ESVS Guideline Filter
author: Medical AI Team
version: 1.0
required_open_webui_version: 0.3.0
"""

from pydantic import BaseModel, Field
from typing import Optional, List
import httpx

class Action:
    class Valves(BaseModel):
        API_BASE_URL: str = Field(
            default="http://host.docker.internal:8080/api/v1",
            description="Laravel API Base URL (reachable from OpenWebUI container)"
        )
        API_KEY: str = Field(
            default="",
            description="API Key for Laravel Backend"
        )

    def __init__(self):
        self.valves = self.Valves()

    async def action(
        self,
        body: dict,
        __user__=None,
        __event_emitter__=None,
        __event_call__=None,
    ) -> Optional[dict]:
        print(f"action: {__event_emitter__}")

        if not __event_call__:
            return None

        # 1. Define Available Scopes (aligned with Laravel config/guidelines.php)
        scopes = [
            {"label": "Automatic Routing (Reset)", "value": "auto"},
            {"label": "Carotid & Vertebral", "value": "carotid_vertebral"},
            {"label": "Abdominal Aortic Aneurysm", "value": "abdominal_aortic_aneurysm"},
            {"label": "Chronic Limb-Threatening Ischemia", "value": "clti"},
            {"label": "Venous Thrombosis", "value": "venous_thrombosis"},
            {"label": "Vascular Trauma", "value": "vascular_trauma"},
            {"label": "Aortic Arch", "value": "aortic_arch"},
            {"label": "Peripheral Arterial Disease", "value": "pad"},
            {"label": "Descending Thoracic Aorta", "value": "descending_thoracic_aorta"},
        ]

        # 2. Ask User for Selection via Event Call
        try:
            # We want a dropdown or radio list.
            # Using 'input' type with options/select might be specific to newer OWUI,
            # but standard 'input' allows text.
            # For best UX, we'll try to present a list in the message and ask for input, or if supported, a select.
            # Currently standard actions support 'confirmation' and 'input'.
            
            # Let's use a simpler approach: Just a text input with the list in the message description for now,
            # unless we know we can do a select.
            # NOTE: Improved UI might need a custom tool, but Action 'input' is safe.
            
            # Construct a clear prompt
            scope_list_text = "\n".join([f"- {s['value']}: {s['label']}" for s in scopes])
            
            selection_response = await __event_call__(
                {
                    "type": "input",
                    "data": {
                        "title": "Select Guideline Scope",
                        "message": f"Enter the code for the guideline you want to focus on (or 'auto' to reset):\n\n{scope_list_text}",
                        "placeholder": "e.g., carotid_vertebral",
                    },
                }
            )
            
            selected_value = selection_response.strip() if selection_response else "auto"
            
            # Basic validation
            valid_keys = [s['value'] for s in scopes]
            if selected_value not in valid_keys:
                 await __event_emitter__(
                    {
                        "type": "status",
                        "data": {"description": f"Invalid selection: {selected_value}. Resetting to auto.", "done": True},
                    }
                )
                 selected_value = "auto"

        except Exception as e:
            print(f"Event call error: {e}")
            return None

        # 3. Send Selection to Laravel Backend
        chat_id = body.get("chat_id")
        if not chat_id:
             # Fallback if chat_id missing (e.g. test)
             chat_id = "test_user"

        new_scope = []
        if selected_value != "auto":
            new_scope = [selected_value]

        async with httpx.AsyncClient() as client:
            try:
                headers = {
                    "Authorization": f"Bearer {self.valves.API_KEY}",
                    "Content-Type": "application/json",
                    "Accept": "application/json"
                }
                
                payload = {
                    "chat_id": chat_id,
                    "scope": new_scope
                }
                
                url = f"{self.valves.API_BASE_URL}/context/scope"
                
                response = await client.post(url, json=payload, headers=headers, timeout=5.0)
                
                if response.status_code == 200:
                    description = "Automatic Routing Active" if not new_scope else f"Scope set to: {selected_value}"
                    await __event_emitter__(
                        {
                            "type": "status",
                            "data": {"description": description, "done": True},
                        }
                    )
                    
                    # Optionally return a confirmation message in chat
                    # return {"content": f"**System**: Guideline scope updated to `{selected_value}`."}
                    pass # Silent update is often cleaner
                    
                else:
                    await __event_emitter__(
                        {
                            "type": "status",
                            "data": {"description": f"Failed to set scope: {response.status_code}", "done": True},
                        }
                    )

            except Exception as e:
                await __event_emitter__(
                    {
                        "type": "status",
                        "data": {"description": f"Error updating scope: {str(e)}", "done": True},
                    }
                )

        return None
