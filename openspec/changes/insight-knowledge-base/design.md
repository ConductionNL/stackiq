# Design: insight-knowledge-base

Read at development 49e65cb4, with `@conduction/nextcloud-vue` 2.57.1 and OpenRegister development 4fee776.

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Page | `src/manifest.json:491` `ModuleDetail` | a widget `md-knowledge`, `type: integration`, `integrationId: collectives`, `requiredApp: collectives`, title Knowledge articles, and a layout row below `md-compliance` and `md-versions` (`:510` and `:511`) |
| Page and menu | new `src/manifest.d/insight-knowledge-base.json` (ADR-024, ADR-037) | page `KnowledgeBase` at `/knowledge`, `type: dashboard`, one `kb-search` widget; menu entry `KnowledgeBaseMenu` with `visibleIf.appInstalled: collectives`; the page has `requiresApp` Collectives |
| Menu layout | `src/menu-layout.json` `relocations` | `"KnowledgeBaseMenu": "Modules"`, so the entry is a child of Applications and not a new top-level entry (ADR-097) |
| Strings | `l10n/en.json`, `l10n/nl.json` | panel title, page title, hint and empty texts |

No schema, controller, route, service or store in stackiq. The data is Nextcloud Collectives pages; the link rows are OpenRegister's (`lib/Service/CollectiveLinkService.php`, `lib/Db/CollectiveLinkMapper.php:51` `findByObjectUuid` in OpenRegister).

## Decisions

### D1. Articles are Collectives pages, linked through the OpenRegister leaf

ADR-022's leaf catalogue maps "knowledge / wiki pages" to Collectives, leaf id `collectives`, and says an app MUST consume the leaf before building its own model. The leaf fits the row: a page is an article with a title, body, history and sharing in Collectives; OpenRegister links it to an object (`POST /api/objects/{register}/{schema}/{id}/collectives`, or `.../collectives/new` to create and link, OpenRegister `appinfo/routes.php:888` and `:889`); and the library draws the linked pages on the detail page with collective, emoji, last change and a deep link (`src/integrations/builtin/collectives.js`, tab `CnCollectivesTab`, card `CnCollectivesCard`). None of ADR-022's exceptions applies: there is no legal or statutory requirement the leaf misses.

Rejected: a `knowledgeArticle` schema in the stackiq register with a title, markdown body, applications and category. It would be the "parallel data model" ADR-022 names as an anti-pattern, and it would need its own editor, history and sharing, which Collectives already has.

Rejected: the `xwiki` leaf. It needs an outside xWiki and credentials held by integriq (ADR-091). Collectives runs inside Nextcloud.

### D2. The application page keeps Documentation and adds Knowledge articles

`md-files` (`src/manifest.json:501`) stays: attached files such as a manual PDF are documents, not articles. `md-knowledge` is placed in a new full-width row below `md-compliance` and `md-versions` (`gridY` 12, width 12), so the ADR-062 grid of the existing rows does not move. The widget declares `requiredApp: collectives`; `CnDetailPage` keeps such a widget and shows a set-up state when the app is missing (`src/components/CnDetailPage/CnDetailPage.vue:3780` in the library), which tells an administrator what to install instead of leaving a gap.

### D3. The Knowledge base page is a `kb-search` widget on OpenRegister's page search

The library's `kb-search` widget (`src/components/CnKbSearchWidget/index.js:14`) runs a provider; the built-in `default` provider sends `GET <endpoint>?<queryParam>=<term>&limit=<n>` and reads `{ results }` (`src/utils/kbSearchProviders.js` `defaultKbProvider` and `normaliseKbResults`). OpenRegister's `GET /apps/openregister/api/integrations/collectives/available?search=` returns `{ results, total }` with `title` and `url` per page, over the collectives the user is a member of (`lib/Controller/CollectiveLinksController.php:272` in OpenRegister). So the page is configuration only: `content.endpoint` that URL, `content.queryParam` `search`. When Collectives is missing the endpoint answers 501 (`:273` to `:277`), the provider throws, and the widget shows its unavailable text.

Rejected: a stackiq search endpoint over the linked pages. OpenRegister exposes links per object only (`findByObjectUuid`); a cross-object listing would need a new OpenRegister route, and the per-user collective search already answers "find the article".

### D4. The menu entry is a child of Applications

ADR-097 caps the main menu at six top-level entries and stackiq has fifteen. The fragment declares `KnowledgeBaseMenu`, and `src/menu-layout.json` relocates it under `Modules`, the way `Komplianties` and `ComplianceMatrix` are relocated under `ReportsCompliance` today. `visibleIf.appInstalled: collectives` hides it where Collectives is absent, the same key the Integrations entry uses for integriq (`src/manifest.d/connection-registry.json`).

## Declarative versus imperative

The change adds a relation widget and a search widget, both declared in the manifest (ADR-024, ADR-031). The relation between an application and an article is OpenRegister's link table behind the leaf; stackiq writes no code for it.

## Seed data

No schema changes. The demo import cannot create Collectives pages, because they belong to a user's collective. The e2e test creates a collective and a page through the Collectives app on the test instance, then links it from the application page.

## Risks

- **Collectives on CI.** The e2e test needs the Collectives app on the test instance. Where it is absent the test asserts the set-up state and the hidden menu entry instead, so the suite stays meaningful on both.
- **Title search only.** The stackiq page matches titles (OpenRegister `lib/Service/CollectiveLinkService.php:537`). The hint under the search box says so and links to Collectives for full text search.
- **A layout row next to other changes.** `landscape-application-page` also adds rows to `ModuleDetail`. Whichever lands second moves its `gridY` below the other's; both are appended rows, so nothing overlaps.
