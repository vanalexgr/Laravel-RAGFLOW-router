"""
title: Vascular Expert Tools
author: open-webui
author_url: https://github.com/open-webui
funding_url: https://github.com/open-webui
version: 1.0.0
"""

import requests
from pydantic import BaseModel, Field


class Tools:
    def __init__(self):
        self.valves = self.Valves()

    class Valves(BaseModel):
        VASCULAR_API_BASE_URL: str = Field(
            default="https://lavarel.eastus2.cloudapp.azure.com",
            description="Base URL for Vascular Expert API",
        )
        VASCULAR_API_KEY: str = Field(
            default="gukUXd551qIobQVHVQLedUMmA4E8Cx4s",
            description="API Key for authentication",
        )

    def consult_vascular_guidelines(
        self, question: str, __user__: dict = {}
    ) -> str:
        """
        Consult ESVS Vascular Guidelines. Use this for any clinical vascular surgery question.
        :param question: The clinical question to answer
        :return: Evidence-based recommendations and citations
        """
        
        url = f"{self.valves.VASCULAR_API_BASE_URL}/api/v1/vascular-consult"
        headers = {
            "Authorization": f"Bearer {self.valves.VASCULAR_API_KEY}",
            "Content-Type": "application/json",
        }
        payload = {"question": question}

        try:
            response = requests.post(url, json=payload, headers=headers, timeout=30)
            response.raise_for_status()
            
            data = response.json()
            return data.get("result", "No results returned")
            
        except requests.exceptions.RequestException as e:
            return f"Error calling Vascular Expert API: {str(e)}"
