---
kind: spec
depends_on: []
---

# Requirements for matrix rows that had none

## Summary

Thirteen rows of the stackiq capability matrix name behaviour that no requirement says. This change writes one requirement for each, in the main spec that already owns the area. Most describe what stackiq does today, so the matrix can point at a requirement instead of at a spec directory. Three describe behaviour the code does not yet have, and those rows move back from built to planned.

## Why

Ruben, 11 Nov 2026: every capability needs an open spec (decisions 386, 387). The spec coverage measure counts a row only when it records a requirement whose scenarios say what the row says.

## Rows and where their requirement goes

| Row | Spec | Today |
|---|---|---|
| `land-guided-wizard` | guided-entry-wizard (new) | Only suites have a wizard. Planned. |
| `comp-standards-register` | module-compliance-assessment | Built. |
| `comp-bulk-sync-standards` | module-compliance-assessment | Built. |
| `mkt-supplier-directory` | fe-organizations | Built. |
| `org-register` | fe-organizations | Built. |
| `org-contact-persons` | fe-organizations | Built. |
| `ins-dashboard` | fe-shell-navigation | Built. |
| `conn-register-connection` | catalogue-connection-pages | Built. |
| `conn-usage-of-connection` | application-usage-pages | Built. |
| `mkt-free-public-service` | open-data-publishing | Built. |
| `sec-sbom-import` | sbom-import | Built. The SPDX path was a note, now a requirement. |
| `org-contact-to-account` | contactpersoon-sync | The conversion reads an email field the contact schema no longer has. Planned. |
| `ctr-approval` | contract-decision-delegation | The status enum still lets a user save a contract as Active without a decision. Planned. |

## Out of scope

Rows whose requirement lives in another repo (openregister) and rows an open change already covers.
