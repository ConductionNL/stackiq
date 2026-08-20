# Design: softwarecatalog-mcp-adoption

## Architecture Overview
No new architecture. Software Catalog already loads its OpenRegister
configuration from `lib/Settings/softwarecatalogus_register.json`, deep-merged
(ADR-037) with every `*.json` fragment in `lib/Settings/register.d/` by
`SettingsService::loadSettings()` (`self::deepMergeConfig()`, confirmed at
`lib/Service/SettingsService.php:1465-1535`). This change adds exactly one new
fragment, `register.d/softwarecatalog-mcp-adoption.json`, that contributes a
`configuration.x-openregister-mcp` block to 9 existing schema definitions.
Nothing else in the request path changes: OpenRegister's
`SchemaDerivedToolProvider` (cross-repo, already merged at
openregister@origin/development) reads the merged register at MCP-serve time
and derives `softwarecatalog.<schema>.<verb>` tools — Software Catalog ships no
PHP for this at all.

```
Hermiq (agent)
  -> OpenRegister /api/mcp (JSON-RPC) or chat facade
    -> SchemaDerivedToolProvider
      -> merged softwarecatalog register (monolith + register.d/*.json)
        -> configuration.x-openregister-mcp per schema  (THIS CHANGE adds these)
```

## Curation — schema -> verbs -> why

19 schemas exist in the softwarecatalogus register at HEAD (verified via
`python3 -c "import json; ... schemas.keys()"` against
`lib/Settings/softwarecatalogus_register.json`). 9 are curated ON, 10 are OFF.
Every verb below is read-only (`search`/`get`) — see "Why no writes" below for
the single cross-cutting justification instead of repeating it per schema.

| Schema | Verbs | Why (one line) |
|---|---|---|
| `module` | search, get | THE core catalogue entity — "een applicatie is een softwarecomponent"; the primary thing an agent is asked to look up ("does gemeente X run product Y", "what's module Z's licence"). |
| `moduleVersie` | search, get | Version/lifecycle detail of a module ("which version of module X is current / end-of-support") — a natural drill-down from `module`. |
| `dienst` | search, get | A supplier's specific service offering on one or more modules — "what services does supplier X sell". |
| `organisatie` | search, get | Vendors, municipalities, samenwerkingsverbanden, communities — "who participates in the catalogue" and the counterpart to every `aanbieder`/`afnemer` reference. |
| `contactpersoon` | search, get | Answers "who do I contact about module/organisatie X" — the catalogue's own contact-routing entity (identity itself lives in NC Contacts via `contactsUid`, per `contact-is-nextcloud-entity`). |
| `koppeling` | search, get | Integrations between applications/systems — "how do systems A and B exchange data", explicitly requested (a "components" question). |
| `compliancy` | search, get | Standards-compliance evidence — explicitly requested by the task brief ("standards-compliance"); "is module X compliant with standard Y". |
| `gebruik` | search, get | Adoption/usage records — "which organisations use module X and at what lifecycle stage" — the core join between `organisatie` and `module`/`dienst`. |
| `contract` | search, get | Procurement agreements — "which contracts are active/in-negotiation for service X"; kept strictly read (see Risk 2 in proposal.md) because `approvalState`/`status=Actief` are decidesk-projected fields softwarecatalog itself never authors. |

### Excluded (10 schemas) — what and why

