# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md` (hydra#667, amended in hydra#673, with hydra#674 pending). This file records how stackiq meets it and where it fits loosely.

## D1. Which connections are declared

Each candidate was checked against the code on `development`.

| Key | Declared as | Why |
|---|---|---|
| `email` | `adapter.configKey: email_transport_type`, `simulatedValues: ["null"]` | `SymfonyEmailService::createTransport()` builds `null://null` for `null`. |
| `federation` | `reportedOnly: true` | `FederationService` needs OpenCatalogi installed and the boolean key `federation_enabled`. Neither is readable as a filled string. |
| `eol-feed` | `reportedOnly: true`, `sourceTemplate: endoflife-date` | `EolSyncService::run()` reads integriq's `eol_product` and `eol_cycle` through OpenRegister. Integriq seeds the `endoflife-date` source. |

**Why an empty transport is not simulated.** Stackiq reads a key that was never set with the default `smtp`. A key set to an empty or unknown value reaches the `default` branch of `createTransport()`, which builds an SMTP transport with a warning. An empty value sends real mail, so the contract default `[""]` would be a false Simulated. The file lists `null` only.

**Why email has no `requiredConfig`.** Which keys a transport needs depends on the transport: a host for SMTP, an API key for SendGrid, nothing for sendmail. And `email_enabled` stores `false` as a filled string. No fixed key list can say "the settings are complete", so stackiq reports it (D2).

**Why federation and eol-feed are reported only.** `federation_enabled` is typed boolean, and integriq's reader answers `typed` for it, which is a filled value. `eol_sync_config` is a JSON blob that is filled after any save, with `enabled` true or false. Rule 5 would read both as configured.

**Anchors.** The admin section is `stackiq` (`StackiqAdmin::getSection()`), so each link is `/settings/admin/stackiq#section-…`. The three section components put the id on their `AlwaysVisibleSection`, whose root `NcSettingsSection` inherits it.

## D2. What stackiq reports, and when

`lib/Service/ConnectionReportService.php` sends both events. It names the classes by string behind `class_exists` (ADR-041) and never throws.

Every save sends the refresh first and the report second. Under hydra#674 the refresh retires older observations, so a report sent before it would be retired by it.

**Email, on an email settings save** (`POST /api/settings/email`, or `PUT` and `POST /api/settings` with `emailSettings`). Stackiq reads `SymfonyEmailService::isEmailSystemConfigured()`.

| Stackiq sees | Status | Message |
|---|---|---|
| Email switched off | `unconfigured` | "Email is switched off, so stackiq sends no mail." |
| The transport misses what it needs | `unconfigured` | "Email is on, and the SMTP Server transport misses a setting it needs." |
| A required template is empty | `unconfigured` | "Email is on, and a required mail template is empty." |
| Everything filled | `configured` | "Email is on and the SMTP Server transport settings are filled. No test mail was sent." |

The `null` transport still reads Simulated: rule 3 sits above every report.

**Federation, on a peer add or remove.** Stackiq reads `FederationService::getStatus()`.

| Stackiq sees | Status | Message |
|---|---|---|
| OpenCatalogi not installed | `unavailable` | "Federation needs the OpenCatalogi app, and it is not installed." |
| `federation_enabled` off | nothing: the refresh alone, and the switch makes integriq read `disabled` with the declared message | |
| No peers | `unconfigured` | "Federation is on, and no peer catalog is added yet." |
| Ready | nothing | the refresh alone, so the row reads the declared "Not checked yet" |

**Federation, after a pull** (Pull now, or `FederationSyncJob`). The same three blocking states come from the pull's `reason`. Otherwise:

| Peers that answered | Status |
|---|---|
| all | `configured` |
| some | `limited`, naming the first host that failed |
| none | `error`, naming the first host that failed |

A message names a peer by host only, never by its full URL, and cuts a failure reason at 160 characters.

**End-of-life feed, on an EOL sync settings save.** A refresh and no report. When `enabled` is off the switch makes integriq read `disabled`. Otherwise the row reads "Not checked yet" until the next run.

**End-of-life feed, after a run** (Sync now, or `EolSyncJob`). `EolSyncService::run()` already records a status. The report maps its `reason`:

| Reason | Status |
|---|---|
| none, the run completed | `configured`, with the matched and skipped counts |
| `disabled` | nothing, the switch says it |
| `openregister-not-installed` | `unavailable` |
| `object-service-unavailable` | `error` |
| `module-schema-not-configured` | `unconfigured` |
| `eol-register-or-schema-not-found` | `unconfigured`, naming integriq's endoflife.date source |
| anything else | `error`, naming the reason |

**Why this is cheap.** A save and a button are admin actions. `FederationSyncJob` runs once per `federation_sync_interval` (3600 s by default), and `EolSyncJob` once per `intervalSeconds`, never below 300 s. No page request sends an event (ADR-076).

**Wiring.** `FederationService`, `FederationSyncJob`'s service and `EolSyncService` are built by hand in `Application::register()`. Those factories pass the report service by name. `SettingsController` is autowired, so it takes the service as an optional last argument.

## D3. The page

- `src/manifest.d/connection-registry.json`: an `index` page `Integrations` at `/settings/integrations`, `requiresApp` integriq, `permission: admin`, `showAdd: false`, and the columns connection, status, status message, last checked and settings.
- Its menu entry `IntegrationsMenu` sits in the settings gear with `query: {app: stackiq}`, `permission: admin` and `visibleIf.appInstalled: integriq`.
- `src/services/connectionRegistry.js` holds `openIntegriqConnections`.
- `App.vue` passes no `formatters`: CnAppRoot supplies the two built-ins. `src/customComponents.js` carries the handler, because CnIndexPage resolves a header action's handler against `customComponents`.

**Formatters.** `@conduction/nextcloud-vue` 3.2.0 ships `connectionStatus` and `connectionSettingsLabel` as built-ins, `disabled` included (nextcloud-vue#1173). Stackiq carried a local copy while it resolved 2.39.0, and dropped it on moving to 3.2.0.

## D4. Contract misfits

- **A boolean app-config key.** `federation_enabled` is typed boolean, and a stored `false` counted as filled. Resolved by hydra#676 (`false` reads empty) and hydra#677: the row declares `switch: {"configKey": "federation_enabled"}` and reads `disabled` while it is off.
- **A flag inside a blob.** `eol_sync_config` holds `{"enabled": false, …}`. Resolved by hydra#677: the row declares `switch: {"configKey": "eol_sync_config", "jsonPath": "enabled"}`. An unset blob has no `enabled` and reads off, which matches `SettingsService::getEolSyncConfig()`'s default.
- **Completeness that depends on the adapter.** Email needs different keys per transport. `requiredConfig` is one fixed list.
- **Gate 116's vendored schema is behind integriq.** `hydra-gates/scripts/schemas/connections.schema.json` on `.github` `main` has no `jsonPath`, `simulatedValues` or `reportedOnly`, so gate 116 warns on every file that uses the hydra#673 fields. The file validates against integriq's own schema on `development`.

## Risks

- **Same-second ordering.** Stackiq sends the refresh before the report. If integriq stamps `refreshedAt` later than the report's `at` within one request, the report is retired. Hydra#674 compares with "not older than", so an equal stamp counts.
- **A federation row can lag a failing peer.** Between pulls the row keeps the last outcome. `FederationSyncJob` bounds that to one sync interval.
