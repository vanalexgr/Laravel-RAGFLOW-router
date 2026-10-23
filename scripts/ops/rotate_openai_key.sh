#!/usr/bin/env bash
# Rotate the OpenAI API key on the Hetzner server.
#
# The key is typed at a hidden prompt and sent to the server over SSH stdin only:
# it never appears on a command line, in shell history, in a log, or in a file
# (apart from the config stores it is meant to live in).
#
# What it updates (all of them, or nothing if validation fails):
#   1. /opt/cg/laravel/app/.env          OPENAI_API_KEY   (planner)
#   2. RAGFlow MySQL rag_flow.tenant_llm every row holding the old key (embeddings = retrieval)
#   3. OpenWebUI webui.db config         every value equal to the old key (answer writing)
#   4. /opt/cg/gate-v2/.env              OPENAI_API_KEY   (Gate v2 prototype; optional)
#
# Usage:
#   bash rotate_openai_key.sh                  # validate, show plan, ask, apply
#   bash rotate_openai_key.sh --validate-only  # only test the new key and show where the old one lives
set -euo pipefail

HOST=${HOST:-root@178.105.193.206}
SSH_KEY=${SSH_KEY:-$HOME/.ssh/id_ed25519}
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$HOST")
REMOTE=/root/.rotate_openai_key_remote.sh

VALIDATE_ONLY=0
[[ ${1:-} == --validate-only ]] && VALIDATE_ONLY=1

if [[ -t 0 ]]; then
  read -rsp "Paste the NEW OpenAI API key (input hidden): " NEW; echo
else
  IFS= read -r NEW   # non-interactive use: key on stdin
