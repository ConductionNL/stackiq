---
kind: code
---

# Proposal: Rename the app id from `stackiq` to `stackiq`

## Problem

The repository was renamed to `ConductionNL/stackiq` in the 2026-08 fleet
renaming, but the app itself was not: `appinfo/info.xml` still declares
`<id>stackiq</id>` and `<namespace>Stackiq</namespace>`. Until
the id moves, the repo name and the installed app disagree, `occ` commands and
URLs still carry the old name, and every later change has to keep straddling
both spellings.

Moving an app id is not a search-and-replace, because **Nextcloud has no
in-place app-id upgrade**. `oc_appconfig` and `oc_preferences` are namespaced by
app id; the moment the id changes, every row written under the old one becomes
unreachable. Nothing errors — every reader in this codebase supplies a default —
so admin settings and per-user preferences silently revert and the instance
looks freshly installed. `oc_jobs` has the same shape one level down: it stores
the background job's CLASS NAME as a string, so the namespace rename orphans all
four job rows and they simply stop running.

The mirror-image risk is renaming too much. Several identifiers in this tree are
**values other systems store and match on**, not names this app owns. Rewriting
one of those does not fail loudly either — it produces a working-looking app that
quietly does less: a group check that matches nobody, a dashboard widget that
vanishes from every dashboard, an OpenRegister import that creates a second
configuration and orphans the first, a documentation deploy to a host with no DNS
record.

## Proposed Change

1. **Move the identity.** `<id>` -> `stackiq`, `<namespace>` -> `Stackiq`, PHP
   root namespace `OCA\Stackiq` -> `OCA\Stackiq`, l10n domain, URL and
   route prefixes, webpack bundle prefix, DOM mount ids, composer/npm package
   names, and the display name -> "Stackiq". App-named classes and files move
   with it (`StackiqueService` -> `StackiqService`,
   `lib/Service/Stackique/` -> `lib/Service/Stackiq/`,
   `StackiqAdmin` -> `StackiqAdmin`, and their tests).

2. **Carry the stored data across.** Three new `IRepairStep`s —
   `MigrateAppConfigKeys`, `MigrateUserPreferences`,
   `MigrateBackgroundJobClasses` — registered FIRST in both `<install>` and
   `<post-migration>`. Ordering is load-bearing: `InitializeSettings` writes app
   config itself, so if it ran first the copy step would see the key as already
   present and strand the operator's real value. `<install>` matters because an
   app-id rename presents to Nextcloud as a FIRST install of `stackiq`, and
   `Installer::installAppLastSteps()` guards `<post-migration>` with
   `if ($previousVersion !== '')` — so `<install>` is the only hook that fires on
   the very upgrade these steps exist for.

3. **Freeze what this app does not own**, each with a comment naming the
   consequence so a later tidy-up does not undo it. The full inventory is in the
   spec's "Externally Owned Identifiers Stay Frozen" requirement.

## Non-goals

- Registering `stackiq` with the Nextcloud App Store, or issuing the signing
  certificate for the new id. `.nextcloud/certificates/stackiq.csr` is
  left as-is; a maintainer action is required before a stable release publishes
  under the new id.
- Moving `softwarecatalog.conduction.nl` / `www.conduction.nl/apps/stackiq`
  or the `stackiq-docs` Cloudflare Pages project. Those move when DNS and
  the Pages binding move, in a change that verifies both.
- Renaming `openspec/specs/*` capability directories. They are referenced from
  `openspec/changes/archive/**`, which is frozen history.
- Renaming PHP/JS method, property and local-variable names that contain the
  product name. Several are named after the frozen group ids, and renaming a
  method declaration drags it into gate-16's diff scope for no functional gain.
