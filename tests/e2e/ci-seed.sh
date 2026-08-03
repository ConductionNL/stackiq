#!/usr/bin/env bash
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V.
#
# Provision SoftwareCatalog's OpenRegister registers + schemas on a freshly
# installed Nextcloud, for the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/softwarecatalog/tests/e2e/ci-seed.sh'
#
# WHY THIS IS NEEDED
# ------------------
# `occ app:enable softwarecatalog` runs the `InitializeSettings` post-migration
# repair step, which is supposed to import `lib/Settings/softwarecatalogus_register.json`
# into OpenRegister. Two things make that unreliable as the sole fresh-install
# path, and BOTH fail silently:
#
#   1. An IRepairStep runs with NO user session. OpenRegister's RBAC evaluates
#      the acting user, so the import can be denied outright — and
#      `InitializeSettings::run()` catches `\Exception` and downgrades it to a
#      warning. `occ app:enable` still exits 0.
#   2. The non-forced import path is version-guarded: it can advance the
#      recorded configuration version WITHOUT applying the register, so a
#      second run then sees "already current" and does nothing either.
#
# Either way the app enables cleanly, the SPA boots, and the `voorzieningen`
# register simply is not there. The e2e suite's failure mode in that state is a
# wall of `voorzieningen/config returned 500` / `voorzieningen register not
# configured` — messages that point at the fixtures, not at the missing import.
#
# So this script does the import EXPLICITLY through the admin HTTP API (which
# has a real session and passes RBAC), with `force: true` to defeat the version
# guard, and then VERIFIES the registers, the schemas, AND the app-level
# register/schema mapping the fixtures actually resolve by. A failed provision
# becomes one loud step failure here instead of ~20 misleading spec failures.
#
# It is idempotent: the import is idempotent server-side, and re-running only
# re-verifies.

set -euo pipefail

# ── Target resolution ────────────────────────────────────────────────────────
# The shared workflow's "Seed test data" step exports BASE_URL / NEXTCLOUD_URL /
# ADMIN_USER / ADMIN_PASSWORD, but accept the runner's own `php -S 0.0.0.0:8080`
# as a fallback in case a caller wires this differently.
#
# That fallback is gated on actually being in CI. On a developer box
# `localhost:8080` is the SHARED dev container, and this script performs ADMIN
# WRITES with `force: true` — it must never silently reimport a register into
# somebody else's environment. Off CI, an unset target is a hard error.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[ci-seed] target: ${BASE}"

# ── 1. Import the SoftwareCatalog configuration ──────────────────────────────
# `settings#manualImport` is admin-only (no @NoAdminRequired) and
# @NoCSRFRequired, so basic auth is sufficient. `force` is compared with
# `=== true` in SettingsController::manualImport(), so it must arrive as a JSON
# boolean — a form-encoded "true" is the *string* "true" and would be ignored,
# silently giving us the version-guarded path this script exists to bypass.
IMPORT_URL="${BASE}/index.php/apps/softwarecatalog/api/settings/import"
echo "[ci-seed] POST ${IMPORT_URL} (force: true)"

IMPORT_BODY="$(mktemp)"
IMPORT_CODE="$(
	curl -sS -o "$IMPORT_BODY" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data '{"force":true}' \
		"$IMPORT_URL" || echo 000
)"

echo "[ci-seed] import HTTP ${IMPORT_CODE}"
head -c 3000 "$IMPORT_BODY"; echo

if [ "$IMPORT_CODE" != "200" ]; then
	echo "::error::SoftwareCatalog configuration import failed (HTTP ${IMPORT_CODE}). The e2e suite cannot resolve the voorzieningen register without it."
	exit 1
fi

# ── 2. Verify the registers and schemas are actually there ───────────────────
# The import reporting success is not the same as the register existing —
# verify against OpenRegister directly, using the slugs declared in
# lib/Settings/softwarecatalogus_register.json and consumed by the e2e
# fixtures (tests/e2e/workflows/_fixtures.ts).
#
# ⚠️ Before believing a zero, prove the query can match: the check below prints
# every slug it DID see, so "missing" can never be confused with "the endpoint
# returned nothing / did not return JSON at all" — the two are distinguished
# explicitly and reported differently.
verify() {
	python3 - "$1" "$2" <<'PY'
import json, sys
path, kind = sys.argv[1], sys.argv[2]
required = {
    'registers': ['voorzieningen'],
    # The schemas the e2e fixtures create/read through, per _fixtures.ts
    # (organisatie, contactpersoon, module, contract, moduleVersie) plus the
    # ones the spec-coverage index pages render.
    'schemas': [
        'organisatie', 'contactpersoon', 'module', 'contract',
        'moduleVersie', 'kwetsbaarheid', 'compliancy', 'suite',
    ],
}[kind]
with open(path) as fh:
    raw = fh.read()
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::{kind} endpoint did not return JSON. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
items = body if isinstance(body, list) else body.get('results', [])
slugs = {i.get('slug') for i in items if isinstance(i, dict)}
if not slugs:
    print(f'::error::{kind} endpoint returned ZERO items — the query itself may be wrong.')
    print(raw[:500])
    sys.exit(1)
missing = [s for s in required if s not in slugs]
print(f'[ci-seed] {kind} present ({len(slugs)}): {sorted(s for s in slugs if s)}')
if missing:
    print(f'::error::SoftwareCatalog {kind} missing after import: {missing}')
    sys.exit(1)
print(f'[ci-seed] {kind} OK ({len(required)} required slugs present)')
PY
}

