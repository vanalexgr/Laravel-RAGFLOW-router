"""
Push vascular_mcp_adapter.py content into the OpenWebUI SQLite DB.
Run inside the open-webui container:
    python3 /tmp/push_adapter.py
Source file must be at /tmp/vascular_expert_new.py
"""
import sqlite3

TOOL_ID = "vascular_mcp_adapter"

with open("/tmp/vascular_expert_new.py", "r") as f:
    new_content = f.read()

conn = sqlite3.connect("/app/backend/data/webui.db")
conn.execute(
    "UPDATE tool SET content=?, updated_at=strftime('%s','now') WHERE id=?",
    (new_content, TOOL_ID),
)
conn.commit()

row = conn.execute(
    "SELECT length(content) FROM tool WHERE id=?", (TOOL_ID,)
).fetchone()
print(f"SUCCESS: {TOOL_ID} content length = {row[0]}")

content = conn.execute(
    "SELECT content FROM tool WHERE id=?", (TOOL_ID,)
).fetchone()[0]

checks = [
    ("version in header", "version:" in content),
    ("has_gap", "has_gap" in content),
    ("_build_two_layer_blueprint", "_build_two_layer_blueprint" in content),
    ("SCOPE FILTER", "SCOPE FILTER" in content),
    ("_CLARIFICATION_OPTIONS", "_CLARIFICATION_OPTIONS" in content),
    ("_format_clarification_with_options", "_format_clarification_with_options" in content),
]
for name, ok in checks:
    print(f"  {'OK' if ok else 'MISSING'}: {name}")
conn.close()
