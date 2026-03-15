# push_gpt5_prompt.py — runs inside the OpenWebUI container
import json
import sqlite3


PROMPT_PATH = "/tmp/gpt5_system_prompt.txt"
MODEL_ID = "gpt-5-chat"


def main():
    prompt = open(PROMPT_PATH).read()

    conn = sqlite3.connect("/app/backend/data/webui.db")
    row = conn.execute("SELECT params FROM model WHERE id = ?", (MODEL_ID,)).fetchone()
    if not row:
        raise RuntimeError(f"Model not found: {MODEL_ID}")

    params = json.loads(row[0] or "{}")
    params["system"] = prompt

    conn.execute(
        "UPDATE model SET params = ? WHERE id = ?",
        (json.dumps(params), MODEL_ID),
    )
    conn.commit()

    updated = conn.execute(
        "SELECT json_extract(params, '$.system') FROM model WHERE id = ?",
        (MODEL_ID,),
    ).fetchone()
    conn.close()

    print("Updated", MODEL_ID)
    print((updated[0] or "")[:400])


if __name__ == "__main__":
    main()