fi
NEW=${NEW//[$'\r\n\t ']/}
if [[ ! $NEW =~ ^sk-[A-Za-z0-9_-]{20,}$ ]]; then
  echo "That does not look like an OpenAI key (expected sk-...)." >&2; exit 2
fi
echo "New key: ...${NEW: -4}"

GATE=1
if [[ -t 0 ]]; then
  read -rp "Also put the new key in Gate v2 (/opt/cg/gate-v2)? [Y/n] " a
  [[ ${a,,} == n* ]] && GATE=0
fi

# Ship the remote half (everything below the __REMOTE__ marker; contains no secrets).
sed -n '/^__REMOTE__$/,$p' "$0" | tail -n +2 | "${SSH[@]}" "umask 077; cat > $REMOTE"
trap '"${SSH[@]}" "rm -f $REMOTE" >/dev/null 2>&1 || true' EXIT

echo; echo "=== Validating on the server (nothing is changed in this step) ==="
if ! printf '%s\n' "$NEW" | "${SSH[@]}" "bash $REMOTE validate $GATE"; then
  echo; echo "Validation failed - nothing was changed." >&2; exit 1
fi

if (( VALIDATE_ONLY )); then echo; echo "--validate-only: stopping here, nothing changed."; exit 0; fi
if [[ ! -t 0 ]]; then echo "Not a terminal: refusing to apply without confirmation." >&2; exit 1; fi

echo
read -rp "Apply the new key everywhere listed above and restart PHP-FPM + OpenWebUI? Type APPLY: " ok
[[ $ok == APPLY ]] || { echo "Aborted - nothing changed."; exit 1; }

echo; echo "=== Applying ==="
printf '%s\n' "$NEW" | "${SSH[@]}" "bash $REMOTE apply $GATE"
unset NEW
exit 0

__REMOTE__
#!/usr/bin/env bash
# Runs on the server as root. Reads the new key from stdin. Args: validate|apply GATE(0|1)
set -euo pipefail
umask 077
MODE=${1:?mode}; GATE=${2:-1}
PROD=/opt/cg/laravel/app
GV2=/opt/cg/gate-v2
KEYRE='^sk-[A-Za-z0-9_-]{20,}$'

IFS= read -r NEW || true
NEW=${NEW//[$'\r\n\t ']/}
[[ $NEW =~ $KEYRE ]] || { echo "ERROR: new key has an unexpected format"; exit 2; }

envkey() { sed -n 's/^OPENAI_API_KEY=//p' "$1" | head -1 | tr -d "\"'\r "; }
l4() { printf '...%s' "${1: -4}"; }

OLD=$(envkey "$PROD/.env")
[[ $OLD =~ $KEYRE ]] || { echo "ERROR: could not read the current key from $PROD/.env"; exit 2; }
[[ $NEW != "$OLD" ]] || { echo "ERROR: that is the CURRENT (compromised) key - create a new one first"; exit 2; }
GV2_OLD=""
[[ -f $GV2/.env ]] && GV2_OLD=$(envkey "$GV2/.env")
[[ -f $GV2/.env ]] || GATE=0

T=$(mktemp -d); trap 'rm -rf "$T"' EXIT

# api KEY METHOD PATH [JSON]  -> prints the HTTP status; response body in $T/body.
# The Authorization header is fed to curl on stdin (-H @-), never on the command line.
api() {
  local args=(-sS -o "$T/body" -w '%{http_code}' --max-time 90 -X "$2" -H @-)
  [[ -n ${4:-} ]] && args+=(-H 'Content-Type: application/json' --data-binary "$4")
  printf 'Authorization: Bearer %s\n' "$1" | curl "${args[@]}" "https://api.openai.com/v1$3" 2>/dev/null || true
}
errmsg() { python3 -c 'import json,sys
try: print(json.load(open(sys.argv[1]))["error"]["message"][:160])
except Exception: print("(no error message)")' "$T/body"; }

mysql_q() { docker exec -i docker-mysql-1 sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B rag_flow' 2> >(grep -v 'Using a password' >&2 || true); }

# Models the apps call (non-secret names). Gate v2 stage models come from its .env or config defaults.
CHAT_MODELS=(gpt-5-mini gpt-5.4)
if (( GATE )); then
  for v in GATE_V2_MODEL GATE_V2_ORIENT_MODEL GATE_V2_PATHWAY_MODEL GATE_V2_PROBE_MODEL GATE_V2_CRITIC_MODEL GATE_V2_KNOWLEDGE_MODEL; do
    m=$(sed -n "s/^$v=//p" "$GV2/.env" | head -1 | tr -d "\"'\r ")
    [[ -n $m ]] && CHAT_MODELS+=("$m")
  done
  CHAT_MODELS+=(gpt-4.1-mini gpt-4.1)   # config/gate-v2.php defaults for orient/pathway/probe/critic
fi
mapfile -t CHAT_MODELS < <(printf '%s\n' "${CHAT_MODELS[@]}" | awk '!seen[$0]++')

key_status() {  # prints ACTIVE / REVOKED / UNKNOWN(code)
  local c; c=$(api "$1" GET /models)
  case $c in 200) echo ACTIVE;; 401) echo REVOKED;; *) echo "UNKNOWN(HTTP $c)";; esac
}

# OpenWebUI >= 0.12 stores config as key/value rows (config.key, config.value);
# older versions kept one JSON blob in config.data. Both layouts are handled.
webui_py_count='import sqlite3,sys
k=sys.stdin.readline().strip()
c=sqlite3.connect("/app/backend/data/webui.db")
cols=[r[1] for r in c.execute("pragma table_info(config)")]
col="value" if "value" in cols else "data"
print(sum(str(v).count(k) for (v,) in c.execute(f"select {col} from config")))'

inventory() {
  echo "Where the current key (production, $(l4 "$OLD")) lives:"
  echo "  Laravel .env lines:        $(grep -cF -- "$OLD" "$PROD/.env" || true)"
  echo "  RAGFlow tenant_llm rows:   $(printf "SELECT COUNT(*) FROM tenant_llm WHERE api_key='%s';\n" "$OLD" | mysql_q)"
  echo "  RAGFlow instance rows:     $(printf "SELECT COUNT(*) FROM tenant_model_instance WHERE api_key='%s';\n" "$OLD" | mysql_q 2>/dev/null || echo n/a)"
  echo "  OpenWebUI config values:   $(printf '%s\n' "$OLD" | docker exec -i open-webui python3 -c "$webui_py_count")"
  if [[ -n $GV2_OLD ]]; then
    echo "  Gate v2 .env key:          $(l4 "$GV2_OLD") $([[ $GV2_OLD == "$OLD" ]] && echo '(same as production)' || echo '(a different key)')$( (( GATE )) || echo ' - will NOT be changed')"
  fi
  echo "Other copies of the current key in OpenWebUI's database (chats, tools, etc. - not changed):"
  printf '%s\n' "$OLD" | docker exec -i open-webui python3 -c 'import sqlite3,sys
k=sys.stdin.readline().strip(); c=sqlite3.connect("/app/backend/data/webui.db"); hit=0
for (t,) in c.execute("select name from sqlite_master where type=\"table\"").fetchall():
    for col in c.execute(f"pragma table_info(\"{t}\")").fetchall():
        if t=="config": continue
        n=c.execute(f"select count(*) from \"{t}\" where instr(cast(\"{col[1]}\" as text), ?)>0",(k,)).fetchone()[0]
        if n: hit=1; print(f"    {t}.{col[1]}: {n} row(s)")
print("    none" if not hit else "    ^ review these: the key was stored or pasted there")'
  echo "Other files on the server containing the current key (not changed; .gz archives not searched):"
  timeout 180 grep -rlsF --exclude-dir={vendor,node_modules,.venv,.git} -- "$OLD" /opt/cg /etc /root 2>/dev/null \
    | grep -vxF -e "$PROD/.env" -e "$GV2/.env" -e "$PROD/bootstrap/cache/config.php" -e "$GV2/bootstrap/cache/config.php" \
    | sed 's/^/    /' || true
}

validate_new() {
  local fail=0 c dim m
  report() { if [[ $2 == 200 ]]; then echo "  OK    $1"; else echo "  FAIL  $1 -> HTTP $2: $(errmsg)"; fail=1; fi; }
  echo "Testing the NEW key $(l4 "$NEW") from this server:"
  c=$(api "$NEW" GET /models); report "list models" "$c"
  for m in text-embedding-3-large text-embedding-ada-002; do
    c=$(api "$NEW" POST /embeddings "{\"model\":\"$m\",\"input\":\"key rotation test\"}")
    dim=$(python3 -c 'import json,sys;print(len(json.load(open(sys.argv[1]))["data"][0]["embedding"]))' "$T/body" 2>/dev/null || echo "?")
    report "embeddings $m (dim $dim)" "$c"
  done
  for m in "${CHAT_MODELS[@]}"; do
    c=$(api "$NEW" POST /chat/completions "{\"model\":\"$m\",\"messages\":[{\"role\":\"user\",\"content\":\"Reply with OK\"}],\"max_completion_tokens\":300}")
    report "chat $m" "$c"
  done
  return $fail
}

recache() {  # re-cache Laravel config as www-data (the PHP-FPM user) so FPM can always read it
  local d=$1 f=$1/bootstrap/cache/config.php
  [[ -f $f ]] || { echo "  $d: config not cached, nothing to do"; return; }
  # www-data must be able to read .env, or artisan silently falls back to defaults.
  runuser -u www-data -- test -r "$d/.env" || chgrp www-data "$d/.env"
  (cd "$d" && runuser -u www-data -- sh -c 'umask 027; php artisan config:cache -q')
  [[ -f $f ]] || { echo "  ERROR: $f was not rebuilt"; return 1; }
  echo "  $d: config re-cached as www-data; new key in cache: $(printf '%s\n' "$NEW" | php -r '$k=trim(fgets(STDIN)); $c=require $argv[1]; $f=false; array_walk_recursive($c,function($v)use($k,&$f){if($v===$k)$f=true;}); echo $f?"yes":"NO";' "$f")"
}

set_env_key() {  # set_env_key FILE  (new key on stdin) - rewrites in place, keeps owner/mode
  python3 -c 'import sys,re
p=sys.argv[1]; new=sys.stdin.readline().strip(); s=open(p).read()
s2,n=re.subn(r"(?m)^OPENAI_API_KEY=.*$", lambda m: "OPENAI_API_KEY="+new, s)
if n!=1: sys.exit(f"expected exactly one OPENAI_API_KEY line in {p}, found {n}")
open(p,"w").write(s2)' "$1" <<<"$NEW"
}

case $MODE in
validate)
  inventory
  echo
  echo "Current production key $(l4 "$OLD") is: $(key_status "$OLD")"
  [[ -n $GV2_OLD && $GV2_OLD != "$OLD" ]] && echo "Gate v2's current key $(l4 "$GV2_OLD") is: $(key_status "$GV2_OLD")"
  echo
  validate_new
  echo; echo "Validation passed."
  ;;

