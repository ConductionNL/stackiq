# Design: landscape-ai-system-inventory

Read at development `49e65cb4`.

## Context

The catalogue holds applications (`module`, `lib/Settings/softwarecatalogus_register.json:6779` schema) and organisations' usages of them (`usage`, `:2656`). An AI system either is a product of its own or runs inside an application; in both cases the organisation needs to see it next to the application and classify it. New schemas go in a fragment (ADR-037) that appends them to the `stackiq` register (`SettingsService::loadSettings()`, `lib/Service/SettingsService.php:1653-1680`).

## D1. The aiSystem schema

`lib/Settings/register.d/ai-system-inventory.json`, schema.org type `SoftwareApplication` with `applicationCategory` AI:

| property | type | notes |
|---|---|---|
| `name` | string, required | |
| `description` | string, markdown | |
| `kind` | enum `AI agent`, `AI model`, `AI feature` | facetable |
| `module` | `$ref module` | the application it runs in or supports, `inversedBy: aiSystems` |
| `provider` | `$ref organization` | the supplier |
| `purpose` | string | what it decides or produces |
| `aiActRiskCategory` | enum `prohibited`, `high risk`, `limited risk`, `minimal risk`, `not yet assessed`, default `not yet assessed` | facetable |
| `aiActRole` | enum `provider`, `deployer` | |
| `algorithmRegisterUrl` | string, format uri | the entry at algoritmes.overheid.nl |
| `assessedOn` | date | |
| `status` | enum `in development`, `in use`, `withdrawn`, with an `x-openregister-lifecycle` on those exact values | |

Configuration: `allowFiles: true`, `allowedTags`: `FRIA`, `Technical documentation`, `Human oversight`, `Logging`. Authorization copied from `usage`: the organisation reads and edits its own AI systems (`_organisation` match); suppliers read those whose `provider` is their organisation.

Rejected: a new value `AI system` in `module.type`. The act's fields (category, role, assessment) do not belong on every application, and one application can carry several AI features.

## D2. Pages

`src/manifest.d/ai-systems.json`: `AiSystems` (`/ai-systems`, index, columns name, kind, module, aiActRiskCategory, status, `filterMenu: true`, quick filters per risk category) and `AiSystemDetail` (`/ai-systems/:id`: data, files with the four tags, related, history). A menu child "AI systems" under Applications (ADR-097). On `ModuleDetail` (`src/manifest.json:491`) an `object-list` `md-ai-systems` with filter `{ "module": "@objectId" }`.

## D3. The missing assessment badge

A computed flag through OpenRegister's calculation dialect if it can test for an attached file with a tag; otherwise the detail page shows a `CnStatusBadge` from a small check in a custom body widget `AiActChecklist` (`src/components/ai/AiActChecklist.vue`) that lists the four tags and marks which have a file. The index quick filter "High risk without FRIA" uses the same rule through the list's filter on a boolean `hasFria` that the widget cannot set; so the design writes `hasFria` from a listener on file add and remove (`lib/Listener/AiSystemFileListener.php`) only if the calculation dialect cannot express it.

## Declarative versus imperative

The schema, lifecycle, tags and pages are declarative (ADR-031). The file-presence flag is the one piece that may need a listener, decided at build time by what OpenRegister's calculation dialect supports.

## Seed data

Two demo AI systems: a chat assistant (AI agent, limited risk) inside a demo application, and a scoring model (AI model, high risk) without a FRIA, so the badge shows.

## Risks

- A supplier may register the same AI feature for its product that a municipality registered for its usage. The detail page shows provider and organisation; `operations-record-reconciliation` merges duplicates.
