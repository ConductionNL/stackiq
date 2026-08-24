---
kind: code
depends_on: []
---

# softwarecatalog — fix licence declaration consistency (AGPL → EUPL-1.2)

## Why

The app ships a **EUPL-1.2 `LICENSE` file** (`EUROPEAN UNION PUBLIC LICENCE
v. 1.2`) and declares EUPL-1.2 in every human-facing place — the README badge
(`license-EUPL--1.2`), the info.xml description text ("Free and open source
under the EUPL license"), `composer.json` (`"license": "EUPL-1.2"`), and
`publiccode.yml` (`license: EUPL-1.2`). But the two places **machines** read
the licence from still say AGPL:

- `appinfo/info.xml` declares `<licence>agpl</licence>` — this is the value the
  Nextcloud App Store and `occ` surface to administrators. The store therefore
  advertises the app as AGPL-3.0, directly contradicting the EUPL-1.2 text in
  the repository.
- **66 of 78 PHP files under `lib/`** carry `SPDX-License-Identifier:
  AGPL-3.0-or-later` in their docblock (e.g. `lib/Service/Federation/FederationMerger.php`).
  Automated SPDX/REUSE scanners and downstream redistributors will read those
  per-file identifiers, not the root LICENSE, and conclude the code is AGPL.

This is a licensing-honesty defect, not a cosmetic one: a redistributor who
trusts the SPDX headers or the store metadata inherits the wrong obligations.
Conduction's licence policy is EUPL-1.2 (pipelinq already ships
`<licence>EUPL-1.2</licence>`); softwarecatalog must match its own LICENSE file.

## What Changes

- **`appinfo/info.xml`**: `<licence>agpl</licence>` → `<licence>EUPL-1.2</licence>`
  (the exact form pipelinq ships and the App Store accepts).
- **All PHP files under `lib/`**: the `SPDX-License-Identifier` docblock tag
  becomes `EUPL-1.2` wherever it currently reads `AGPL-3.0-or-later` (66 files),
  so every file's per-file metadata matches the repository LICENSE. Copyright
  lines are untouched.
- **No licence *text* change**: the `LICENSE` file is already EUPL-1.2 and is
  authoritative; this change only removes the metadata that disagrees with it.

Out of scope (recorded in design.md): the `<nextcloud min-version="28">` floor.
Fleet EUPL apps target NC ≥ 31, but raising the floor drops NC 28–30 support and
requires a compatibility re-test — a separate product decision, not a licence
correction.

## Impact

- **Correctness/compliance**: store, `occ`, SPDX/REUSE scanners, and
  redistributors all read EUPL-1.2 — consistent with the shipped LICENSE.
- **Risk**: minimal. No behavioural code change; metadata/docblocks only. The
  only functional surface is the App Store licence field, which becomes correct.
- **No OpenRegister dependency**; no schema, route, or API change.