REG_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=300" -o "$REG_BODY"
verify "$REG_BODY" registers

SCH_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/schemas?_limit=1000" -o "$SCH_BODY"
verify "$SCH_BODY" schemas

# ── 3. Verify the APP-LEVEL mapping, not just OpenRegister ───────────────────
# The register existing in OpenRegister and softwarecatalog KNOWING about it are
# two different facts. `tests/e2e/workflows/_fixtures.ts` resolves the register
# id and every schema id from `GET /api/voorzieningen/config`, which reads the
# `voorzieningen_config` app-config value written by the auto-configure pass of
# the import. If that pass silently no-ops, OpenRegister looks perfectly seeded
# and every fixture still dies on "voorzieningen register not configured".
CFG_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/softwarecatalog/api/voorzieningen/config" -o "$CFG_BODY"

python3 - "$CFG_BODY" <<'PY'
import json, sys
with open(sys.argv[1]) as fh:
    raw = fh.read()
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print('::error::voorzieningen/config did not return JSON. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
config = (body or {}).get('config') or {}
print(f'[ci-seed] voorzieningen config: {json.dumps(config)[:800]}')
missing = [k for k in ('register', 'organisatie_schema', 'contactpersoon_schema',
                       'module_schema', 'contract_schema') if not config.get(k)]
if missing:
    print(f'::error::softwarecatalog has no register/schema mapping for: {missing}')
    print('::error::OpenRegister may be seeded, but the app cannot resolve it — '
          'every e2e fixture would fail with "voorzieningen register not configured".')
    sys.exit(1)
print('[ci-seed] app-level voorzieningen mapping OK.')
PY

echo "[ci-seed] SoftwareCatalog registers + schemas provisioned."

# ── 4. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# The shared workflow serves Nextcloud with `php -S 0.0.0.0:8080`. It now sets
# PHP_CLI_SERVER_WORKERS: 8, but the first hit still pays a cold opcache and the
# first parse of a multi-megabyte webpack bundle, and the effect lands entirely
# on whichever spec happens to run first.
#
# Warm it here, in the environment-preparation step where it belongs. The
# alternative — raising the first spec's timeout — would hide the cold start
# inside an assertion and keep drifting upward. Failures are ignored on
# purpose: this is a warm-up, not a gate. The real checks are above and below.
for path in \
	"/index.php/apps/softwarecatalog/" \
	"/index.php/apps/softwarecatalog/api/settings" \
	"/index.php/apps/softwarecatalog/api/voorzieningen/config" \
	"/index.php/apps/openregister/api/registers?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# Pull the main webpack bundle once so it is in the page cache.
#
# Do NOT hardcode the URL. Nextcloud serves an app's assets from whichever apps
# directory it was installed into — `/apps/<app>/js/...` on the CI runner,
# `/custom_apps/<app>/js/...` in the docker dev images — and asking for the
# wrong one does not 404. It returns **HTTP 200 with `text/html`**: the NC error
# page, served through index.php. A status-code check therefore reports success
# while fetching a 40 KB HTML page instead of a multi-MB bundle, so the warm-up
# silently warms nothing.
#
# Read the real src out of the rendered app page instead, and verify the
# response is actually JavaScript.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/softwarecatalog/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that aborts the script right here — so the case the gate
# below exists to explain (no bundle) would die with a bare non-zero exit and
# none of the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*softwarecatalog-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app — and the environment hides it well: when the
# bundle is absent, Nextcloud does not 404. It serves its HTML error page with
# **HTTP 200 and Content-Type text/html**, so `npm run build` producing nothing
# looks, to every status-code check in the pipeline, exactly like success.
#
# The specs are the honest signal (the `smoke` project asserts the app actually
# MOUNTS); this check just makes the cause loud and immediate instead of
# arriving as a wall of selector timeouts.
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle verified as JavaScript."
			;;
		*)
			echo "::error::The SoftwareCatalog frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac
fi

echo "[ci-seed] done."

# ═══════════════════════════════════════════════════════════════════════════
# TEMPORARY POSITIVE CONTROL — REVERT THIS BLOCK BEFORE MERGING.
#
# A green e2e job only means something if the specs would go RED when the app
# is broken. This truncates the webpack bundle AFTER the gate above has already
# verified and warmed the real one, so the gate still passes on its own terms
# and the failure lands squarely on the specs.
#
# TRUNCATE, do not delete: a delete-based control is defeated by any
# `fs.existsSync()` self-heal in globalSetup (that is how a sibling repo got
# 82/82 green with the bundle "removed"). softwarecatalog has no such self-heal,
# but truncation is used anyway so the evidence is comparable across repos.
# ═══════════════════════════════════════════════════════════════════════════
CONTROL_BUNDLE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/js/softwarecatalog-main.js"
echo "[ci-seed][CONTROL] bundle path: ${CONTROL_BUNDLE}"
echo "[ci-seed][CONTROL] bytes BEFORE: $(stat -c %s "$CONTROL_BUNDLE")"
printf '/* truncated by the e2e positive control */\n' > "$CONTROL_BUNDLE"
echo "[ci-seed][CONTROL] bytes AFTER:  $(stat -c %s "$CONTROL_BUNDLE")"
