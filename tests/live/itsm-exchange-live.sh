#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V.
#
# Live run of the service desk exchange against integriq's TOPdesk or
# ServiceNow mock (openspec/changes/sharing-itsm-exchange, task 6).
#
# It points integriq's seeded source at the mock, stores the mock password
# in OpenRegister's credential broker, sets the exchange up through
# stackiq's own endpoint, runs the imports, and checks what the spec asks:
# the first import creates, the second updates, a stackiq change reaches the
# mock once, a conflict keeps each owner's field, and nothing echoes.
#
# Usage: tests/live/itsm-exchange-live.sh topdesk|servicenow
# Env:   NC (http://localhost:8096), AUTH (admin:admin), MOCK_HOST
#        (container name), MOCK_PORT (host port of the mock, for its
#        control endpoints, read through the Nextcloud container).
#        CONTAINER (rdam2-nextcloud). IN_CONTAINER=1 runs it inside the
#        Nextcloud container itself (the clone is mounted there), for when
#        the host port does not answer: NC=http://localhost.
#
# Test data only: the mock password is the mock's documented one.

set -euo pipefail

DESK="${1:?topdesk or servicenow}"
NC="${NC:-http://localhost:8096}"
AUTH="${AUTH:-admin:admin}"
CONTAINER="${CONTAINER:-rdam2-nextcloud}"
MOCK="http://rdam-mock-${DESK}:8080"
OR="$NC/index.php/apps/openregister/api"
SQ="$NC/index.php/apps/stackiq/api"
H=(-u "$AUTH" -H 'OCS-APIRequest: true' -H 'Accept: application/json')
FAIL=0