apply)
  validate_new >/dev/null || { echo "ERROR: new key failed validation - nothing changed"; exit 1; }
  TS=$(date +%Y%m%d_%H%M%S); BK=/root/openai_key_rotation_$TS
  mkdir -p "$BK"
  cat > "$BK/ROLLBACK.txt" <<EOF
To put the previous key back (run as root on this server):
  cp -a $BK/prod.env $PROD/.env
  [ -f $BK/gate-v2.env ] && cp -a $BK/gate-v2.env $GV2/.env
  docker exec -i docker-mysql-1 sh -c 'mysql -uroot -p"\$MYSQL_ROOT_PASSWORD" rag_flow' < $BK/tenant_llm.sql
  docker cp $BK/webui.db open-webui:/app/backend/data/webui.db && docker restart open-webui
  (cd $PROD && php artisan config:cache); (cd $GV2 && runuser -u www-data -- php artisan config:cache)
  systemctl restart php8.5-fpm.service
EOF
  STEP=start
  trap 'echo; echo "FAILED during step: $STEP"; cat "$BK/ROLLBACK.txt"' ERR

  STEP="backups"
  cp -a "$PROD/.env" "$BK/prod.env"
  (( GATE )) && cp -a "$GV2/.env" "$BK/gate-v2.env"
  docker exec docker-mysql-1 sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction rag_flow tenant_llm' > "$BK/tenant_llm.sql" 2>/dev/null
  [[ -s $BK/tenant_llm.sql ]]
  docker exec open-webui python3 -c 'import sqlite3;s=sqlite3.connect("/app/backend/data/webui.db");d=sqlite3.connect("/tmp/webui.keyrot.db");s.backup(d);d.close()'
  docker cp open-webui:/tmp/webui.keyrot.db "$BK/webui.db" >/dev/null
  docker exec open-webui rm -f /tmp/webui.keyrot.db
  echo "  backups: $BK  (they contain the OLD key - delete once you trust the new one)"

  STEP="Laravel .env"
  set_env_key "$PROD/.env"; echo "  updated $PROD/.env"

  STEP="RAGFlow tenant_llm"
  n=$(printf "UPDATE tenant_llm SET api_key='%s' WHERE api_key='%s'; SELECT ROW_COUNT();\n" "$NEW" "$OLD" | mysql_q)
  (( n > 0 )); echo "  updated $n RAGFlow tenant_llm rows"
  # RAGFlow >= 0.27 resolves keys from tenant_model_instance, not tenant_llm.
  if [[ $(echo "SHOW TABLES LIKE 'tenant_model_instance';" | mysql_q) == tenant_model_instance ]]; then
    n=$(printf "UPDATE tenant_model_instance SET api_key='%s' WHERE api_key='%s'; SELECT ROW_COUNT();\n" "$NEW" "$OLD" | mysql_q)
    (( n > 0 )); echo "  updated $n RAGFlow tenant_model_instance row(s)"
  fi

  STEP="OpenWebUI config"
  n=$(printf '%s\n%s\n' "$OLD" "$NEW" | docker exec -i open-webui python3 -c 'import sqlite3,json,sys
old,new=[l.strip() for l in sys.stdin.read().splitlines()[:2]]
c=sqlite3.connect("/app/backend/data/webui.db")
cols=[r[1] for r in c.execute("pragma table_info(config)")]
n=0
def walk(x):
    global n
    if isinstance(x,dict): return {k:walk(v) for k,v in x.items()}
    if isinstance(x,list): return [walk(v) for v in x]
    if x==old: n+=1; return new
    return x
if "value" in cols:   # OpenWebUI >= 0.12: one row per setting
    for key,raw in c.execute("select key,value from config").fetchall():
        if old not in str(raw): continue
        before=n; val=walk(json.loads(raw))
        if n>before: c.execute("update config set value=? where key=?",(json.dumps(val),key))
else:                 # older: single JSON blob
    rid,raw=c.execute("select id,data from config order by id desc limit 1").fetchone()
    data=walk(json.loads(raw) if isinstance(raw,str) else raw)
    if n: c.execute("update config set data=? where id=?",(json.dumps(data),rid))
c.commit()
print(n)')
  (( n > 0 )); echo "  updated $n OpenWebUI config value(s)"

  if (( GATE )); then
    STEP="Gate v2 .env"; set_env_key "$GV2/.env"; echo "  updated $GV2/.env"
  fi

  STEP="re-cache Laravel config"
  recache "$PROD"
  (( GATE )) && recache "$GV2"

  STEP="restart services"
  systemctl restart php8.5-fpm.service; echo "  restarted php8.5-fpm"
  docker restart open-webui >/dev/null; echo -n "  restarted open-webui, waiting for health"
  for i in $(seq 1 45); do
    [[ $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/health || true) == 200 ]] && break
    echo -n "."; sleep 2
  done; echo
  trap - ERR

  echo; echo "=== Checks ==="
  echo "  Laravel /up:                 HTTP $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8001/up || true)"
  echo "  Gate v2 /gate/up:            HTTP $(curl -s -o /dev/null -w '%{http_code}' --resolve chat.clinicalguidelines.io:443:127.0.0.1 https://chat.clinicalguidelines.io/gate/up || true)"
  echo "  OpenWebUI /health:           HTTP $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/health || true)"
  echo "  old key left in Laravel .env: $(grep -cF -- "$OLD" "$PROD/.env" || true)"
  echo "  old key left in tenant_llm:   $(printf "SELECT COUNT(*) FROM tenant_llm WHERE api_key='%s';\n" "$OLD" | mysql_q)"
  echo "  old key left in instances:    $(printf "SELECT COUNT(*) FROM tenant_model_instance WHERE api_key='%s';\n" "$OLD" | mysql_q)"
  echo "  old key left in OpenWebUI:    $(printf '%s\n' "$OLD" | docker exec -i open-webui python3 -c "$webui_py_count")"
  echo "  new key in tenant_llm:        $(printf "SELECT COUNT(*) FROM tenant_llm WHERE api_key='%s';\n" "$NEW" | mysql_q) rows"
  echo "  new key in OpenWebUI:         $(printf '%s\n' "$NEW" | docker exec -i open-webui python3 -c "$webui_py_count") value(s)"
  s=$(key_status "$OLD")
  echo
  if [[ $s == REVOKED ]]; then echo "Old key $(l4 "$OLD") is REVOKED. Good."
  else echo "WARNING: old key $(l4 "$OLD") is still $s - revoke it now at platform.openai.com -> API keys."; fi
  echo "Done. Ask one question in OpenWebUI (model 'ESVS expert') to confirm answers and retrieval work end to end."
  ;;
*) echo "unknown mode $MODE"; exit 2;;
esac
