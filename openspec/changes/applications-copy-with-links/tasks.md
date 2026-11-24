# Tasks: applications-copy-with-links

## Implementation tasks

### Task 1: Switch the copy with links on (board SkApplicaties)
- **spec_ref**: openspec/changes/applications-copy-with-links/specs/application-copy/spec.md#requirement-an-application-is-copied-with-the-links-the-user-picks-req-acp-001
- **files**: `src/manifest.json`, `src/views/FacetedCatalogIndexView.vue`, `package.json`, `package-lock.json`, `tests/vitest/facetedIndexCopy.spec.js`
- **acceptance_criteria**:
  - GIVEN the Modules and Diensten pages WHEN read THEN their config has `copy.include: ["relationRows", "incoming", "files"]`
  - GIVEN `FacetedCatalogIndexView` WHEN it renders its embedded `CnIndexPage` THEN it passes the page's `copy` config through, so single and mass copy show the link list
  - GIVEN `@conduction/nextcloud-vue` WHEN installed THEN it is the release that ships `index-copy-with-relations`
- [x] Implement
- [x] Test (vitest `facetedIndexCopy.spec.js`)

### Task 2: A copy starts unpublished

Unblocked (17 Nov): `@conduction/nextcloud-vue` 2.181.0, the release stackiq resolves, reads `config.copy.overrides` and applies it to the single, mass, with-links and fields-only copy (`selfModeActions.js` `copyOverridesOf`). The Modules and Diensten pages set `publicationDate` and `depublicationDate` to null.
- **spec_ref**: openspec/changes/applications-copy-with-links/specs/application-copy/spec.md#requirement-an-application-is-copied-with-the-links-the-user-picks-req-acp-001
- **files**: `src/manifest.json`, `tests/vitest/facetedIndexCopy.spec.js`
- **acceptance_criteria**:
  - GIVEN a copy WHEN created THEN the overrides clear `publicationDate` and `depublicationDate` (the library's copy overrides config), so the copy is not published with the source
- [x] Implement
- [x] Test (vitest `facetedIndexCopy.spec.js`)

### Task 3: Live check once OpenRegister's copy endpoint lands
- **spec_ref**: openspec/changes/applications-copy-with-links/specs/application-copy/spec.md#requirement-an-application-is-copied-with-the-links-the-user-picks-req-acp-001
- **files**: `tests/e2e/spec-coverage/application-copy.spec.ts`
- **acceptance_criteria**:
  - GIVEN an instance with the OpenRegister copy endpoint WHEN a user copies an application with connections ticked THEN the copy holds the same connections
  - GIVEN an instance without it WHEN the dialog opens THEN the links show read only with the note
- [x] Implement (17 Nov: `tests/e2e/spec-coverage/application-copy.spec.ts` written, one test per scenario, each skipping with the reason when the instance offers the other; OpenRegister main now has the copy endpoint)
- [x] Test (Playwright `application-copy.spec.ts`) (e2e written, runs in nightly; not verified on a live instance)
