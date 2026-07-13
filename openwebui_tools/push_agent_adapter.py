"""
Push vascular_agent_adapter.py to the OpenWebUI SQLite DB.
Run inside the open-webui container:
    python3 /tmp/push_agent_adapter.py
Source file must be at /tmp/vascular_agent_adapter.py
"""

import sqlite3


TOOL_ID = "vascular_agent_adapter"  # dev tool — Vizra ADK on VM 9.205.16.132
SOURCE_PATH = "/tmp/vascular_agent_adapter.py"
DB_PATH = "/app/backend/data/webui.db"


with open(SOURCE_PATH, "r", encoding="utf-8") as handle:
    new_content = handle.read()

connection = sqlite3.connect(DB_PATH)
connection.execute(
    "UPDATE tool SET content=?, updated_at=strftime('%s','now') WHERE id=?",
    (new_content, TOOL_ID),
)
connection.commit()

row = connection.execute(
    "SELECT length(content) FROM tool WHERE id=?",
    (TOOL_ID,),
).fetchone()
print(f"SUCCESS: {TOOL_ID} content length = {row[0]}")

content = connection.execute(
    "SELECT content FROM tool WHERE id=?",
    (TOOL_ID,),
).fetchone()[0]

checks = [
    ("version: 3.6.0", "version: 3.6.0" in content),
    ("agent-consult endpoint", "agent-consult" in content),
    ("no anthropic import", "import anthropic" not in content),
    ("instant clarification gate", "_check_clarification" in content),
    ("GUIDELINE_RETRIEVAL_PAUSED", "GUIDELINE_RETRIEVAL_PAUSED" in content),
    ("gpt5 markers present", "ANSWER STYLE (MANDATORY)" in content),
]
for label, ok in checks:
    print(f"  {'OK' if ok else 'MISSING'}: {label}")

connection.close()
