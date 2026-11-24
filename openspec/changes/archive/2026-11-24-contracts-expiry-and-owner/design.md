# Design: contracts-expiry-and-owner

Read at development 49e65cb4.

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Schema | `lib/Settings/softwarecatalogus_register.json:3250` `catalogContract` | lifecycle block (`:3531`) renamed to the English states in the monolith; schema `version` (`:3270`, now 0.1.1) and register `info.version` (`:6`, now 2.5.0) move up |
| Fragment | new `lib/Settings/register.d/contracts-expiry-and-owner.json` (ADR-037) | `Expiring` appended to the `status` enum (`:3428`), the new property `responsibleUser`, and one recipient appended to the `contract-expiry` rule (`:3258`) |
| Service | `lib/Service/ContractStatusService.php` | `shouldExpire()` (`:77`) accepts Active and Expiring; new `shouldStartExpiring()` and `shouldReturnToActive()`; `expirePastContracts()` (`:114`) becomes one pass over Active and Expiring contracts |
| Job | `lib/BackgroundJob/ContractStatusJob.php:78` | unchanged call, it now logs three counts |
| Setting | `lib/Repair/InitializeSettings.php:103` `contract_expiry_window_days` | read by the service through `IAppConfig` |
| Page | `src/manifest.json:527` `Contracten` | columns (`:534`) gain `responsibleUser`; quick filters (`:542` to `:547`) split into Expiring and Expired |
| Page | `src/manifest.json:556` `ContractDetail` | no manifest change: the `ct-data` widget (`:566`) renders every schema property, `responsibleUser` included |
| Seed | `lib/Settings/stackiq_mock_register.json:7608` onward | demo contracts get an Expiring example and a `responsibleUser` |

No new controller, route or store. The pages keep reading OpenRegister directly (ADR-022).

## Decisions

### D0. What goes in a fragment and what stays in the monolith

`SettingsService::loadSettings()` deep-merges every `lib/Settings/register.d/*.json` into the register (`lib/Service/SettingsService.php:1653` to `:1680`). `deepMergeConfig()` (`:7338`) appends lists and only replaces the lists under `authorization` (`:7340`, `:7352`). An appended enum value and an appended recipient are what this change wants, so they go in the fragment. The lifecycle rename replaces values inside `final`, `from` and `to` lists, which an append cannot do, so that edit stays in the monolith together with the version bump.

### D1. Expiring is a stored status, set by the daily job

The archived change 2026-06-14-contract-administration (design decision 3) chose a query instead: "expiring soon" as a filter on `endDate`, never a status. That query was never built, and a query cannot be a status column value, a facet or a lifecycle state that another app reads. The matrix row asks for a status that moves on its own, so this change stores it.

Rejected: a derived value computed in the browser. It would show in one view and nowhere else, not in the API, the export or the notification filter.

### D2. The job re-evaluates Expiring every day, in both directions

`ContractStatusService` gets one pure decision per move, like `shouldExpire()` today:

| From | To | When |
|---|---|---|
| Active | Expiring | `endDate` is today or later and at most `contract_expiry_window_days` days away |
| Active or Expiring | Expired | `endDate` is before today |
| Expiring | Active | `endDate` is more than the window away, because someone extended the contract |

The job never touches In negotiation, never moves a contract out of Expired, and skips a contract without a parseable end date, as `shouldExpire()` does now (`:84` to `:97`). One query fetches Active and Expiring contracts with the same 5000 ceiling as `:137`.

Rejected: OpenRegister's automatic lifecycle transitions. They fire at the end of a write (`lib/Service/Lifecycle/AutoTransitionPass.php` in OpenRegister), not on a clock, so a date that passes without a save moves nothing.

### D3. The lifecycle block uses the English states

The block at `:3531` names Actief, Verlopen and In onderhandeling. The enum and every migrated row say Active, Expired and In negotiation. The register changelog 2.4.4 (`:7`) records the same bug on `organization` and why the schema version must move with it: OpenRegister's content check compares properties, required and authorization, never `configuration`, so a lifecycle-only edit never deploys. This change rewrites the block with `initial: In negotiation`, `final: [Expired]`, and the transitions `sign` (In negotiation to Active), `approach` (Active to Expiring), `extend` (Expiring to Active), `expire` (Active or Expiring to Expired) and `renegotiate` (Expired to In negotiation).

### D4. The responsible person is a Nextcloud user id

`responsibleUser` is a string with `referenceType: nextcloud-user`. The library's `CnFormDialog` renders that as a searchable Nextcloud user picker (`@conduction/nextcloud-vue` 2.57.1, `src/utils/schema.js:355` and `src/components/CnFormDialog/CnFormDialog.vue:217`), so no custom component is needed (ADR-012).

Rejected: reusing `contactPersonUser`. It is a nested name and email (`:3398`), written for suppliers and colleagues outside Nextcloud. OpenRegister resolves a `field` recipient only when the value is an existing Nextcloud uid (`lib/Service/Notification/NotificationRecipientResolver.php:187` in OpenRegister), so an email string would be dropped without a word.

Rejected: a group field for a team. The same resolver has no kind that reads a group id from a field. Named in the proposal's out of scope.

## Declarative versus imperative

- Notification: declarative. The recipient is one more entry, `{"kind": "field", "field": "responsibleUser"}`, in the existing `x-openregister-notifications` rule. Stackiq sends nothing itself (ADR-031).
- Lifecycle: declarative states and transitions in `x-openregister-lifecycle`, so a person can still move a contract by hand through OpenRegister's transition endpoint.
- The time-driven moves stay imperative in `ContractStatusJob`. OpenRegister has no clock-driven transition (D2), and the job already exists for the Active to Expired move.

## Seed data

`catalogContract` changes, so the demo descriptor gets matching rows. Values follow the English enum.

| Field | Contract 1 | Contract 2 | Contract 3 | Contract 4 |
|---|---|---|---|---|
| `@self.slug` | contract-contract-1-1 | contract-contract-2-2 | contract-contract-3-3 | contract-expiring-4 |
| `contractNumber` | CON-2025-001 | CON-2024-017 | CON-2026-003 | CON-2023-042 |
| `contractType` | SLA | Licence | Maintenance | Licence |
| `startDate` | 2025-01-01 | 2024-03-01 | 2026-02-01 | 2023-07-01 |
| `endDate` | 2027-12-31 | 2026-03-01 | empty | 60 days after the seed date |
| `status` | Active | Expired | In negotiation | Expiring |
| `responsibleUser` | admin | admin | empty | admin |

Contract 4 is new. The seed writer computes its end date from the import date, so the Expiring example stays inside the window. `admin` exists on every development and CI instance.

## Risks

- **A long window marks many contracts at once.** A window of 365 days on a large catalogue flips many rows in one night. The pass is bounded at 5000 rows and each save is logged, as today.
- **Existing Expired rows stay Expired.** The job never moves a contract out of Expired, so a contract that expired by mistake still needs a person to renegotiate it.
- **The rule may not fire yet.** Until `stackiq:ctr-expiry-alert` fixes the filter and the subject fields, the responsible user receives nothing. The scenarios that need a delivered notification are marked for the unit test that checks the declaration, not for a browser run.
