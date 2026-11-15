---
kind: code
depends_on: []
---

# Generated documentation of the catalogue API

## Summary

A developer at a municipality or a supplier reads the catalogue API from generated documentation instead of hand-written pages: the catalogue objects as OpenRegister describes them, and stackiq's own endpoints as the code describes them. Both open from one API documentation page in stackiq, and both download as OpenAPI files. The generated file in the repository stops being empty.

## Why

Row from the stackiq matrix:

- `stackiq:share-api-docs`, "Read generated documentation of the catalogue API." Rated partial, built. Four competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/sap-leanix-apis, "we provide the OpenAPI explorer"), BlueDolphin (https://help.bluedolphin.io/en/articles/11967733-quick-start-guide, Swagger documentation of the public API), GLPI (source read at 11.0.9, `src/Glpi/Api/HL/Controller/CoreController.php:322` serves a Swagger UI over the spec `src/Glpi/Api/HL/OpenAPIGenerator.php` builds) and TOPdesk (https://developers.topdesk.com/). The missing half: a generated OpenAPI description of the catalogue API.

No tender, feature request or roadmap row names it.

## What stackiq has today

- `openapi.json` at the repository root has an `info` block and no paths. `composer.json:43` names a script `openapi` that calls `generate-spec`, which no installed package provides, and no controller carries the annotations an extractor reads.
- Two hand-written JSON documentation endpoints: `GET /api/views/docs` (`lib/Controller/ViewController.php:373`, login only) and `GET /api/aangeboden-gebruik/docs` (`lib/Controller/AangebodenGebruikController.php:866`, public), plus markdown in `docs/API_REFERENCE.md` and `docs/View_API.md` on the docs site. No page in `src/` calls either endpoint.
- `appinfo/routes.php` registers 132 routes on stackiq's own controllers.
- OpenRegister already generates an OpenAPI document per register: `GET /apps/openregister/api/registers/{id}/oas` (openregister `appinfo/routes.php:1670`, public with a rate limit, `lib/Controller/OasController.php:82-92`), and opens it in a hosted Redoc viewer from its own admin screens (`src/views/register/RegistersIndex.vue:670`). Stackiq does not surface it.

## What this change builds

1. Generated `openapi.json` for stackiq's external endpoints (offers, offered usage, usages, views, contact persons, facets, portfolio report, publication), through `nextcloud/openapi-extractor` and the annotations it reads, with a check in `composer check:strict` that the committed file is current.
2. An API documentation page in stackiq (footer menu, next to Documentation) with two tabs: Catalogue objects (OpenRegister's document for the stackiq register) and Stackiq endpoints (the generated file), rendered in the app and downloadable as JSON.
3. The two hand-written documentation endpoints answer with a pointer to the generated documents, and `docs/API_REFERENCE.md` links to the page.

## Out of scope

- A try-it console with credentials: the matrix category says stackiq is not a developer portal, and integriq's `access-developer-portal-and-subscriptions` covers keys and subscriptions for its gateway.
- Documenting admin-only settings endpoints; they are internal.
- Versioning the API; OpenRegister's `/api/versions/{version}/oas` covers its own contract.

## Risks

- Annotating many controllers is broad work. The tasks split it by controller so each pull request stays small, and the freshness check starts once the first controllers are annotated.
