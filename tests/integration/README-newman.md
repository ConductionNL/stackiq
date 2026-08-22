# Stackiq API-contract tests (Newman)

Newman/Postman contract tests that exercise stackiq's HTTP surface
directly, locking the API contract. Per the gate-19 split, **API/contract
correctness lives in Newman**; Playwright drives the UI only.

The suite is **self-contained and idempotent**: setup seeds the OpenRegister
objects it needs (an organisatie, a component, plus a dienst + gebruik so the
Contract folder has real FK targets) and teardown deletes everything created.

## What is covered

| Folder | Endpoints | Happy | Error (4xx, not 500) | Authz |
| --- | --- | --- | --- | --- |
| 0. Setup | OR object create (organisatie / component / dienst / gebruik) | seeds 4 objects + captures ids | — | — |
| 1. Organisatie CRUD | OR `/api/objects/{register}/{schema}` (ADR-022) | list (non-empty, @resolve) + create→read→update→delete | 404 unknown id | anon read (OR reads not auth-gated) |
| 2. Contactpersoon | OR object API + `GET /api/contactpersonen/organisation/{id}` | create→read→list-for-org→delete | — | 401 no-auth on the swc controller |
| 3. Component + Moduleversie | OR object API | list (non-empty) + create component + create linked moduleversie | 404 invalid schema | — |
| 4. Contract | OR object API | list + create (refs seeded dienst+gebruik) → read → delete | — | — |
| 5. Standaard | OR object API (`/standaard` slug) | — | **QUARANTINED 404** (schema not provisioned) | — |
| 6. Aanbod | `GET /api/aanbod` | 200 paginated envelope | — | public (`@PublicPage`) → 200 empty, not 401 |
| 7. ArchiMate / AMEF | `GET /api/archimate/export/organization/{uuid}`, `POST /api/archimate/export` | — | **QUARANTINED 500** (org export) + real **400** (bulk export, graceful) | 401 no-auth |
| 8. Settings & cronjobs | `GET /api/settings`, `/settings/stats`, `/voorzieningen/config`, `/settings/cronjobs`, `/settings/cronjobs/users` | settings/stats/config/cronjobs 200 | cronjobs/users **410 Gone** (deprecated-and-removed) | 401 no-auth on settings + cronjobs |
| 9. Dashboard & profile | `GET /api/dashboard`, `GET /api/me` | 200 + contract shape | — | 401 no-auth on me |
| 99. Teardown | OR object delete | idempotent cleanup of 4 seeded objects | — | — |

**46 requests / 82 assertions, all green** against the dev container
(localhost:8080, register 11).

## Phase-0 / Phase-3 facts locked

- **Lists return data** — organisatie and component lists assert a non-empty
  `results` array (the OR `@resolve` fix landed; ADR-022 register `voorzieningen` / id 11).
- **cronjob config returns 200** (not 500) — the deprecated-cronjob registration
  was fixed in Phase-0; `GET /api/settings/cronjobs` returns the active registry.
- **cronjob/users returns a clean 410 Gone** with a deprecation message — the
  per-user cronjob context was retired, and the endpoint degrades honestly
  rather than 500-ing.
- **Bulk ArchiMate export degrades gracefully** — `POST /api/archimate/export`
  returns **400** (not 500) when AMEF is unconfigured, with a `success:false`
  message naming the AMEF register.

## Known env gaps (quarantined — NOT fake passes)

Two cases assert the *current bad/missing* behaviour so the suite stays green
without faking a pass. Each flips RED the moment the gap is provisioned — that's
the signal to convert it to a happy-path assertion.

1. **Standaard schema not provisioned** (folder 5). stackiq's
   `voorzieningen_config` has no `standaard_schema` key and no `standaard`
   schema exists in register 11, so OR 404s the slug. The test asserts the 404.
