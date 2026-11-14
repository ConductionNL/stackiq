---
kind: code
depends_on: []
---

# Proposal: platform-properties

## Summary

Seven capability rows describe what stackiq gets from the platform it runs on rather than from its own code: it runs on your own Nextcloud, its data is reachable through a REST API, its look follows the Nextcloud theme, entries reach outside systems through flows and webhooks, every record keeps a history, and the store adds configuration from other organisations. All seven are built, but no requirement says them, so nothing can cite them and nothing stops a later change from breaking one. This change writes them down as a `platform-properties` spec, one requirement per property, each naming who provides it and how stackiq inherits it. pipelinq and shillinq solved the same rows this way.

Two of the seven are drawn on board SkInstellingenUitwisseling with a stackiq half that is not built yet: a Webhooks section in the exchange settings and the "Pas versturen vanaf levenscyclusstatus" field on the service desk exchange. The board is the canon (decision 130), so the tasks build those two halves.

## The rows this covers

Source: stackiq `openspec/parity/capabilities.json`.

- **share-self-hosted** Run the catalogue on your own infrastructure instead of the vendor's cloud. Provider: Nextcloud. Built: `appinfo/info.xml`, `stackiq-compose.yaml`.
- **share-rest-api** Read and write catalogue data through a REST API. Provider: OpenRegister objects API, plus stackiq's own `/api/*` routes in `appinfo/routes.php`.
- **ops-own-house-style** Apply the organisation's own colours and house style to the interface. Provider: Nextcloud theming (and thematiq).
- **share-lifecycle-conditional-sync** Send entries to connected outside systems only once they reach a chosen lifecycle state. Provider: OpenRegister flows (trigger plus filter node), integriq source call. Board: SkInstellingenUitwisseling.
- **share-webhooks** Notify another system automatically when a catalogue entry changes. Provider: OpenRegister webhooks; stackiq ships the ITSM outbound flow template. Board: SkInstellingenUitwisseling.
- **ins-audit-trail** Look back at who changed what in the catalogue, and when. Provider: OpenRegister audit trail, shown in the History tab of 14 detail pages. Board: SkApplicatie (tab Historie).
- **ops-plugins** Extend the product with plugins installed from a marketplace. Provider: the OpenRegister store plane, on the stackiq Store page. Board: SkFunctiesRoadmap (tab Uitbreidingen); the tab itself is built by the change `features-roadmap-page`.

## What changes

1. A new main capability `platform-properties` with seven requirements (REQ-PLAT-001 to REQ-PLAT-007).
2. `@spec` tags on the files that carry each property.
3. Checks that keep the properties true: an app-info test, a route posture test and a stylelint rule against hard-coded colours (a grep on development finds hex colours in 10 `.vue` files).
4. The two missing halves drawn on SkInstellingenUitwisseling: the Webhooks section and the lifecycle threshold on the service desk exchange.

## Screens

See `screens.md`: SkInstellingenUitwisseling, SkApplicatie, SkFunctiesRoadmap.

## Out of scope

- Theming itself (Nextcloud and thematiq own it).
- OpenRegister's webhook delivery, retries and signing (OpenRegister owns them; stackiq only lists and creates webhooks for its own schemas).
- A catalogue-wide audit page. The overlay lists `audit-trail-view` as provided by OpenRegister.
