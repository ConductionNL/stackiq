# Design: sharing-generated-api-docs

Read at development `9a5ece6a`, OpenRegister development `4fee776`.

## Context

Two APIs serve the catalogue. The objects themselves (applications, services, usages, connections, contracts) are OpenRegister objects in the `stackiq` register, served by OpenRegister's objects API and described by OpenRegister's generated OpenAPI document (`GET /apps/openregister/api/registers/{id}/oas`, `openregister appinfo/routes.php:1670`, public and rate limited at `lib/Controller/OasController.php:82-92`). Stackiq adds its own endpoints on top (`appinfo/routes.php`, 132 routes), among them public ones for offers (`AanbodController`), offered usage (`AangebodenGebruikController`, including `GET /api/koppelingen-gebruik/{uuid}` at :269) and views (`ViewController`). Those are described only by hand (`ViewController.php:373`, `AangebodenGebruikController.php:866`, `docs/API_REFERENCE.md`), and `openapi.json` holds no paths.

## D1. Generate stackiq's own spec with the Nextcloud extractor

Add `nextcloud/openapi-extractor` as a dev dependency (in `vendor-bin/openapi-extractor/composer.json`, the Nextcloud convention, so it does not touch the runtime lock) and point `composer.json:43` at `vendor-bin/openapi-extractor/vendor/bin/generate-spec`. Annotate the external controllers so the extractor can read them: `#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]` on public and user-facing controllers, `#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]` on admin settings controllers, and psalm return shapes (`@return JSONResponse<200, array{...}, array{}>`) with `@psalm-type` definitions in a new `lib/ResponseDefinitions.php`, the file the extractor reads for shared schemas.

A new composer script `openapi:check` regenerates into a temp file and fails when it differs from the committed `openapi.json`; `check:strict` runs it after `phpstan`.

Rejected: writing the spec by hand. The hand-written endpoints are the problem the row names: they drift, and nothing checks them.

## D2. One documentation page, two documents

New page `ApiDocumentation` (`/api-docs`), a custom view `src/views/ApiDocumentationView.vue`, with a footer menu entry beside Documentation (`src/manifest.json:183`). Two tabs through the library's `CnTabs`:

- Catalogue objects: fetches `/apps/openregister/api/registers/{stackiq register id}/oas` (the id from the app's register resolver).
- Stackiq endpoints: fetches the bundled `openapi.json`, served by a small `GET /api/openapi` route (`ApiDocsController`, public, rate limited like OpenRegister's).

A reader component `src/components/api/OpenApiReader.vue` renders a document in the app: operations grouped by tag, each with method, path, parameters, request body and responses, and schemas with their properties, plus a Download JSON button.

Rejected: opening the hosted Redoc viewer as OpenRegister's admin screen does. It loads remote script from a third party and sends the document URL there, which a government instance should not do by default.

## D3. The hand-written endpoints

`GET /api/views/docs` and `GET /api/aangeboden-gebruik/docs` keep answering, with their current body plus a `documentation` link to `/apps/stackiq/api-docs`, and their docblocks marked deprecated. `docs/API_REFERENCE.md` gets a first paragraph that points to the page and the two OpenAPI files.

## Declarative versus imperative

The catalogue objects' document is OpenRegister's, generated from the schemas. Stackiq's own document is generated from annotations at build time. The page only reads.

## Seed data

None.

## Risks

- The extractor refuses controllers with untyped responses; the first run lists them. Task 1 annotates the public controllers first, then the user-facing ones.
- The OpenRegister document grows with the register; the reader renders operations lazily per tag.
