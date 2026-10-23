#!/usr/bin/env bash
# Rotate the Cohere API key (reranking) on the Hetzner server.
#
# The key is typed at a hidden prompt and sent to the server over SSH stdin only:
# never on a command line, in shell history, in a log, or in a temp file.
#
# What it updates (all of them, or nothing if validation fails):
#   1. RAGFlow MySQL rag_flow.tenant_llm  every row holding the old Cohere key (live reranking)
#   2. /opt/cg/laravel/app/.env           BRIDGE_RERANK_API_KEY (Laravel-side reranker, standby)
#   3. /opt/cg/gate-v2/.env               BRIDGE_RERANK_API_KEY
#
# Usage:
#   bash rotate_cohere_key.sh                  # validate, show plan, ask, apply
#   bash rotate_cohere_key.sh --validate-only  # only test the new key
set -euo pipefail

HOST=${HOST:-root@178.105.193.206}
SSH_KEY=${SSH_KEY:-$HOME/.ssh/id_ed25519}
SSH=(ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$HOST")
REMOTE=/root/.rotate_cohere_key_remote.sh

VALIDATE_ONLY=0
[[ ${1:-} == --validate-only ]] && VALIDATE_ONLY=1

if [[ -t 0 ]]; then
  read -rsp "Paste the NEW Cohere API key (input hidden): " NEW; echo
else
  IFS= read -r NEW
fi
NEW=${NEW//[$'\r\n\t ']/}
if [[ ! $NEW =~ ^[A-Za-z0-9_-]{30,120}$ ]]; then
  echo "That does not look like a Cohere API key." >&2; exit 2
fi
echo "New key: ...${NEW: -4}"

sed -n '/^__REMOTE__$/,$p' "$0" | tail -n +2 | "${SSH[@]}" "umask 077; cat > $REMOTE"
trap '"${SSH[@]}" "rm -f $REMOTE" >/dev/null 2>&1 || true' EXIT

echo; echo "=== Validating on the server (nothing is changed in this step) ==="
if ! printf '%s\n' "$NEW" | "${SSH[@]}" "bash $REMOTE validate"; then
  echo; echo "Validation failed - nothing was changed." >&2; exit 1
fi

if (( VALIDATE_ONLY )); then echo; echo "--validate-only: stopping here, nothing changed."; exit 0; fi
if [[ ! -t 0 ]]; then echo "Not a terminal: refusing to apply without confirmation." >&2; exit 1; fi

echo
read -rp "Apply the new Cohere key everywhere listed above and restart PHP-FPM? Type APPLY: " ok
[[ $ok == APPLY ]] || { echo "Aborted - nothing changed."; exit 1; }

echo; echo "=== Applying ==="
printf '%s\n' "$NEW" | "${SSH[@]}" "bash $REMOTE apply"
unset NEW
exit 0

__REMOTE__
#!/usr/bin/env bash
# Runs on the server as root. Reads the new key from stdin. Arg: validate|apply
set -euo pipefail
MODE=${1:?mode}
PROD=/opt/cg/laravel/app
GV2=/opt/cg/gate-v2
KEYRE='^[A-Za-z0-9_-]{30,120}$'
VAR=BRIDGE_RERANK_API_KEY

IFS= read -r NEW || true
NEW=${NEW//[$'\r\n\t ']/}
[[ $NEW =~ $KEYRE ]] || { echo "ERROR: new key has an unexpected format"; exit 2; }

l4() { printf '...%s' "${1: -4}"; }
mysql_q() { docker exec -i docker-mysql-1 sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B rag_flow' 2> >(grep -v 'Using a password' >&2 || true); }

OLD=$(echo "SELECT DISTINCT api_key FROM tenant_llm WHERE llm_factory='Cohere' AND api_key<>'';" | mysql_q)
[[ $(printf '%s\n' "$OLD" | grep -c .) == 1 ]] || { echo "ERROR: expected exactly one Cohere key in RAGFlow, found: $(printf '%s\n' "$OLD" | grep -c .)"; exit 2; }
[[ $OLD =~ $KEYRE ]] || { echo "ERROR: stored Cohere key has an unexpected format"; exit 2; }
[[ $NEW != "$OLD" ]] || { echo "ERROR: that is the CURRENT (compromised) key - create a new one first"; exit 2; }

T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
api() {  # api KEY METHOD PATH [JSON] -> HTTP status; body in $T/body. Auth header via stdin.
  local args=(-sS -o "$T/body" -w '%{http_code}' --max-time 60 -X "$2" -H @- -H 'Content-Type: application/json')
  [[ -n ${4:-} ]] && args+=(--data-binary "$4")
  printf 'Authorization: Bearer %s\n' "$1" | curl "${args[@]}" "https://api.cohere.com$3" 2>/dev/null || true
}
errmsg() { python3 -c 'import json,sys
try: j=json.load(open(sys.argv[1])); print(str(j.get("message") or j)[:160])
except Exception: print("(no error message)")' "$T/body"; }
key_status() {
  local c; c=$(api "$1" POST /v1/check-api-key)
  if [[ $c == 200 ]] && python3 -c 'import json,sys; sys.exit(0 if json.load(open(sys.argv[1])).get("valid") else 1)' "$T/body" 2>/dev/null; then echo ACTIVE
  elif [[ $c == 401 || $c == 200 ]]; then echo REVOKED
  else echo "UNKNOWN(HTTP $c)"; fi
}

env_has_old() { [[ -f $1 ]] && grep -qxF -- "$VAR=$OLD" "$1"; }

inventory() {
  echo "Where the current Cohere key $(l4 "$OLD") lives:"
  echo "  RAGFlow tenant_llm rows:      $(printf "SELECT COUNT(*) FROM tenant_llm WHERE api_key='%s';\n" "$OLD" | mysql_q)"
  echo "  RAGFlow instance rows:        $(printf "SELECT COUNT(*) FROM tenant_model_instance WHERE api_key='%s';\n" "$OLD" | mysql_q 2>/dev/null || echo n/a)"
  for f in "$PROD/.env" "$GV2/.env"; do
    echo "  $f $VAR: $(env_has_old "$f" && echo 'old key -> will be replaced' || echo 'not the old key -> left alone')"
  done
}

validate_new() {
  local c fail=0
  echo "Testing the NEW key $(l4 "$NEW") from this server:"
  c=$(api "$NEW" POST /v1/check-api-key)
  if [[ $c == 200 ]] && python3 -c 'import json,sys; sys.exit(0 if json.load(open(sys.argv[1])).get("valid") else 1)' "$T/body"; then echo "  OK    key is valid"
  else echo "  FAIL  check-api-key -> HTTP $c: $(errmsg)"; fail=1; fi
  for m in rerank-english-v3.0; do
    c=$(api "$NEW" POST /v2/rerank "{\"model\":\"$m\",\"query\":\"abdominal aortic aneurysm repair threshold\",\"documents\":[\"Elective repair is recommended at 5.5 cm in men.\",\"Varicose veins are treated with ablation.\"],\"top_n\":1}")
    if [[ $c == 200 ]]; then echo "  OK    rerank $m"; else echo "  FAIL  rerank $m -> HTTP $c: $(errmsg)"; fail=1; fi
  done
  return $fail
}

set_env_var() {  # set_env_var FILE  (new key on stdin) - only if the file holds the old key; keeps owner/mode
  python3 -c 'import sys,re
p,var,old=sys.argv[1],sys.argv[2],sys.argv[3]; new=sys.stdin.readline().strip(); s=open(p).read()
s2,n=re.subn(r"(?m)^"+re.escape(var+"="+old)+r"$", lambda m: var+"="+new, s)
if n!=1: sys.exit(f"expected exactly one {var}=<old key> line in {p}, found {n}")
open(p,"w").write(s2)' "$1" "$VAR" "$OLD" <<<"$NEW"
}

recache() {  # re-cache Laravel config as www-data (the PHP-FPM user)
  local d=$1 f=$1/bootstrap/cache/config.php
  [[ -f $f ]] || { echo "  $d: config not cached, nothing to do"; return; }
  runuser -u www-data -- test -r "$d/.env" || chgrp www-data "$d/.env"
  (cd "$d" && runuser -u www-data -- sh -c 'umask 027; php artisan config:cache -q')
  [[ -f $f ]] || { echo "  ERROR: $f was not rebuilt"; return 1; }
  echo "  $d: config re-cached as www-data"
}

case $MODE in
validate)
  inventory
  echo; echo "Current Cohere key $(l4 "$OLD") is: $(key_status "$OLD")"
  echo; validate_new
  echo; echo "Validation passed."
  ;;

apply)
  validate_new >/dev/null || { echo "ERROR: new key failed validation - nothing changed"; exit 1; }
  umask 077
  TS=$(date +%Y%m%d_%H%M%S); BK=/root/cohere_key_rotation_$TS
  mkdir -p "$BK"
  cat > "$BK/ROLLBACK.txt" <<EOF
To put the previous key back (run as root on this server):
  cp -a $BK/prod.env $PROD/.env
  cp -a $BK/gate-v2.env $GV2/.env
  docker exec -i docker-mysql-1 sh -c 'mysql -uroot -p"\$MYSQL_ROOT_PASSWORD" rag_flow' < $BK/tenant_llm.sql
  for d in $PROD $GV2; do (cd \$d && runuser -u www-data -- sh -c 'umask 027; php artisan config:cache'); done
  systemctl restart php8.5-fpm.service
EOF
  STEP=start
  trap 'echo; echo "FAILED during step: $STEP"; cat "$BK/ROLLBACK.txt"' ERR

  STEP=backups
  cp -a "$PROD/.env" "$BK/prod.env"; cp -a "$GV2/.env" "$BK/gate-v2.env"
  docker exec docker-mysql-1 sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction rag_flow tenant_llm' > "$BK/tenant_llm.sql" 2>/dev/null
  [[ -s $BK/tenant_llm.sql ]]
  echo "  backups: $BK  (they contain the OLD key - delete once you trust the new one)"

  STEP="RAGFlow tenant_llm"
  n=$(printf "UPDATE tenant_llm SET api_key='%s' WHERE api_key='%s'; SELECT ROW_COUNT();\n" "$NEW" "$OLD" | mysql_q)
  (( n > 0 )); echo "  updated $n RAGFlow tenant_llm rows"
  # RAGFlow >= 0.27 resolves keys from tenant_model_instance, not tenant_llm.
  if [[ $(echo "SHOW TABLES LIKE 'tenant_model_instance';" | mysql_q) == tenant_model_instance ]]; then
    n=$(printf "UPDATE tenant_model_instance SET api_key='%s' WHERE api_key='%s'; SELECT ROW_COUNT();\n" "$NEW" "$OLD" | mysql_q)
    (( n > 0 )); echo "  updated $n RAGFlow tenant_model_instance row(s)"
  fi

  for f in "$PROD/.env" "$GV2/.env"; do
    STEP="$f"
    if env_has_old "$f"; then set_env_var "$f"; echo "  updated $VAR in $f"; fi
  done

  STEP="re-cache Laravel config"
  umask 022
  recache "$PROD"; recache "$GV2"

  STEP="restart php-fpm"
  systemctl restart php8.5-fpm.service; echo "  restarted php8.5-fpm"; sleep 2
  trap - ERR

  echo; echo "=== Checks ==="
  echo "  Laravel /up:                HTTP $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8001/up || true)"
  echo "  Gate v2 /gate/up:           HTTP $(curl -s -o /dev/null -w '%{http_code}' --resolve chat.clinicalguidelines.io:443:127.0.0.1 https://chat.clinicalguidelines.io/gate/up || true)"
  echo "  old key left in tenant_llm:  $(printf "SELECT COUNT(*) FROM tenant_llm WHERE api_key='%s';\n" "$OLD" | mysql_q)"
  echo "  old key left in instances:   $(printf "SELECT COUNT(*) FROM tenant_model_instance WHERE api_key='%s';\n" "$OLD" | mysql_q)"
  echo "  old key left in .env files:  $(cat "$PROD/.env" "$GV2/.env" | grep -cF -- "$OLD" || true)"
  echo "  new key in tenant_llm:       $(printf "SELECT COUNT(*) FROM tenant_llm WHERE api_key='%s';\n" "$NEW" | mysql_q) rows"
  s=$(key_status "$OLD")
  echo
  if [[ $s == REVOKED ]]; then echo "Old key $(l4 "$OLD") is REVOKED. Good."
  else echo "WARNING: old key $(l4 "$OLD") is still $s - revoke it at dashboard.cohere.com -> API keys."; fi
  echo "Done. Ask one question in OpenWebUI (model 'ESVS expert') to confirm retrieval works end to end."
  ;;
*) echo "unknown mode $MODE"; exit 2;;
esac
