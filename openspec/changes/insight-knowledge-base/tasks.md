# Tasks: insight-knowledge-base

## Implementation tasks

### Task 1: Put the Collectives leaf on the application page
- **spec_ref**: openspec/changes/insight-knowledge-base/specs/application-knowledge-base/spec.md#requirement-req-akb-001-the-application-detail-page-shall-show-the-knowledge-articles-linked-to-the-application
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an application with a linked Collectives page WHEN /modules/:id opens THEN the Knowledge articles panel lists it with collective and last change
  - GIVEN an application owner WHEN they use the panel THEN they can link an existing page, create and link a new one, and unlink one
  - GIVEN the page WHEN it renders THEN md-files is still there and md-knowledge sits in a new full-width row below the existing rows
  - GIVEN an instance without Collectives WHEN /modules/:id opens THEN the panel shows the set-up state
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/application-knowledge-base.spec.ts)

### Task 2: Add the Knowledge base page and its menu entry under Applications
- **spec_ref**: openspec/changes/insight-knowledge-base/specs/application-knowledge-base/spec.md#requirement-req-akb-003-the-knowledge-base-page-shall-search-the-articles-the-user-can-read-and-open-them-in-collectives
- **files**: `src/manifest.d/insight-knowledge-base.json`, `src/menu-layout.json`, `tests/vitest/manifestFragments.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the merged manifest WHEN it is built THEN KnowledgeBase exists at /knowledge with one kb-search widget whose endpoint is the OpenRegister collectives page search and whose queryParam is search
  - GIVEN the merged menu WHEN it is built THEN KnowledgeBaseMenu is a child of Modules and the top-level count does not grow
  - GIVEN a title match WHEN the user types on /knowledge THEN the result links into Collectives
  - GIVEN no Collectives app WHEN stackiq opens THEN the entry is hidden and /knowledge says Collectives is needed
- [ ] Implement
- [ ] Test (vitest tests/vitest/manifestFragments.spec.js and Playwright tests/e2e/spec-coverage/application-knowledge-base.spec.ts)

### Task 3: Document the knowledge base
- **spec_ref**: openspec/changes/insight-knowledge-base/specs/application-knowledge-base/spec.md#requirement-req-akb-004-without-the-collectives-app-the-knowledge-base-must-be-absent-not-broken
- **files**: `docs/features/application-knowledge-base.md`
- **acceptance_criteria**:
  - GIVEN the docs WHEN a reader opens the feature page THEN it explains that articles live in Collectives, shows the panel and the search page in screenshots, and says what an administrator installs first
- [ ] Implement
- [ ] Test (docs build and a manual read against the running app)

## Verification

- `openspec validate insight-knowledge-base --type change --strict`
- vitest: tests/vitest/manifestFragments.spec.js
- Playwright: tests/e2e/spec-coverage/application-knowledge-base.spec.ts, on an instance with Collectives and on one without
- Docs in docs/features/application-knowledge-base.md with screenshots (ADR-010)
- English and Dutch strings for the panel title, the page title, the hint and the empty and unavailable texts (ADR-005)
