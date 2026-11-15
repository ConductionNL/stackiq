---
kind: spec
depends_on: []
---

# Capability rows the code does not yet meet

## Summary

The closing round of the capability matrix (12 Nov 2026) read every row against its requirement and its code. Two kinds of row came out. Ten rows were marked built while the code does not do what the row says; they go back to specified, and this change plans the missing work. Nine rows are built, but no scenario says part of what they claim; this change writes that scenario, and the task is a test of what the code already does.

## Why

Ruben, decisions 386 to 396: every capability has a correct open spec, and the built state is true. Decision 389: a row whose code does not do it loses built.

## Rows that lose built

| Row | Requirement | What the code lacks |
|---|---|---|
| `ctr-saas-spend` | REQ-CLR-001 | No SaaS subscription list, and nothing records a purchase outside IT |
| `sec-vulnerability-alert` | REQ-CLR-002 | The alert goes to admins and record managers, not to the organisations that use the software. The subject names `{{naam}}`, a field that no longer exists |
| `sec-affected-versions` | REQ-CLR-004 | A vulnerability links to applications, not to versions |
| `land-ai-agent-inventory` | REQ-CLR-006 | An AI system names no business process |
| `arch-refcomp-mapping` | REQ-CLR-007 | The application's own reference components are hidden on its form |
| `mkt-review-moderation` | REQ-CLR-008 | The Reviews index shows pending reviews to everyone and lets the author set the status |
| `org-moderation` | REQ-CLR-010 | Approval leaves the organisation's status at Draft |
| `conn-register-connection` | REQ-CLR-012 | The standard version picker reads another register and does not resolve |
| `sec-exposure` | REQ-CLR-018 | The list of exposed organisations sits on a detail tab no page opens |
| `org-self-registration` | REQ-CLR-019 | The intake endpoint has no sign-up form, and the schema grants public create |

## Rows that stay built, with a scenario added

`mkt-reviews` (REQ-CLR-009), `life-value-assessment` (REQ-CLR-016), `sec-vulnerability-page` (REQ-CLR-005), `conn-external-provision` (REQ-CLR-013), `mkt-vendor-master-data` (REQ-CLR-014), `share-federation-peers` (REQ-CLR-015), `org-field-level-permissions` (REQ-CLR-017). `land-bulk-edit` (REQ-CLR-011) stays building. `life-new-version-notice` (REQ-CLR-003) stays specified, with stackiq's half written here next to the openregister change it already names.
