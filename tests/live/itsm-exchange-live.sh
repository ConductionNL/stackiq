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
#        CONTAINER (rdam2-nextcloud).
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
mock() { docker exec "$CONTAINER" curl -s "$@"; }
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
SRC=$(api "$OR/objects/integriq/source?slug=$DESK" | python3 -c 'import sys,json;r=json.load(sys.stdin)["results"];print(r[0]["@self"]["id"] if r else "")')
[ -n "$SRC" ] || { bad "integriq has no $DESK source"; exit 1; }
api -X PATCH "$OR/objects/integriq/source/$SRC" -d "{\"location\":\"$LOCATION\",\"isEnabled\":true,\"auth\":\"basic\",\"configuration\":{\"authentication\":{\"username\":\"stackiq\",\"password\":{\"credentialRef\":{\"credentialName\":\"$CRED\"}}}}}" >/dev/null && ok "source $SRC"

say "set up the exchange"
ORG=$(api "$OR/objects/stackiq/organization?type=Municipality&_limit=1" | python3 -c 'import sys,json;print(json.load(sys.stdin)["results"][0]["@self"]["id"])')
SETUP=$(api -X POST "$SQ/itsm/setup" -d "{\"desk\":\"$DESK\",\"organisation\":\"$ORG\",\"templateId\":\"tpl-application\"}")
echo "$SETUP" | python3 -c 'import sys,json;d=json.load(sys.stdin);print("   created:",d.get("created"),d.get("message",""));[print("  ",k,v) for k,v in (d.get("blocking") or {}).items()]'
echo "$SETUP" | grep -q '"created":true' || { bad "set-up refused"; exit 1; }
flow() { echo "$SETUP" | python3 -c "import sys,json;print(json.load(sys.stdin)['flows']['$1'])"; }

say "first import"
for f in applications relations licences contracts; do echo "   $f: $(run_flow "$(flow $f)")"; done
U1=$(count usage); C1=$(count connection); K1=$(count catalogContract)
[ "$U1" -gt 0 ] && ok "usages created: $U1" || bad "no usages created"
echo "   connections $C1, contracts $K1"

say "second import updates, never duplicates"
for f in applications relations licences contracts; do echo "   $f: $(run_flow "$(flow $f)")"; done
[ "$(count usage)" = "$U1" ] && ok "usages still $U1" || bad "usages now $(count usage)"
[ "$(count connection)" = "$C1" ] && ok "connections still $C1" || bad "connections now $(count connection)"
[ "$(count catalogContract)" = "$K1" ] && ok "contracts still $K1" || bad "contracts now $(count catalogContract)"

say "no echo: the imports made no call to the desk"
CALLS0=$(mock_calls)
[ "$CALLS0" = 0 ] && ok "0 writes on the mock" || bad "$CALLS0 writes on the mock after imports only"

say "a stackiq-owned change reaches the desk once"
USAGE=$(api "$OR/objects/stackiq/usage?serviceDeskSystem=$DESK&_limit=1" | python3 -c 'import sys,json;r=json.load(sys.stdin)["results"][0];print(r["@self"]["id"]+" "+r["serviceDeskRecordId"])')
UID_=${USAGE% *}; RID=${USAGE#* }
api -X PATCH "$OR/objects/stackiq/usage/$UID_" -d '{"timeClassification":"Invest"}' >/dev/null
docker exec -u www-data "$CONTAINER" php -f /var/www/html/cron.php >/dev/null 2>&1 || true
CALLS1=$(mock_calls)
[ "$CALLS1" = 1 ] && ok "1 write on the mock for record $RID" || bad "$CALLS1 writes on the mock"

say "the import after the export writes nothing back and calls nothing"
run_flow "$(flow applications)" >/dev/null
docker exec -u www-data "$CONTAINER" php -f /var/www/html/cron.php >/dev/null 2>&1 || true
[ "$(mock_calls)" = "$CALLS1" ] && ok "still $CALLS1 write" || bad "$(mock_calls) writes: ping-pong"

say "conflict: each owner keeps its field"
if [ "$DESK" = topdesk ]; then mock -X POST "$MOCK/__set/$RID" -d '{"name":"Renamed in the desk"}' >/dev/null; else mock -X POST "$MOCK/__set/cmdb_ci_appl/$RID" -d '{"name":"Renamed in the desk"}' >/dev/null; fi
api -X PATCH "$OR/objects/stackiq/usage/$UID_" -d '{"timeClassification":"Migrate"}' >/dev/null
docker exec -u www-data "$CONTAINER" php -f /var/www/html/cron.php >/dev/null 2>&1 || true
run_flow "$(flow applications)" >/dev/null
AFTER=$(api "$OR/objects/stackiq/usage/$UID_")
MOD=$(echo "$AFTER" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("module"))')
NAME=$(api "$OR/objects/stackiq/module/$MOD" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("name"))')
TC=$(echo "$AFTER" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("timeClassification"))')
[ "$NAME" = "Renamed in the desk" ] && ok "name from the desk: $NAME" || bad "name is $NAME"
[ "$TC" = Migrate ] && ok "TIME class from stackiq: $TC" || bad "TIME class is $TC"

say "result"
[ $FAIL = 0 ] && echo "   ALL PASSED ($DESK)" || echo "   FAILURES ($DESK)"
exit $FAIL
