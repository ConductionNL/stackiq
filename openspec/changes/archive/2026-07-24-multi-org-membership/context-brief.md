# Context Brief: multi-org-membership

## What
Let one user act for **multiple organisations** (samenwerkingsverbanden, shared service centres, herindeling transitions): an organisation switcher in the app UI, and a self-service flow to give a colleague access to your organisation. Closes softwarecatalog#371.

## Why (evidence)
- VNG Softwarecatalogus issues **#57** and **#60** — one account active in multiple organisations.
- VNG **#65** — self-service colleague access (today an admin must do it).
- Dutch municipal reality: shared service centres and samenwerkingsverbanden mean one person legitimately works for several organisations; herindelingen create transition periods with dual membership.

## The hard part is ALREADY BUILT — verify before you build anything
`openregister/lib/Service/OrganisationService.php` already ships (verified 2026-07-24):
- `getUserOrganisations(bool $_useCache = true): array` (line ~474)
- `getActiveOrganisation(?array $preloadedOrgs = null): ?Organisation` (line ~513)
- `setActiveOrganisation(string $organisationUuid): bool` (line ~560)

So membership + active-org switching exist at the platform layer. **Read those methods first** and build on them — do NOT reimplement membership in softwarecatalog. Check what HTTP surface OpenRegister already exposes for them (grep its `appinfo/routes.php` and controllers); if an endpoint already exists, consume it rather than adding a proxy controller here (ADR-011: check OpenRegister core before adding your own; ADR-022: apps consume OR abstractions — a pass-through controller is a `hydra-gate-redundant-controller` failure).

Softwarecatalog currently has **no org-switcher UI** and **no invite flow** (`grep -rli "invite\|uitnodig" src/ lib/` → nothing). `lib/Controller/ContactpersonenController.php` references the org primitives — read it to see the existing pattern.

## Scope
IN:
- **Organisation switcher** in the app UI (header/nav area) listing the user's organisations and switching the active one; the whole app's tenant context (`X-OpenRegister-Organisation`) must follow the switch, and lists/pages must refresh to the newly-active organisation.
- **Self-service colleague access**: an organisation member with the right role can grant an existing Nextcloud user access to their organisation (and revoke it). Build on the existing role/group machinery in `openspec/specs/sc-handlers/spec.md` (`ContactPersonHandler`, `addUsersToOrganization`) and `softwarecatalog-contacts-to-nc`.
- i18n (EN keys + nl + en_US), unit tests, docs.

OUT: inviting brand-new users by email (that is user provisioning — out of scope); cross-organisation data merging (that is #370/organisation-merge); changing the RBAC model itself.

## Design constraints
- 🔒 **Interacts directly with the security model just hardened in sc#395 (`schema-rbac-hardening`).** Schema RBAC now scopes reads by `_organisation` matched against the caller's organisation context. Switching the active organisation MUST change what the user can see — and must NOT become a way to read an organisation the user is not a member of. Membership has to be verified server-side on every switch; never trust a client-supplied organisation id. Include NEGATIVE tests: switching to a non-member organisation is refused, and after switching the user sees only that organisation's data.
- **Any register change goes in a NEW `lib/Settings/register.d/multi-org-membership.json` fragment — never edit the monolith** (ADR-037; a monolith edit was a silent no-op on installed instances until sc#396, and fragments remain the correct pattern).
- 🔑 Register store object types by **schema SLUG** against `voorzieningenConfig.register` (the `useSelfFetchList.js` pattern) — several `voorzieningen_config.<x>_schema` keys are never populated; that mistake shipped a dead org picker (sc#392).
- ADR-012 `@conduction/nextcloud-vue` components; modals/dialogs each in their own file; `NcSelect` needs `inputLabel`.
- Security change ⇒ `hydra-gate-security-change-has-tests` requires tests. SPDX docblocks on new lib/ PHP.
- Spec deltas: `### Requirement: <name>` headers; MUST/SHALL on the requirement's FIRST physical line; no angle brackets in requirement bodies; `#### Scenario:` GIVEN/WHEN/THEN per MUST/SHALL.
- `@spec` anchors → canonical `openspec/specs/<capability>/spec.md#requirement-<kebab>`, NEVER a change dir (archive moves it).
- ⚠️ After `openspec archive`, run `grep -c '^### ' openspec/specs/<cap>/spec.md` and `git diff -- openspec/specs/` — the archiver silently DELETES legacy `### REQ-NNN:` requirements (hydra#376).
