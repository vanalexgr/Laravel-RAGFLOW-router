#!/usr/bin/env bash
# Set a new password for a RAGFlow UI account on the Hetzner server.
# The password is typed at a hidden prompt and sent over SSH stdin only; RAGFlow stores
# werkzeug-hash(base64(password)), which is what its login checks.
set -euo pipefail
HOST=${HOST:-root@178.105.193.206}
SSH_KEY=${SSH_KEY:-$HOME/.ssh/id_ed25519}
EMAIL=${EMAIL:?usage: EMAIL=you@example.com bash reset_ragflow_password.sh}

read -rsp "New RAGFlow password for $EMAIL: " P1; echo
read -rsp "Repeat it: " P2; echo
[[ $P1 == "$P2" ]] || { echo "Passwords differ - nothing changed." >&2; exit 1; }
(( ${#P1} >= 12 )) || { echo "Use at least 12 characters - nothing changed." >&2; exit 1; }

printf '%s\n%s\n' "$EMAIL" "$P1" | ssh -i "$SSH_KEY" -o BatchMode=yes "$HOST" \
  'docker exec -i docker-ragflow-cpu-1 /ragflow/.venv/bin/python3 -c "
import os,sys,base64,secrets,pymysql
from werkzeug.security import generate_password_hash, check_password_hash
email=sys.stdin.readline().strip(); pw=sys.stdin.readline().rstrip(\"\n\")
enc=base64.b64encode(pw.encode()).decode()
c=pymysql.connect(host=\"mysql\",user=\"root\",password=os.environ[\"MYSQL_PASSWORD\"],database=\"rag_flow\"); cur=c.cursor()
n=cur.execute(\"update user set password=%s, access_token=%s where email=%s\",(generate_password_hash(enc), secrets.token_urlsafe(48), email)); c.commit()
cur.execute(\"select password from user where email=%s\",(email,)); ok=check_password_hash(cur.fetchone()[0], enc)
print(\"updated\",n,\"account(s); verified:\",ok)
"'
unset P1 P2
echo
echo "Now open a tunnel (keep it running):  ssh -L 8082:127.0.0.1:8082 root@178.105.193.206"
echo "then browse to http://localhost:8082 and log in as $EMAIL."
