# Architecture decision records

Decisions this app has made and recorded, in MADR form. Each record cites the repo file it was taken from (usually an OpenSpec `design.md`). Organisation-wide decisions that apply to all Conduction apps are kept outside this repo and are not repeated here.

| Number | Decision |
| --- | --- |
| [0001](0001-federate-through-opencatalogi.md) | Federate catalogs through OpenCatalogi |
| [0002](0002-peer-entries-are-read-only-and-go-stale.md) | Federated peer entries are read-only and go stale instead of being deleted |
| [0003](0003-contract-approval-via-decidesk-events.md) | Delegate contract approval to Decidesk through in-process events |
| [0004](0004-conservative-end-of-life-matching.md) | Match end-of-life data only on an unambiguous single candidate |
| [0005](0005-sbom-import-is-local-only.md) | SBOM import parses locally and makes no outbound HTTP calls |
| [0006](0006-cron-based-organisation-sync.md) | Synchronise organisations and contacts with a background job |

New decisions are first written in the `design.md` of an OpenSpec change under `openspec/changes/`. Add a record here, with the next number, once the decision has shipped.
