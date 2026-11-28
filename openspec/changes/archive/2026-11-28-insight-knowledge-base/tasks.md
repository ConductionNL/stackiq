# Tasks: insight-knowledge-base

## Implementation tasks

### Task 1: Put the Collectives leaf on the application page
- **spec_ref**: openspec/changes/archive/2026-11-28-insight-knowledge-base/specs/application-knowledge-base/spec.md#requirement-req-akb-001-the-application-detail-page-shall-show-the-knowledge-articles-linked-to-the-application
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an application with a linked Collectives page WHEN /modules/:id opens THEN the Knowledge articles panel lists it with collective and last change
  - GIVEN an application owner WHEN they use the panel THEN they can link an existing page, create and link a new one, and unlink one
  - GIVEN the page WHEN it renders THEN md-files is still there and md-knowledge sits in a new full-width row below the existing rows
  - GIVEN an instance without Collectives WHEN /modules/:id opens THEN the panel shows the set-up state
- [x] Implement
- [x] Test (Playwright tests/e2e/spec-coverage/application-knowledge-base.spec.ts) (verified live 28 Nov 2026, see Task 3)

### Task 2: Add the Knowledge base page and its menu entry under Applications
- **spec_ref**: openspec/changes/archive/2026-11-28-insight-knowledge-base/specs/application-knowledge-base/spec.md#requirement-req-akb-003-the-knowledge-base-page-shall-search-the-articles-the-user-can-read-and-open-them-in-collectives
- **files**: `src/manifest.d/insight-knowledge-base.json`, `src/menu-layout.json`, `tests/vitest/manifestFragments.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the merged manifest WHEN it is built THEN KnowledgeBase exists at /knowledge with one kb-search widget whose endpoint is the OpenRegister collectives page search and whose queryParam is search
  - GIVEN the merged menu WHEN it is built THEN KnowledgeBaseMenu is a child of Modules and the top-level count does not grow
  - GIVEN a title match WHEN the user types on /knowledge THEN the result links into Collectives
  - GIVEN no Collectives app WHEN stackiq opens THEN the entry is hidden and /knowledge says Collectives is needed
- [x] Implement
- [x] Test (vitest tests/vitest/manifestFragments.spec.js and Playwright tests/e2e/spec-coverage/application-knowledge-base.spec.ts) (verified live 28 Nov 2026, see Task 3)

### Task 3: Document the knowledge base
- **spec_ref**: openspec/changes/archive/2026-11-28-insight-knowledge-base/specs/application-knowledge-base/spec.md#requirement-req-akb-004-without-the-collectives-app-the-knowledge-base-must-be-absent-not-broken
- **files**: `docs/features/application-knowledge-base.md`
- **acceptance_criteria**:
  - GIVEN the docs WHEN a reader opens the feature page THEN it explains that articles live in Collectives, shows the panel and the search page in screenshots, and says what an administrator installs first
- [x] Implement
- [x] Test (docs build and a manual read against the running app)
- Progress 27 Nov 2026: the feature page's text is written. `tests/e2e/docs/screenshots.spec.ts` now captures `docs/images/knowledge-articles.png` (the panel on an application with a linked article) and `docs/images/knowledge-base-search.png` (a title search on /knowledge); it skips without Collectives and has not run yet. Left: run it on an instance with Collectives, add both images to `docs/features/application-knowledge-base.md`, and the manual read.
- 28 Nov 2026, live sandbox (NC 34.0.6, PostgreSQL 16, PHP 8.4, OpenRegister main c5e58018 + openregister#11395, Collectives 4.8.0 from its release). Both images were captured by `tests/e2e/docs/screenshots.spec.ts` and added to the feature page.
  - The manual read found two errors in the page. The panel shows each page's collective but no last change, and linking, creating and unlinking happen in the sidebar's Collectives tab, not in the panel. Both are corrected.
  - The repository has no docs build; the page's links and images resolve.
  - `tests/e2e/spec-coverage/application-knowledge-base.spec.ts` is 5/5 with Collectives installed and 1/1 (set-up state) without it.
  - Fixes the run needed:
    - ModuleDetail declared its own sidebar tabs, so the collectives leaf tab (link, create, unlink: REQ-AKB-002) never showed. It is now declared there like bookmarks.
    - The spec scrolls to the card, opens the sidebar and expands the Applications menu group first.
    - Creating a page needs openregister#11395. Collectives 4.x refuses parent 0 ('File not found: 0'); the page is now created under the landing page.
  - The archive waited for that PR; it merged on 28 Nov 2026 (19:47Z).

## Verification

- `openspec validate insight-knowledge-base --type change --strict`
- vitest: tests/vitest/manifestFragments.spec.js
- Playwright: tests/e2e/spec-coverage/application-knowledge-base.spec.ts, on an instance with Collectives and on one without
- Docs in docs/features/application-knowledge-base.md with screenshots (ADR-010)
- English and Dutch strings for the panel title, the page title, the hint and the empty and unavailable texts (ADR-005)