say() { printf '\n== %s\n' "$*"; }
ok() { printf '   ok    %s\n' "$*"; }
bad() { printf '   FAIL  %s\n' "$*"; FAIL=1; }
api() { curl -s "${H[@]}" -H 'Content-Type: application/json' "$@"; }
IN_CONTAINER="${IN_CONTAINER:-0}"
mock() { if [ "$IN_CONTAINER" = 1 ]; then curl -s "$@"; else docker exec "$CONTAINER" curl -s "$@"; fi; }
cron() { if [ "$IN_CONTAINER" = 1 ]; then su -s /bin/sh www-data -c 'php -f /var/www/html/cron.php' >/dev/null 2>&1 || true; else cron; fi; }
count() { api "$OR/objects/stackiq/$1?serviceDeskSystem=$DESK&_limit=500" | python3 -c 'import sys,json;print(len(json.load(sys.stdin).get("results",[])))'; }
mock_calls() { mock "$MOCK/__requests" | python3 -c "
import sys,json
log=json.load(sys.stdin)
print(sum(1 for r in log if r.get('method') in ('POST','PATCH','PUT') and '/__' not in r.get('path','')))"; }
run_flow() { api -X POST "$OR/flows/$1/run?sync=true" -d '{}' | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("status"), (d.get("error") or "")[:300])'; }

if [ "$DESK" = topdesk ]; then LOCATION="$MOCK/tas/api"; CRED=topdesk-application-password; else LOCATION="$MOCK"; CRED=servicenow-integration-password; fi

say "reset the mock"
mock -X POST "$MOCK/__reset" >/dev/null && ok "mock reset"

say "credential $CRED in the broker"
if api "$OR/credentials" | grep -q "\"$CRED\""; then ok "exists"; else
	api -X POST "$OR/credentials" -d "{\"name\":\"$CRED\",\"provider\":\"generic-basic\",\"secret\":\"mock-password\",\"allowedApps\":[\"integriq\"]}" >/dev/null && ok "created"
fi

say "point integriq's $DESK source at the mock"
SRC=$(api "$OR/objects/integriq/source?_limit=500" | python3 -c "import sys,json;r=[x for x in json.load(sys.stdin)['results'] if x['@self'].get('slug')=='$DESK'];print(r[0]['@self']['id'] if r else '')")
[ -n "$SRC" ] || { bad "integriq has no $DESK source"; exit 1; }
CONF=$(api "$OR/objects/integriq/source/$SRC" | python3 -c "
import sys,json
c=json.load(sys.stdin).get('configuration') or {}
a=c.setdefault('authentication',{})
a.setdefault('username','stackiq')
a['password']={'credentialRef':{'credentialName':'$CRED'}}
print(json.dumps({'location':'$LOCATION','isEnabled':True,'configuration':c}))")
api -X PATCH "$OR/objects/integriq/source/$SRC" -d "$CONF" >/dev/null && ok "source $SRC at $LOCATION, keeping the template's configuration"

say "set up the exchange"
ORG=$(api "$OR/objects/stackiq/organization?type=Municipality&_limit=1" | python3 -c 'import sys,json;print(json.load(sys.stdin)["results"][0]["@self"]["id"])')
SETUP=$(api -X POST "$SQ/itsm/setup" -d "{\"desk\":\"$DESK\",\"organisation\":\"$ORG\",\"templateId\":\"tpl-application\"}")
echo "$SETUP" | python3 -c 'import sys,json;d=json.load(sys.stdin);print("   created:",d.get("created"),d.get("message",""));[print("  ",k,v) for k,v in (d.get("blocking") or {}).items()]'
echo "$SETUP" | grep -q '"created":true' || { bad "set-up refused"; exit 1; }
flow() { echo "$SETUP" | python3 -c "import sys,json;print(json.load(sys.stdin)['flows']['$1'])"; }

say "first import"
for f in applications relations licences contracts; do echo "   $f: $(run_flow "$(flow $f)")"; done
cron; cron
U1=$(count usage); C1=$(count connection); K1=$(count catalogContract)
[ "$U1" -gt 0 ] && ok "usages created: $U1" || bad "no usages created"
[ "$C1" -gt 0 ] && ok "connections created: $C1" || bad "no connections created"
[ "$K1" -gt 0 ] && ok "licences and contracts created: $K1" || bad "no contracts created"
CALLS_A=$(mock_calls)
echo "   writes on the desk after the first import: $CALLS_A (stackiq-owned licence and contract fields reaching the application records)"

say "second import updates, never duplicates, and calls nothing"
for f in applications relations licences contracts; do echo "   $f: $(run_flow "$(flow $f)")"; done
cron; cron
[ "$(count usage)" = "$U1" ] && ok "usages still $U1" || bad "usages now $(count usage)"
[ "$(count connection)" = "$C1" ] && ok "connections still $C1" || bad "connections now $(count connection)"
[ "$(count catalogContract)" = "$K1" ] && ok "contracts still $K1" || bad "contracts now $(count catalogContract)"
[ "$(mock_calls)" = "$CALLS_A" ] && ok "no new write on the desk" || bad "$(mock_calls) writes, was $CALLS_A"

say "no write ever sends a field the desk owns"
OWNED_BY_DESK=$(mock "$MOCK/__requests" | python3 -c "
import sys,json
desk={'name','supplier','version','lifecycleStatus','vendor','install_status'}
hits=[k for r in json.load(sys.stdin) if r.get('method') in ('POST','PATCH','PUT') and '/__' not in r.get('path','') and '/assets/' in r.get('path','')+'/assets/' and isinstance(r.get('body'),dict) for k in r['body'] if k in desk]
print(len(hits))")
[ "$OWNED_BY_DESK" = 0 ] && ok "0 desk-owned fields sent on update" || bad "$OWNED_BY_DESK desk-owned fields sent"

say "a stackiq-owned change reaches the desk once"
USAGE=$(api "$OR/objects/stackiq/usage?serviceDeskSystem=$DESK&_limit=1" | python3 -c 'import sys,json;r=json.load(sys.stdin)["results"][0];print(r["@self"]["id"]+" "+r["serviceDeskRecordId"])')
UID_=${USAGE% *}; RID=${USAGE#* }
BEFORE=$(mock_calls)
api -X PATCH "$OR/objects/stackiq/usage/$UID_" -d '{"timeClassification":"Invest"}' >/dev/null
cron; cron
DELTA=$(( $(mock_calls) - BEFORE ))
[ "$DELTA" = 1 ] && ok "1 write for record $RID" || bad "$DELTA writes for one change"
mock "$MOCK/__requests" | grep -q '"Invest"' && ok "it carries the new TIME class" || bad "the new TIME class never reached the desk"

say "the import after the export writes nothing back and calls nothing"
AFTER_EXPORT=$(mock_calls)
run_flow "$(flow applications)" >/dev/null
cron; cron
[ "$(mock_calls)" = "$AFTER_EXPORT" ] && ok "still $AFTER_EXPORT writes: no ping-pong" || bad "$(mock_calls) writes: ping-pong"

say "conflict: each owner keeps its field"
if [ "$DESK" = topdesk ]; then mock -X POST "$MOCK/__set/$RID" -d '{"name":"Renamed in the desk"}' >/dev/null; else mock -X POST "$MOCK/__set/cmdb_ci_appl/$RID" -d '{"name":"Renamed in the desk"}' >/dev/null; fi
api -X PATCH "$OR/objects/stackiq/usage/$UID_" -d '{"timeClassification":"Migrate"}' >/dev/null
cron; cron
run_flow "$(flow applications)" >/dev/null
cron; cron
AFTER=$(api "$OR/objects/stackiq/usage/$UID_")
MOD=$(echo "$AFTER" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("module"))')
NAME=$(api "$OR/objects/stackiq/module/$MOD" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("name"))')
TC=$(echo "$AFTER" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("timeClassification"))')
[ "$NAME" = "Renamed in the desk" ] && ok "stackiq shows the desk's name: $NAME" || bad "name is $NAME"
[ "$TC" = Migrate ] && ok "stackiq keeps its TIME class: $TC" || bad "TIME class is $TC"
mock "$MOCK/__requests" | grep -q '"Migrate"' && ok "the desk got stackiq's TIME class" || bad "the desk never got Migrate"

say "result"
[ $FAIL = 0 ] && echo "   ALL PASSED ($DESK)" || echo "   FAILURES ($DESK)"
exit $FAIL
