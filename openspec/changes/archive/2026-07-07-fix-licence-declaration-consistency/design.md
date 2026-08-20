# Design: fix-licence-declaration-consistency

## Decision 1 — `EUPL-1.2` as the info.xml `<licence>` value

The Nextcloud `info.xsd` `<licence>` field is a free string; the App Store
renders it verbatim. pipelinq already ships `<licence>EUPL-1.2</licence>`
against an identical EUPL-1.2 LICENSE and passes App Store validation, so
`EUPL-1.2` is the proven, correct value (not `eupl`, not `eupl-1.2` lowercase —
match the fleet form). `agpl` is simply wrong: it names a licence the repo does
not ship.

## Decision 2 — Rewrite SPDX identifiers, never touch the LICENSE text

The `LICENSE` file (EUPL-1.2) is authoritative and correct; it is not edited.
The defect is the 66 PHP docblocks that claim `AGPL-3.0-or-later`. Each is
rewritten to `SPDX-License-Identifier: EUPL-1.2`. Per the fleet convention
(feedback_spdx-in-docblock) the SPDX tag lives **inside the main file
docblock** — the edit is in place, not a new header block. `@copyright` /
author lines are preserved unchanged.

Because this is a source edit across ~66 files, it MUST be done with the editor
(not sed/awk/scripts — CLAUDE.md hard rule): match the single line
`SPDX-License-Identifier: AGPL-3.0-or-later` per file and replace it. The 12
files already on EUPL-1.2 are left alone. A final scan (`grep -rl 'AGPL-3.0'
lib/`) MUST return zero.

## Decision 3 — NC min-version is out of scope

`appinfo/info.xml` declares `<nextcloud min-version="28" max-version="34"/>`.
The brief pairs "EUPL-1.2" with "NC ≥ 31", but raising `min-version` to 31 is a
**support decision** that drops NC 28–30 and demands a compatibility pass
(the app currently claims 28+). Conflating it with a licence-string fix would
make an otherwise zero-risk change risky. Left as a noted follow-up; if the
fleet ratifies an NC ≥ 31 EUPL floor, a separate change bumps the floor and
re-tests. This change does not alter `min-version`.

## Decision 4 — No OpenRegister surface

Nothing here touches schemas, routes, controllers, services, or the manifest.
There is no OR abstraction to consume; a licence declaration is app metadata.
