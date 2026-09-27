---
kind: code
depends_on: []
---

# Knowledge articles on applications, kept in Nextcloud Collectives

## Summary

An application owner who knows how an application is set up, how to recover it or what to tell new users has nowhere in stackiq to write that down. The application page has a Documentation files panel, and files are not articles anyone can search. This change keeps knowledge articles in Nextcloud Collectives, the knowledge base Nextcloud already ships, links them to the application through OpenRegister's `collectives` leaf, lists them on the application page, and adds a Knowledge base page under Applications where a user searches the articles without leaving stackiq.

## Why

This change covers one matrix row.

- `stackiq:ins-kb`, "Keep knowledge articles about applications in a searchable knowledge base." Stackiq rates itself no. GLPI rates yes from its source at 11.0.9: `src/KnowbaseItem.php:57` knowledge base articles with categories and visibility, linked to items through `src/KnowbaseItem_Item.php:45` and shown on the appliance Knowledge base tab (`src/Appliance.php:104`); on the lab /front/knowbaseitem.php opens the knowledge base with Search and Browse. TOPdesk rates yes: "The Knowledge Base is set up and managed by your organization's knowledge managers. Every operator is able to use information from the Knowledge Base" (https://docs.topdesk.com/en/knowledge-management.html).

The row has no stackiq half today and is built new.

## What stackiq has today

Read at development 49e65cb4, with `@conduction/nextcloud-vue` 2.57.1 and OpenRegister development 4fee776.

- No article schema in `lib/Settings/softwarecatalogus_register.json`. Its schemas are sector, suite, catalogService, vulnerability, contactPerson, organization, usage, catalogContract, connection, software-review, element, view, model, property-definition, relation, module, compliancy, bioMeasure, moduleVersion and sbomComponent.
- `ModuleDetail` (`src/manifest.json:491`) has `md-files`, an `integration` widget on the `files` leaf titled Documentation (`:501`). Files can be attached, but they are not articles and stackiq does not search them.
- OpenRegister already ships a `collectives` leaf. It links an object to Nextcloud Collectives pages, creates a new page in a collective and links it, and lists the linked pages (routes `/api/objects/{register}/{schema}/{id}/collectives` and `/api/integrations/collectives/available` in OpenRegister `appinfo/routes.php:885` to `:890`, `lib/Service/CollectiveLinkService.php`). The library registers the matching tab and card (`src/integrations/builtin/collectives.js:37`, id `collectives`, required app `collectives`).
- The library also ships a `kb-search` dashboard widget (`src/components/CnKbSearchWidget/index.js:14`) whose default provider calls a configured endpoint with a configured query parameter (`src/utils/kbSearchProviders.js` `defaultKbProvider`).
- ADR-022 maps "knowledge / wiki pages" to the Collectives leaf and requires an app to consume the leaf rather than build its own store.

## What this change builds

- A Knowledge articles panel on the application page: the `collectives` leaf, where an application owner links an existing Collectives page or creates one, and every reader sees the linked articles with their collective, last change and a link into Collectives.
- A Knowledge base page at `/knowledge`, a child of the Applications menu entry, with a search box over the Collectives pages the user can read. A result opens the article in Collectives.
- Without the Collectives app the menu entry is hidden, and the panel on the application page shows the library's set-up state, which tells an administrator what to install.

## Out of scope

- The article store, editor, versions and sharing. They are Nextcloud Collectives. Who can read an article is the collective's membership, not a stackiq role.
- Full text search in article bodies from the stackiq page. OpenRegister's page search matches titles (`lib/Service/CollectiveLinkService.php:537` in OpenRegister); searching bodies is Collectives' own search, one click away.
- Limiting the search to one collective chosen by an administrator. A follow-up can add that as a provider option once there is demand.
- Articles on services, contracts or organisations. The same leaf fits their detail pages later; the row asks for applications.
- An xWiki knowledge base. OpenRegister has an `xwiki` leaf for organisations that use xWiki; this change uses Collectives because it runs inside Nextcloud and needs no outside credentials (ADR-091).

## Risks

- The knowledge base needs the Collectives app. On an instance without it the feature is absent, not broken: the menu entry is hidden, the panel shows a set-up state, and the page says Collectives is needed if reached by URL.
- Articles live under Collectives' access rules. A user who is not a member of the collective can see a linked title on the application page, and Collectives refuses when they open it. This change does not copy Collectives' membership into stackiq.
