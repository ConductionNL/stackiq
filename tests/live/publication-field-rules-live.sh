#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V.
#
# Live check of openspec/changes/publication-field-rules: what an anonymous
# reader gets from OpenRegister's object API for stackiq's schemas.
# It proves the rules on the object body. OpenRegister's own leaks through
# @self.relations, @self.description and explicit _facets are lane or-gh's.
#
# Usage: NC=http://localhost tests/live/publication-field-rules-live.sh
# (inside the Nextcloud container when the host port does not answer).
# It creates its own objects, named "Publication check <stamp>".

set -euo pipefail

NC="${NC:-http://localhost:8096}"
AUTH="${AUTH:-admin:admin}"
OR="$NC/index.php/apps/openregister/api/objects/stackiq"
FAIL=0
STAMP=$(date +%s)

ok() { printf '   ok    %s\n' "$*"; }
bad() { printf '   FAIL  %s\n' "$*"; FAIL=1; }
admin() { curl -s -u "$AUTH" -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' "$@"; }
anon() { curl -s -H 'Accept: application/json' "$@"; }
id_of() { python3 -c 'import sys,json;print(json.load(sys.stdin)["@self"]["id"])'; }
has() { python3 -c "import sys,json;d=json.load(sys.stdin);print('yes' if d.get('$1') not in (None,'',[]) else 'no')"; }
listed() { python3 -c "import sys,json;d=json.load(sys.stdin);print('yes' if any(r.get('@self',{}).get('id')=='$1' for r in d.get('results',[])) else 'no')"; }

PAST="2026-01-01T00:00:00+00:00"
echo "== create a published and an unpublished application, each with one version"
SUP=$(admin -X POST "$OR/organization" -d "{\"name\":\"Publication check $STAMP supplier\",\"type\":\"Supplier\"}" | id_of)
MUN=$(admin -X POST "$OR/organization" -d "{\"name\":\"Publication check $STAMP municipality\",\"type\":\"Municipality\"}" | id_of)
MA=$(admin -X POST "$OR/module" -d "{\"name\":\"Publication check $STAMP published\",\"provider\":\"$SUP\",\"registeredBy\":\"Municipality\",\"publicationDate\":\"$PAST\",\"dpiaDocumentRef\":\"/DPIA/secret.pdf\",\"verwerkingsregisterRef\":\"VR-77\"}" | id_of)
MB=$(admin -X POST "$OR/module" -d "{\"name\":\"Publication check $STAMP unpublished\",\"provider\":\"$SUP\",\"registeredBy\":\"Municipality\"}" | id_of)
VA=$(admin -X POST "$OR/moduleVersion" -d "{\"module\":\"$MA\",\"version\":\"1.0\"}" | id_of)
VB=$(admin -X POST "$OR/moduleVersion" -d "{\"module\":\"$MB\",\"version\":\"1.0\"}" | id_of)

echo "== the version carries its application's publication"
[ "$(admin "$OR/moduleVersion/$VA" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("modulePublicationDate") or "")')" != "" ] && ok "published application's version holds its date" || bad "the version of the published application has no date"

echo "== an anonymous reader gets the published application without its private fields"
BODY=$(anon "$OR/module/$MA")
[ "$(echo "$BODY" | has name)" = yes ] && ok "name is there" || bad "no name: $(echo "$BODY" | head -c 200)"
[ "$(echo "$BODY" | has dpiaDocumentRef)" = no ] && ok "dpiaDocumentRef is not" || bad "dpiaDocumentRef leaks"
[ "$(echo "$BODY" | has verwerkingsregisterRef)" = no ] && ok "verwerkingsregisterRef is not" || bad "verwerkingsregisterRef leaks"
[ "$(admin "$OR/module/$MA" | has dpiaDocumentRef)" = yes ] && ok "a signed-in user still reads dpiaDocumentRef" || bad "the admin lost dpiaDocumentRef"

echo "== an anonymous reader gets the version of the published application only"
VERS=$(anon "$OR/moduleVersion?_limit=500")
[ "$(echo "$VERS" | listed "$VA")" = yes ] && ok "version of the published application is listed" || bad "version of the published application is missing"
[ "$(echo "$VERS" | listed "$VB")" = no ] && ok "version of the unpublished application is not" || bad "version of the unpublished application leaks"

echo "== publishing the application publishes its version"
admin -X PATCH "$OR/module/$MB" -d "{\"publicationDate\":\"$PAST\"}" >/dev/null
[ "$(anon "$OR/moduleVersion?_limit=500" | listed "$VB")" = yes ] && ok "now listed" || bad "still hidden after publishing its application"

echo "== an application in use is public only from its publication date, without its internal fields"
UP=$(admin -X POST "$OR/usage" -d "{\"module\":\"$MA\",\"consumer\":\"$MUN\",\"status\":\"In production\",\"publicationDate\":\"$PAST\",\"timeClassification\":\"Invest\",\"installedVersion\":\"1.0\"}" | id_of)
UN=$(admin -X POST "$OR/usage" -d "{\"module\":\"$MB\",\"consumer\":\"$MUN\",\"status\":\"Planned\",\"timeClassification\":\"Tolerate\"}" | id_of)
USES=$(anon "$OR/usage?_limit=500")
[ "$(echo "$USES" | listed "$UP")" = yes ] && ok "the published usage is listed" || bad "the published usage is missing"
[ "$(echo "$USES" | listed "$UN")" = no ] && ok "the unpublished usage is not" || bad "the unpublished usage leaks"
UBODY=$(anon "$OR/usage/$UP")
[ "$(echo "$UBODY" | has status)" = yes ] && ok "status is there" || bad "no status"
[ "$(echo "$UBODY" | has timeClassification)" = no ] && ok "timeClassification is not" || bad "timeClassification leaks"
[ "$(echo "$UBODY" | has installedVersion)" = no ] && ok "installedVersion is not" || bad "installedVersion leaks"

echo "== result"
[ $FAIL = 0 ] && echo "   ALL PASSED" || echo "   FAILURES"
exit $FAIL