2. **AMEF register not provisioned** (folder 7,
   `export/organization/{uuid}`). The instance has no AMEF register
   (`config amef = "AMEF register not found"`), so `exportOrgArchiMate`
   surfaces **HTTP 500** with `"AMEF register ID is not configured"`. The test
   asserts that 500 and that the message is the static config error (not a
   leaked stack trace). The *bulk* export endpoint (same gap) is a real
   assertion: it returns a graceful **400**, so it is not quarantined.

## Findings surfaced while authoring (env/schema realities, not app bugs)

- The **Organisatie** schema enforces a status lifecycle — `Actief → Inactief`
  is rejected with **422** (`lifecycle-invalid-transition`). The update test
  mutates `naam` only and keeps `status: Actief`.
- **Contactpersoon** requires the JSON-key `e-mailadres` (NOT NULL in the magic
  table); a missing value surfaces the DB error, so the seed sends it.
- **Contract** `dienst` and `gebruik` are NOT NULL relation columns, so the
  suite seeds a dienst (with `aanbieder` = the seeded org and `type` as an enum
  array) and a gebruik (with `afnemer` = the seeded org) and references them.
- **`GET /api/aanbod` is `@PublicPage`** — reachable unauthenticated by design
  (the public offer catalog). Without an org context it returns **200** with an
  empty result set, so the authz test asserts the public-but-empty contract
  rather than a 401.

## Running

```bash
# defaults: BASE_URL=http://localhost:8080, ADMIN_USER=admin, ADMIN_PASS=admin
./run-newman.sh

# or directly:
npx newman run stackiq.postman_collection.json \
  --env-var baseUrl=http://localhost:8080 \
  --env-var adminUser=admin \
  --env-var adminPass=admin
```

`run-newman.sh` prefers a globally-installed `newman`, falls back to
`npx newman`, and serialises runs under `flock /tmp/uiaudit-stackiq.lock`
to avoid tripping the Nextcloud brute-force protection when multiple agents run
in parallel.

## Auth-isolation detail (reusable fleet pattern)

Newman keeps a per-run cookie jar. Authenticated requests against `baseUrl`
(`localhost`) establish a host-scoped NC session cookie; because the jar is
shared, that cookie would silently authenticate the no-auth requests too (they
then return 200 instead of 401). Two measures keep the authorization tests
honest:

1. **Host split** — authenticated requests use `{{baseUrl}}`
   (`http://localhost:8080`); no-auth requests use `{{noAuthBase}}`
   (`http://127.0.0.1:8080`). NC session cookies are host-scoped, so the
   `localhost` session is never sent to `127.0.0.1`. `run-newman.sh` derives
   `noAuthBase` from `BASE_URL` automatically (override with `NO_AUTH_BASE`).
2. **`--ignore-redirects` + `Accept: application/json`** — unauthenticated
   requests get NC's JSON `401`, not the `303`→login-page `200` HTML that a
   browser `Accept` would follow.

### OpenRegister object reads are not auth-gated

CRUD is delegated to OpenRegister (ADR-022). OR's object-read API returns the
list/object to an **anonymous** request (`200`) — authorization on catalog data
is OR's responsibility, not stackiq's. The folder-1 anon test documents
this honestly rather than asserting a `401` the OR API never returns. The
stackiq controllers (contactpersonen, settings, cronjobs, me) **are**
auth-gated and return `401`; `aanbod` is intentionally `@PublicPage`.

## Collection variables

`baseUrl`, `noAuthBase`, `adminUser`, `adminPass`, plus the deployed
OpenRegister IDs `register=11`, `organisatieSchema=39`, `contactpersoonSchema=38`,
`moduleSchema=50` (Applicatie), `moduleVersieSchema=52` (Applicatieversie),
`contractSchema=41`, `dienstSchema=36`, `gebruikSchema=40`. The remaining
variables (`orgId`, `moduleId`, `dienstId`, `gebruikId`, `crud*Id`) are captured
at runtime by the setup/CRUD requests.