| Schema | Why excluded |
|---|---|
| `sector` | Flat 2-field taxonomy lookup (`naam`, `beschrijving`); not itself something a user interrogates — reachable indirectly via a module/organisatie's own classification if ever needed. Omitted to avoid tool clutter (bias to fewer). |
| `suite` | "A collection of applications that together form a product" — a secondary bundling concept; an agent can already discover an application's family through `module.search`. Omitted to avoid a near-duplicate read surface. |
| `kwetsbaarheid` | Schema's own description: "onderdeel van het vastgestelde datamodel maar wordt niet daadwerkelijk in de applicatie gebruikt" (part of the agreed data model but **not actually used** by the application) — dead schema, nothing to query. |
| `beoordeeling` | Same "vastgestelde datamodel maar niet daadwerkelijk gebruikt" (not actually used) language as `kwetsbaarheid` — dead schema. |
| `element` | AMEF/ArchiMate architecture-model element — 80+ properties, a bulk GEMMA-XML-sync artifact (see `GEMMA_release.xml` etc. in `lib/Settings/`), not something a catalogue user asks about conversationally. Its huge property surface would itself bloat any generated tool schema. |
| `view` | AMEF/ArchiMate view/diagram — same bulk-sync-artifact reasoning as `element`; a rendered diagram isn't a chat-answerable entity. |
| `model` | AMEF/ArchiMate full model container (elements + relationships + views + orgs as nested XML) — a whole-file import/export artifact, not a queryable record. |
| `organization` (English) — distinct from `organisatie` (Dutch) | DCAT/GEMMA "publisher of a catalog" concept from the architecture-model import path, not the softwarecatalog vendor/customer entity (that's `organisatie`, which is included). Two schemas share a similar name; only the one actually used by the softwarecatalog domain is curated. |
| `property-definition` | AMEF property-definition metadata (3 fields, used only to interpret `element`/`relation` XML) — no standalone meaning outside the architecture-import pipeline. |
| `relation` | AMEF ArchiMate relation between elements — same bulk-sync-artifact reasoning as `element`/`view`. |

**Why no writes anywhere in this change:** every mutating path in Software
Catalog already runs through a dedicated workflow service that encodes
business rules an ad hoc agent `create`/`update` would bypass:
`ModuleRegistrationService`/`ModuleRegistrationHandler` for module onboarding,
`OrganizationSyncService`/`GroupHandler` for organisation participation,
`ContractApprovalService`/`ContractStatusService` for contract lifecycle. Most
concretely, `contract.status = "Actief"` and `contract.approvalState` are
projections of a decidesk decision — the `contract` schema's own field
description says "softwarecatalog never authors an approval decision locally"
and "NEVER sets `status = Actief` on its own authority". Several curated
schemas (`moduleVersie`, `koppeling`, `organisatie`, `gebruik`, `contract`) also
carry `configuration.x-openregister-lifecycle` state machines — a raw `update`
would let an agent set `status` to a value outside the declared transition
graph. A future `kind: code` change could promote one narrow, non-CRUD action
(e.g. "submit contract for approval" on `ContractApprovalService`) to a
`#[McpTool]` once there's a concrete agent workflow need for it — deliberately
deferred, not part of this change (see `DEFERRED_QUESTIONS` below).

## `configuration.x-openregister-mcp` — exact per-schema declaration

All 9 blocks below go into
`lib/Settings/register.d/softwarecatalog-mcp-adoption.json`, one entry per
schema under `components.schemas.<name>.configuration.x-openregister-mcp`.
ADR-037's recursive deep-merge folds each block into the existing
`configuration` object (e.g. `moduleVersie` already has
`configuration.x-openregister-lifecycle` — this only adds a sibling key, it
does not touch it). Every `filters` entry below was cross-checked against the
schema's `properties` map at HEAD; every one is a real property.

```json
{
  "components": {
    "schemas": {
      "module": {
        "configuration": {
          "x-openregister-mcp": {
            "enabled": true,
            "tools": {
              "search": {
                "description": "Search software applications and system software in the VNG softwarecatalogus by name, type (Applicatie/Systeemsoftware), supplier, licence type, or hosting jurisdiction. Use this to find which applications a municipality could adopt, or which modules a given supplier offers.",
                "scope": "read",
                "filters": ["naam", "type", "aanbieder", "licentietype", "hostingJurisdictie"],
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              },
              "get": {
                "description": "Get a single application/module by id, including licensing, hosting jurisdiction, standards compliance, and linked service offerings.",
                "scope": "read",
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              }
            }
          }
        }
      },
      "moduleVersie": {
        "configuration": {
          "x-openregister-mcp": {
            "enabled": true,
            "tools": {
              "search": {
                "description": "Search application version records by parent module or lifecycle status (in ontwikkeling / in gebruik / einde ondersteuning / teruggetrokken). Use this to find which version of a module is current or when support ends.",
                "scope": "read",
                "filters": ["module", "status"],
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              },
              "get": {
                "description": "Get a single application version's full lifecycle detail, including its support dates and linked usage records.",
                "scope": "read",
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              }
            }
          }
        }
      },
      "dienst": {
        "configuration": {
          "x-openregister-mcp": {
            "enabled": true,
            "tools": {
              "search": {
                "description": "Search service offerings (Diensten) by name or supplying organisation — a Dienst is a specific service a supplier delivers on one or more applications.",
                "scope": "read",
                "filters": ["naam", "aanbieder"],
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              },
              "get": {
                "description": "Get a single service offering by id, including its supplier, linked applications and integrations, and publication window.",
                "scope": "read",
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              }
            }
          }
        }
      },
      "organisatie": {
        "configuration": {
          "x-openregister-mcp": {
            "enabled": true,
            "tools": {
              "search": {
                "description": "Search organisations participating in the softwarecatalogus — municipalities, suppliers, samenwerkingsverbanden, or communities — by type, lifecycle status, or registration state.",
                "scope": "read",
                "filters": ["type", "status", "registratiestatus"],
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              },
              "get": {
                "description": "Get a single organisation by id, including its participation type, lifecycle status, and registered contact persons.",
                "scope": "read",
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              }
            }
          }
        }
      },
      "contactpersoon": {
        "configuration": {
          "x-openregister-mcp": {
            "enabled": true,
            "tools": {
              "search": {
                "description": "Find the contact person responsible for a given organisation or role — use this to answer 'who do I contact about organisation/module X'.",
                "scope": "read",
                "filters": ["organisatie", "functie"],
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              },
              "get": {
                "description": "Get a single contact person's role and organisation link. Identity/contact details themselves live in Nextcloud Contacts, referenced here via contactsUid.",
                "scope": "read",
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              }
            }
          }
        }
      },
      "koppeling": {
        "configuration": {
          "x-openregister-mcp": {
            "enabled": true,
            "tools": {
              "search": {
                "description": "Search integrations (koppelingen) between applications/systems by connection type, lifecycle status, or koppelingType (extern/intern) — use this to find how two systems exchange data.",
                "scope": "read",
                "filters": ["type", "status", "koppelingType", "aanbieder"],
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              },
              "get": {
                "description": "Get a single integration's full detail: connected modules, data-exchange direction, and supported standard versions.",
                "scope": "read",
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              }
            }
          }
        }
      },
      "compliancy": {
        "configuration": {
          "x-openregister-mcp": {
            "enabled": true,
            "tools": {
              "search": {
                "description": "Search standards-compliance evidence records by module or GEMMA standard, to check which applications have demonstrated compliance with a given standard.",
                "scope": "read",
                "filters": ["module", "standaardGemma"],
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              },
              "get": {
                "description": "Get a single compliance record's evidence (bewijs) and reference for a module/standard pair.",
                "scope": "read",
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              }
            }
          }
        }
      },
      "gebruik": {
        "configuration": {
          "x-openregister-mcp": {
            "enabled": true,
            "tools": {
              "search": {
                "description": "Search usage/adoption records — which organisation (afnemer) is using which module/service, and at what lifecycle stage (Verwerving/Gepland/In productie/Uit te faseren/Uitgefaseerd).",
                "scope": "read",
                "filters": ["afnemer", "aanbieder", "status", "module"],
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              },
              "get": {
                "description": "Get a single usage record's full adoption timeline and linked contract/module detail.",
                "scope": "read",
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              }
            }
          }
        }
      },
      "contract": {
        "configuration": {
          "x-openregister-mcp": {
            "enabled": true,
            "tools": {
              "search": {
                "description": "Search procurement contracts by status, contract type (SLA/Licentie/Onderhoud), or the underlying service/usage they cover — for auditing which agreements are active or in negotiation.",
                "scope": "read",
                "filters": ["status", "contractType", "dienst", "gebruik"],
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              },
              "get": {
                "description": "Get a single contract's full detail, including cost, term dates, and the decidesk-projected approval outcome. Softwarecatalog never sets a contract to Actief on its own authority — that transition is always driven by an approved decidesk decision.",
                "scope": "read",
                "readOnlyHint": true,
                "destructiveHint": false,
                "idempotentHint": true
              }
            }
          }
        }
      }
    }
  }
}
```

## Goals / Non-Goals
**Goals:** derive a curated, read-only MCP surface over the 9 catalogue
schemas an assistant would plausibly be asked about, using only the existing
ADR-037/ADR-063 mechanisms.

**Non-Goals:** no write surface; no AMEF/GEMMA architecture-model surface; no
new PHP; no change to the fragment-merge mechanism itself; no change to
existing register fragments.

## Decisions

### Decision 1: `register.d/` fragment, not editing the monolith
The softwarecatalog `register.d/README.md` states each change should "add its
own `<change>.json` (OpenAPI components.schemas/paths) instead of editing
`softwarecatalogus_register.json` — concurrent builds never conflict." This
change follows that convention exactly: `register.d/softwarecatalog-mcp-adoption.json`.
**Alternative considered:** editing `softwarecatalogus_register.json` directly
(this is what the pipelinq `client`/`lead` exemplar did) — rejected because
Software Catalog's own README explicitly asks changes not to do this, and the
fragment mechanism costs nothing extra.

### Decision 2: Read-only for every schema in this change
Considered enabling `create`/`update` on the more transactional schemas
(`gebruik`, `contract`) since they're the ones an operator would most want to
"just update via chat." Rejected — see "Why no writes" above; every one of
these schemas has either an explicit lifecycle state machine or an external
approval-projection field that a raw MCP write would bypass. A future
`kind: code` change can add a curated `#[McpTool]` for a specific, safe
non-CRUD action once one is identified.

### Decision 3: Exclude the AMEF/GEMMA architecture-model schemas entirely
Considered curating a read-only `element.search`/`get` (e.g. "what elements
implement standard X") since GEMMA classification is genuinely useful
context. Rejected for this change: `element` alone has 80+ properties (the
largest in the register by a wide margin), the data arrives via bulk XML
import (`GEMMA_release.xml`, 13MB) rather than user-authored records, and
none of `element`/`view`/`model`/`relation`/`property-definition`/
`organization` map to a "thing a catalogue user asks about" the way `module`
or `koppeling` do. Bias to fewer wins here.

## Risks / Trade-offs
[Risk] A future schema property rename silently breaks a declared `filters`
entry → Mitigation: OpenRegister's `McpAnnotationValidator` hard-rejects the
whole schema import when a filter names a non-existent property, so this fails
loudly at import time (visible in `SettingsService`'s import log), not
silently at query time.

[Trade-off] Excluding `element`/`view`/`model`/`relation` means an agent
cannot answer "which ArchiMate elements are tagged with GEMMA theme X" via
MCP → Accepted: that's a legitimate but separate future change, properly
scoped with its own filter design given the schema's size, not bundled into
this curated-catalogue change.

## Migration Plan
1. Add `register.d/softwarecatalog-mcp-adoption.json` with the 9 blocks above.
2. `python3 -m json.tool` validate the new fragment.
3. Re-run `SettingsService::loadSettings()` (via the existing repair/import
   path) so the fragment signature changes and OpenRegister re-imports the
   merged register.
4. Verify via OpenRegister's MCP tool listing that
   `softwarecatalog.module.search`, `.get`, etc. (18 tools total) appear.
5. **Rollback:** delete the fragment file (or flip every `enabled` to
   `false`) and re-run the import — see proposal.md Rollback Strategy.

## Seed Data
Not applicable — this change annotates 9 *existing* schemas with MCP metadata;
it introduces no new schema and no new objects. The catalogue's existing seed
data (already present for `module`, `organisatie`, etc.) is unaffected and
sufficient to exercise the new derived tools once imported.

## Trade-offs
See "Risks / Trade-offs" above.

## DEFERRED_QUESTIONS
- Should `contract` eventually get a curated, non-CRUD
  `#[McpTool]` action (e.g. "request contract approval", delegating to
  `ContractApprovalService`) once there's a concrete Hermiq workflow that needs
  it? Deferred — no code-kind follow-up filed yet; revisit once a real agent
  use case names the action.
- Should the AMEF/GEMMA schemas (`element`, `view`, `relation`) get a
  narrowly-filtered read-only surface in a dedicated follow-up change once
  their property surface can be trimmed (e.g. via a projection/summary
  schema)? Deferred — out of scope here, no issue filed yet.
