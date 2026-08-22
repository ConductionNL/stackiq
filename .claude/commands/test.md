---
name: "SWC: Test"
description: Run automated tests for the GEMMA Softwarecatalogus — API tests (Postman/Newman), browser tests (persona agents), issue processing, or all
category: Testing
tags: [testing, softwarecatalogus, newman, playwright, persona]
---

Base directory for this skill: /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/stackiq

# Test Softwarecatalogus — Orchestrator

Run automated tests for the GEMMA Softwarecatalogus. Supports four test modes:

1. **API tests** — Fast, low-cost Newman/Postman tests covering ~327 `[API]`-tagged acceptance criteria via HTTP assertions
2. **Browser tests** — Thorough persona-based browser tests covering ~554 `[UI]`-tagged + ~28 `[HYBRID]`-tagged criteria
3. **Both** — Run API tests first, then browser tests for complete ~909 criteria coverage
4. **Open issues** — Issue-by-issue verification of all 72 open IGS issues, preparing GitHub reply comments with proof

**Input**: Optional argument after `/swc:test`:
- No argument → ask which test type to run
- `api` → run all API tests (Newman)
- `api:folder-name` → run a specific API test folder (e.g., `api:02 - RBAC & Organization Scoping`)
- `browser` → run all 7 browser persona agents
- `all` → run API tests first, then browser tests
- `issues` → process all open issues (prepare reply comments with proof)
- `issues:15,65,73` → process specific issues by number
- `issues:bug` → process only issues of category Bug
- `issues:datakwaliteit` → process only Datakwaliteit issues
- `issues:tekstueel` → process only Tekstueel issues
- `issues:wens` → process only Wens issues
- Comma-separated persona names → run only those browser agents (e.g., `leverancier,gemeente,bezoeker`)
- `summary-only` → regenerate the summary report from existing results without re-running tests

**Valid persona names** (for browser tests): `leverancier`, `gemeente`, `security-officer`, `functioneel-beheerder`, `samenwerking`, `architectuur-expert`, `bezoeker`

**API test folders** (for `api:folder-name`):
| Folder | Issues Covered |
|--------|---------------|
| `00 - Setup` | Test data creation (users, orgs, objects) |
| `01 - Public API & Search` | #85, #144, #315, #343, #344, #345, #346, #440 |
| `02 - RBAC & Organization Scoping` | #105, #300, #307, #394, #414 |
| `03 - Object CRUD` | #6, #65, #73, #365, #382, #400, #437 |
| `04 - Data Migration & Import` | #23, #435 |
| `05 - ArchiMate & Views` | #148, #160, #393, #413 |
| `06 - User Profile & Authentication` | #266, #286, #352, #353, #396 |
| `07 - Export & Reporting` | #15 |
| `08 - Aanbod & Gebruik` | #354, #402, #418, #419, #420 |
| `09 - Data Quality & Naming` | #186, #347, #381, #406, #407, #409 |
| `10 - Glossary & Content` | #155, #332 |

### API Test Execution

When running API tests, use Newman CLI:

```bash
# Install Newman if needed
which newman || npm install -g newman newman-reporter-htmlextra

# Run setup first (creates test data)
newman run stackiq/postman/softwarecatalogus-tests.json \
  -e stackiq/postman/environment-local.json \
  --folder "00 - Setup" --reporters cli 2>&1 | tail -20

# Run all test folders
newman run stackiq/postman/softwarecatalogus-tests.json \
  -e stackiq/postman/environment-local.json \
  --reporters cli,htmlextra \
  --reporter-htmlextra-export stackiq/test-results/api/report.html 2>&1

# Run a specific folder
newman run stackiq/postman/softwarecatalogus-tests.json \
  -e stackiq/postman/environment-local.json \
  --folder "{folder-name}" --reporters cli 2>&1
```

**For custom environments**, pass variables:
```bash
newman run stackiq/postman/softwarecatalogus-tests.json \
  -e stackiq/postman/environment-local.json \
  --env-var "base_url={BACKEND}" \
  --env-var "admin_user={ADMIN_USER}" \
  --env-var "admin_pass={ADMIN_PASS}" \
  --reporters cli 2>&1
```

Write API results to `stackiq/test-results/api/results.md`.

---

## Step -1: Test Type & Environment Configuration

### Question 1: Test Type

If no argument was provided (or argument is empty), ask the user using AskUserQuestion:

**Question**: "Which tests do you want to run?"
| Option | Label | Description |
|--------|-------|-------------|
| 1 | **API tests (Recommended)** | Fast Newman/Postman tests — ~327 criteria, ~2 min, low cost. Covers all `[API]`-tagged acceptance criteria. |
| 2 | **Browser tests** | Full persona-based browser testing — ~582 criteria, ~30 min, high token cost. Covers `[UI]` and `[HYBRID]` criteria with 7 parallel agents. |
| 3 | **Both** | API tests first, then browser tests — complete ~909 criteria coverage. |
| 4 | **Open issues** | Process open IGS issues — prepare GitHub reply comments with proof. |
| 5 | **Specific API folder** | Run just one API test category (e.g., RBAC, CRUD, Search). |

If user selects **Specific API folder**, ask which folder (show the API test folders table above).

### Question 2: Environment

Ask the user about the target environment using AskUserQuestion:

**Question**: "Which environment do you want to test against?"
- **Local development (Recommended)** — Frontend: localhost:3000, Backend: localhost:8080, Admin: admin/admin
- **Custom environment** — I'll provide URLs and credentials

If the user selects **Custom environment**, ask follow-up questions **one at a time**:
1. "What is the frontend URL?" (e.g., `https://softwarecatalogus.accept.opencatalogi.nl`)
2. "What is the backend URL?" (e.g., `https://softwarecatalogus.accept.commonground.nu`)
3. "What are the admin credentials? (format: username:password)"

Store the resolved values as `{FRONTEND}`, `{BACKEND}`, `{ADMIN_USER}`, `{ADMIN_PASS}`.

For **Local development**, use:
- `{FRONTEND}` = `http://localhost:3000`
- `{BACKEND}` = `http://localhost:8080`
- `{ADMIN_USER}` = `admin`
- `{ADMIN_PASS}` = `admin`

Replace all URL references in the shared context and sub-agent prompts with these values.

---

## Shared Context (inject into every sub-agent)

All sub-agents share this context:

### Environment

> **LOCAL TEST ONLY** — All credentials in this file and the persona skill files are for the local development environment only. They do NOT work on production or acceptance environments.

- **Frontend**: {FRONTEND}/
- **Backend**: {BACKEND}/
- **Login URL**: {FRONTEND}/login
- **Backend Admin**: {BACKEND}/ ({ADMIN_USER}:{ADMIN_PASS})

### OAS Documentation URLs
These auto-generated OpenAPI specs document the available API endpoints and schemas:
- **Voorzieningen register (id=2)**: {BACKEND}/index.php/apps/openregister/api/registers/2/oas
- **GEMMA/AMEFF register (id=4)**: {BACKEND}/index.php/apps/openregister/api/registers/4/oas

Use these when testing issues related to API access, OAS documentation, or public API availability (e.g., #85, #148).

### Login Procedure
1. Navigate to {FRONTEND}/login
2. **Before entering credentials**: Use `browser_evaluate` to run `localStorage.clear()` — this removes stale sessions from previous agents
3. Enter the persona's username and password
4. Verify the dashboard loads after login

### Screenshot-Based Acceptance Criteria (Image Comparison)
Many issues (especially wizard text/label issues) include **reference screenshots** from PowerPoint presentations showing the EXPECTED text. When an acceptance criterion says "**Image comparison**":
1. **Fetch the reference image** from the GitHub URL in the criterion using `WebFetch` — Claude can read the image
2. **Navigate to the relevant wizard step/page** in the browser
3. **Take a screenshot** of the current UI using `browser_take_screenshot`
4. **Compare visually** — extract text from both the reference image and the live screenshot, then compare labels, titles, tooltips, field names character by character
5. Mark each text element as MATCH or MISMATCH in the results

The authoritative source document is the PowerPoint attached to issue #329.

### Console Log Monitoring
After EVERY page navigation and EVERY significant user action (click, form submit, wizard step), check console logs:
1. Call `browser_console_messages` with level `"error"`
2. Record ALL errors in the test results under a **Console Errors** section per issue
3. Ignore known/expected errors (list below)
4. Any unexpected console error is a finding — mark as severity MEDIUM minimum

**Known/expected errors to ignore:**
- `Failed to load resource: the server responded with a status of 404` for favicon.ico
- `ResizeObserver loop` warnings (browser noise)
- Service worker registration failures in development mode

### Network Performance Monitoring
After EVERY page navigation, check network performance:
1. Call `browser_network_requests` with `includeStatic: false`
2. For each API call (XHR/fetch), check the response time
3. Flag any call that takes **>500ms** as a **SLOW** call
4. Flag any call that takes **>1000ms** as a **PERFORMANCE_FAIL**
5. Record ALL slow/failed calls in the test results under a **Performance** section

**Performance thresholds:**
| Response Time | Classification | Action |
|---------------|---------------|--------|
| 0–500ms | OK | No action |
| 500ms–1000ms | SLOW | Record in results, severity LOW |
| >1000ms | PERFORMANCE_FAIL | Record in results, severity MEDIUM |

**Exceptions (allowed to exceed 1000ms):**
- Initial page load / first navigation after login
- OAS documentation endpoints (`/api/registers/*/oas`) — these generate specs on-the-fly
- Excel/CSV export downloads (`/api/*/export`)
- ArchiMate/AMEFF import/export operations
- Search queries with >5 active filters

### Acceptance Criteria
Before testing each issue, read its detailed acceptance criteria in `stackiq/issues.md`. Each issue has specific, testable acceptance criteria with checkboxes. Use these to determine status:
- **PASS** = ALL acceptance criteria are met
- **PARTIAL** = Some criteria met, some not
- **FAIL** = Key criteria not met or feature is broken
- **CANNOT_TEST** = Feature not accessible or environment issue prevents testing

### CMS Page Management
CMS pages (privacy, terms, FAQ, disclaimer) are managed in the **OpenCatalogi** Nextcloud backend app:
- **Pages URL**: {BACKEND}/index.php/apps/opencatalogi/pages#
- **Themes URL**: {BACKEND}/index.php/apps/opencatalogi/themes#
- **IMPORTANT**: The URL pattern is `/apps/opencatalogi/pages#` (NOT `/#/pages`)
- **Features**: Create, edit, delete, copy pages with title, slug, summary, description
- **Public API**: `GET /index.php/apps/opencatalogi/api/pages/{slug}`
- Relevant for issues: #397 (CMS page creation), #332 (front page), themes management

### RBAC Reference
The authoritative RBAC rules are defined in the register JSON configuration:
- **File**: `stackiq/lib/Settings/softwarecatalogus_register.json`
- Each schema has an `"authorization"` block with `create`, `read`, `update`, `delete` rules
- Rules can be simple group names (e.g., `"public"`, `"gebruik-beheerder"`) or conditional: `{ "group": "aanbod-beheerder", "match": { "_organisation": "$organisation" } }` (only own org's data)

**Key RBAC rules for testing:**

| Schema | Public Read | aanbod-beheerder Read | gebruik-beheerder Read |
|--------|------------|----------------------|----------------------|
| **contactpersoon** | NO (but leverancier contact persons ARE expected to be publicly visible via publications) | Own org only | ALL |
| **module** (applicatie) | Only where `geregistreerdDoor: Leverancier` | Own org only | ALL |
| **koppeling** | NO | Own org only | ALL |
| **gebruik** | NO | Own org only | ALL |
| **organisatie** | YES (all) | ALL | ALL |
| **dienst** | YES (all) | ALL | ALL |

**Important RBAC notes for agents:**
- **Contactpersonen of leveranciers are expected to be publicly visible.** Only gemeente/samenwerking contact persons should be hidden from public view. When testing #394, verify that ONLY leverancier contact persons are exposed — not gemeente ones.
- **Applicatielandschappen page may be visible** to aanbod-beheerder, but should only show applications belonging to their own organization. When testing #105, verify the page shows ONLY own-org data, not that the page itself is blocked.
- When unsure about RBAC, read the register JSON file directly to check the `authorization` block for the relevant schema.

### Test Data Cleanup (MANDATORY)
After all testing is complete, agents **MUST** clean up any objects they created during wizard walkthroughs and testing. This prevents data contamination that inflates counts and creates false-positive FAIL results in subsequent test runs.

**Cleanup procedure:**
1. Search for test objects created during the session using the publications API:
   ```
   GET {BACKEND}/index.php/apps/opencatalogi/api/publications?_search=Test+Wizard&_limit=50
   GET {BACKEND}/index.php/apps/opencatalogi/api/publications?_search=Test+Koppeling&_limit=50
   ```
2. For each object found that was created by your persona (check `@self.owner`), delete it:
   ```
   DELETE {BACKEND}/index.php/apps/openregister/api/objects/{register}/{schema}/{id}
   ```
   Where `register` and `schema` come from the object's `@self` metadata.
3. **Do NOT delete** objects created by the setup script (e.g., "Test Applicatie Leverancier", "Test Dienst Leverancier") — only delete wizard-created duplicates.
4. Record the cleanup in your results file under a "## Test Data Cleanup" section.

**Objects to clean up (by naming pattern):**
- "Test Wizard *" — any wizard-created test objects
- Objects with your persona's username as `@self.owner`
- Duplicate entries visible in beheer tables that didn't exist before your test

### Rules
- **READ ONLY on GitHub issues** — NEVER update, close, or comment on issues
- Write test results ONLY to local files in `stackiq/test-results/`
- Take screenshots as evidence where applicable
- **ALWAYS clean up test data** created during wizard walkthroughs (see Test Data Cleanup above)

---

## Persona Registry

| Key | Skill File | Persona | Role | Organization |
|-----|-----------|---------|------|--------------|
| `leverancier` | `test-leverancier.md` | Jan Pietersen | Aanbod-beheerder (Vendor) | Test Leverancier BV |
| `gemeente` | `test-gemeente.md` | Maria van der Berg | Gebruik-beheerder (Municipality) | Test Gemeente |
| `security-officer` | `test-security-officer.md` | Mark Jansen | Gebruik-beheerder (Security) | Test Gemeente |
| `functioneel-beheerder` | `test-functioneel-beheerder.md` | Peter van Dijk | Admin (Functional Manager) | (Default / admin) |
| `samenwerking` | `test-samenwerking.md` | Linda Bakker | Gebruik-beheerder (Collaboration) | Test Samenwerking |
| `architectuur-expert` | `test-architectuur-expert.md` | Dr. Sarah de Vries | VNG-raadpleger (Architecture) | (Default / VNG) |
| `bezoeker` | `test-bezoeker.md` | Anonymous Visitor | Bezoeker (Unauthenticated) | (none — public) |

---

## Steps

### Step 0: Environment Setup

Run the setup script to create test organizations, contact persons, user accounts, and link everything together. Pass the backend URL if using a custom environment:

```bash
# Local (default):
bash stackiq/test-setup.sh

# Custom environment:
BACKEND_URL="{BACKEND}" ADMIN_USER="{ADMIN_USER}" ADMIN_PASS="{ADMIN_PASS}" bash stackiq/test-setup.sh
```

This script creates:
- 6 Nextcloud user accounts with proper group assignments
- 4 organizations (Test Leverancier BV, Test Gemeente, Test Samenwerking, Test Leverancier 2)
- 4 contact persons linked to their organizations
- Joins each user to their org and sets it as active
- Clears rate limiting / brute force protection

The script is idempotent — it can be run multiple times safely (existing users/orgs are skipped).

**Skip this step** if running with `summary-only` argument or if you've already run the setup script in this session.

### Step 1: Parse Arguments

Read the argument provided after `/swc:test`:

- **No argument or empty**: Ask Question 1 (test type) from Step -1, then proceed accordingly
- **`api`** or **`api:folder-name`**: Run Newman API tests (see API Test Execution above)
- **`browser`**: Set `personas` to all 7
- **`all`**: Run API tests first, then browser tests
- **`issues`**: Run open issues workflow (see Steps 7-10 below)
- **`issues:15,65,73`**: Process only the specified issue numbers
- **`issues:bug`**: Process only open Bug issues
- **`issues:datakwaliteit`**: Process only open Datakwaliteit issues
- **`issues:tekstueel`**: Process only open Tekstueel issues
- **`issues:wens`**: Process only open Wens issues
- **`summary-only`**: Skip to Step 4 (summary generation)
- **Comma-separated persona names**: Parse into list, validate each against the persona registry

For `issues` mode, skip to **Step 7**. For all other modes, continue with Step 2.

### Step 1a: Run API Tests (Newman)

Run the Postman/Newman API test suite. This covers all `[API]`-tagged acceptance criteria.

**Prerequisites**: Newman must be installed. If not found, install it:
```bash
which newman || npm install -g newman newman-reporter-htmlextra
```

**After Newman completes**, parse the output and write results to `stackiq/test-results/api/results.md`:
- Total requests, assertions, passes, failures
- Per-folder pass/fail counts
- Failed test names with issue references (tests are named `#NNN AC: description`)
- Link to HTML report if generated

**If test mode is `all`**, continue to Step 2 for browser tests. Otherwise skip to Step 4.

### Step 2: Launch Browser Sub-Agents in Parallel

For each persona in the `personas` list, launch a Task agent **in parallel** (all in a single message with multiple Task tool calls). Use `subagent_type: "general-purpose"`.

**Browser assignment per persona** (use these when launching sub-agents):

| Persona | Browser |
|---------|---------|
| `leverancier` | `browser-1` |
| `gemeente` | `browser-2` |
| `security-officer` | `browser-3` |
| `functioneel-beheerder` | `browser-4` |
| `samenwerking` | `browser-5` |
| `bezoeker` | `browser-6` |
| `architectuur-expert` | `browser-7` |

Note: All 7 browsers are used. The bezoeker uses browser-6 (does not need headed mode since it's unauthenticated public testing).

**Sub-agent prompt template** (replace `{persona}` with the persona key and `{browser_num}` with the assigned browser number):

```
You are a testing agent for the GEMMA Softwarecatalogus.

Read and follow the instructions in the skill file at:
stackiq/.claude/skills/test-{persona}.md

This file contains your persona details, login credentials, test scope, and the list of issues to test.

## Browser Assignment

You MUST use browser-{browser_num} for ALL browser operations. Use tools prefixed with `mcp__browser-{browser_num}__`:
- `mcp__browser-{browser_num}__browser_navigate` to navigate
- `mcp__browser-{browser_num}__browser_click` to click
- `mcp__browser-{browser_num}__browser_snapshot` to take snapshots
- `mcp__browser-{browser_num}__browser_evaluate` to run JS
- `mcp__browser-{browser_num}__browser_fill_form` to fill forms
- `mcp__browser-{browser_num}__browser_take_screenshot` for screenshots
- etc. (all tools use the `mcp__browser-{browser_num}__` prefix)

If your assigned browser errors or is unresponsive, try the next available browser number (skip browser-6 which is headed).

## Additional Context

**IMPORTANT**: The skill file uses placeholder variables. Replace them with the values below:
- `{FRONTEND}` → {FRONTEND}
- `{BACKEND}` → {BACKEND}
- `{ADMIN_USER}` → {ADMIN_USER}
- `{ADMIN_PASS}` → {ADMIN_PASS}

### OAS Documentation URLs
When testing API-related issues (e.g., #85, #148), use these OAS documentation endpoints:
- Voorzieningen register: {BACKEND}/index.php/apps/openregister/api/registers/2/oas
- GEMMA/AMEFF register: {BACKEND}/index.php/apps/openregister/api/registers/4/oas

### Login Procedure
**For authenticated personas (all except bezoeker):**
1. Use `mcp__browser-{browser_num}__browser_navigate` to go to {FRONTEND}/login
2. IMPORTANT: Before entering credentials, use `mcp__browser-{browser_num}__browser_evaluate` to run: localStorage.clear()
   This removes stale sessions from previous tests.
3. Enter your persona's credentials (from the skill file)
4. Verify dashboard loads after login

**For bezoeker (unauthenticated):**
1. Use `mcp__browser-{browser_num}__browser_navigate` to go to {FRONTEND}/zoeken?_page=1
2. Use `mcp__browser-{browser_num}__browser_evaluate` to run: localStorage.clear()
3. Do NOT log in — all testing is done as an anonymous visitor

### Organization Context
Your persona is linked to a proper organization (not Default Organisation):
- Leverancier personas (jan.pietersen) → "Test Leverancier BV"
- Gemeente personas (maria.vanderberg, mark.jansen) → "Test Gemeente"
- Samenwerking personas (linda.bakker) → "Test Samenwerking"
- Admin/VNG personas (peter.vandijk, sarah.devries) → Default Organisation (expected for admin/VNG roles)
Organization-specific features (wizards, filters, dashboards) should work for your persona's org type.

### RBAC Reference
The authoritative RBAC rules are in `stackiq/lib/Settings/softwarecatalogus_register.json`.
Each schema has an `"authorization"` block. Key rules:
- **contactpersoon**: NOT public, but leverancier contact persons ARE expected to be publicly visible via publications. Only gemeente contact persons should be hidden.
- **module** (applicatie): Public can read only where `geregistreerdDoor: Leverancier`. aanbod-beheerder sees only own org.
- **koppeling**: NOT public. gebruik-beheerder sees all; aanbod-beheerder sees only own org.
- **gebruik**: NOT public. gebruik-beheerder sees all; aanbod-beheerder sees only own org.
- **organisatie**: Public readable by everyone.
When testing RBAC/visibility issues, read the register JSON for the exact rules.

### CMS Pages
CMS pages (privacy, terms, FAQ, disclaimer) are managed in the OpenCatalogi Nextcloud backend:
- URL: {BACKEND}/index.php/apps/opencatalogi/pages#
- Use this when testing CMS-related issues (#397, #403, #332).

### Wizard Execution — MANDATORY
**CRITICAL**: Authenticated agents (leverancier, gemeente) MUST execute their wizard flows BEFORE testing individual issues. The skill files contain detailed step-by-step walkthroughs.

- **Leverancier**: Must complete Applicatie publiceren, Dienst publiceren, and Koppeling publiceren wizards (all steps)
- **Gemeente**: Must complete Applicatie toevoegen wizard (all steps)
- **Both**: Document every wizard step with screenshots, noting field values entered and navigation behavior

The setup script also pre-creates test objects ("Test Applicatie Leverancier", "Test Dienst Leverancier", "Test Applicatie Gemeente") so beheer tables are never empty.

### Screenshot-Based Acceptance Criteria (Image Comparison)
When an acceptance criterion in issues.md says "**Image comparison**":
1. Fetch the reference image from the GitHub URL using WebFetch
2. Navigate to the relevant page in the browser
3. Take a screenshot using browser_take_screenshot
4. Compare text from both images — labels, titles, tooltips, field names
5. Mark each text element as MATCH or MISMATCH

### Console Log Monitoring
After EVERY page navigation and EVERY significant user action (click, form submit, wizard step):
1. Call `browser_console_messages` with level `"error"`
2. Record ALL errors in a **Console Errors** section per issue
3. Ignore these known/expected errors:
   - `Failed to load resource: the server responded with a status of 404` for favicon.ico
   - `ResizeObserver loop` warnings
   - Service worker registration failures in development mode
4. Any unexpected console error is a finding — severity MEDIUM minimum

### Network Performance Monitoring
After EVERY page navigation:
1. Call `browser_network_requests` with `includeStatic: false`
2. Check response times for all API calls (XHR/fetch)
3. Flag calls >500ms as **SLOW** (severity LOW)
4. Flag calls >1000ms as **PERFORMANCE_FAIL** (severity MEDIUM)

**Exceptions (allowed to exceed 1000ms):**
- Initial page load / first navigation after login
- OAS documentation endpoints (`/api/registers/*/oas`)
- Excel/CSV export downloads
- ArchiMate/AMEFF import/export
- Search queries with >5 active filters

At the END of your results file, include a Performance Summary:
```
## Performance Summary
- Total API calls monitored: {N}
- OK (<500ms): {N}
- SLOW (500ms-1s): {N}
- PERFORMANCE_FAIL (>1s): {N}
- Slowest call: {URL} — {time}ms
```

And a Console Errors Summary:
```
## Console Errors Summary
- Total pages/actions checked: {N}
- Pages with errors: {N}
- Total unique errors: {N}
- Most frequent error: {description} (seen {N} times)
```

### Testing Hints for Specific Issues
- **#399 (cross-vendor)**: Public search page → find "Test Applicatie Leverancier 2", click Versies tab, click a version. Verify no error.
- **#375 (SaaS version)**: After wizard, find the created app on `/zoeken?_page=1`, check Versies tab.
- **#105 (RBAC)**: Leverancier only — `/beheer/applicatielandschappen` should show ONLY own org's applications (data scoping, not page visibility).
- **#141 (merge)**: Functioneel-beheerder only — test via Nextcloud backend: OpenRegister → Search/Views → voorzieningen register → organisatie schema → three-dot menu → Merge.
- **#403 (delete dialog)**: Find a test object in beheer table, click delete, verify dialog text and usage check, click Cancel.
- **#15 (export)**: In beheer table, click Acties → Exporteren → Als CSV/Excel. Verify download.
- **#402 (Edge vs Chrome)**: **SKIP** — untestable (single Chromium engine).

### Test Data Cleanup (MANDATORY — do this AFTER all testing)
After completing all tests, you MUST clean up any objects you created during wizard walkthroughs:

1. Search for objects you created:
   ```bash
   curl -s -u {ADMIN_USER}:{ADMIN_PASS} '{BACKEND}/index.php/apps/opencatalogi/api/publications?_search=Test+Wizard&_limit=50'
   ```
   Also search for any other names you used during wizard testing (e.g., your test koppeling names).

2. For each object where `@self.owner` matches your username, delete it:
   ```bash
   curl -s -X DELETE -u {ADMIN_USER}:{ADMIN_PASS} '{BACKEND}/index.php/apps/openregister/api/objects/{register}/{schema}/{id}'
   ```
   Use the `register`, `schema`, and `id` values from the object's `@self` metadata.

3. **Do NOT delete** objects created by the setup script: "Test Applicatie Leverancier", "Test Dienst Leverancier", "Test Applicatie Gemeente", "Test Applicatie Leverancier 2".

4. Add a "## Test Data Cleanup" section to your results file documenting what was deleted.

**Why this matters:** Without cleanup, wizard re-runs create duplicate entries that cause false FAIL results for count-based issues (#300, #307).

### Acceptance Criteria
Before testing each issue, read its acceptance criteria from stackiq/issues.md.
The file contains detailed checkboxes for each issue. Use these to determine PASS/FAIL/PARTIAL/CANNOT_TEST.

### Output Format
Write your results to: stackiq/test-results/{persona}/results-authenticated.md

Use this format:
- Header with persona name, date, environment, login used
- Summary table: | Issue | Title | Previous Status | Current Status | Severity |
- Per-issue sections with acceptance criteria checkboxes marked [x] or [ ]
- Console Errors subsection per issue (if any errors found)
- Performance notes per issue (if any slow calls)
- Evidence screenshots saved to the same directory
- Performance Summary section at end
- Console Errors Summary section at end

### Rules
- NEVER update, close, or comment on GitHub issues — READ ONLY
- Write results ONLY to local files in test-results/
- Take screenshots for evidence
- ALWAYS clean up wizard-created test data after testing (see above)
```

### Step 3: Wait for Completion

Wait for all sub-agent tasks to complete. As each finishes, note its completion status.

If any agent fails (crashes, doesn't write results), log the failure and continue with the remaining agents.

### Step 4: Generate Summary Report

After all tests complete (or in `summary-only` mode), read all result files and generate a summary.

**Read these files** (if they exist):
- `stackiq/test-results/api/results.md` (API test results)
- `stackiq/test-results/leverancier/results-authenticated.md`
- `stackiq/test-results/gemeente/results-authenticated.md`
- `stackiq/test-results/security-officer/results-authenticated.md`
- `stackiq/test-results/functioneel-beheerder/results-authenticated.md`
- `stackiq/test-results/samenwerking/results-authenticated.md`
- `stackiq/test-results/architectuur-expert/results-authenticated.md`
- `stackiq/test-results/bezoeker/results-public.md`

For each file, extract:
- Issue number, title, status (PASS/PARTIAL/FAIL/CANNOT_TEST), severity
- Agent/method that tested it (API or persona name)

**Write the summary to**: `stackiq/test-results/README.md`

### Summary Report Format

```markdown
# GEMMA Softwarecatalogus — Test Results Summary

**Date:** {today's date}
**Environment:** {FRONTEND} (Frontend), {BACKEND} (Backend)
**Method:** {method description — e.g., "API tests (Newman)" or "Browser tests (7 persona agents)" or "Combined API + Browser tests"}

---

## Overall Results

| Status | Count | Percentage |
|--------|-------|------------|
| **PASS** | {count} | {pct}% |
| **PARTIAL** | {count} | {pct}% |
| **FAIL** | {count} | {pct}% |
| **CANNOT_TEST** | {count} | {pct}% |
| **Total tested** | {count} | — |
| **Not yet tested** | {count} | — |

---

## FAIL Issues (Requires Attention)

| Issue | Title | Severity | Agent | Summary |
|-------|-------|----------|-------|---------|
| #{num} | {title} | {severity} | {agent} | {one-line summary of failure} |
...

---

## CANNOT_TEST Issues (Blocked)

| Issue | Title | Agent | Reason |
|-------|-------|-------|--------|
| #{num} | {title} | {agent} | {why it couldn't be tested} |
...

---

## Results by Agent

### 1. Leverancier — Jan Pietersen
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| {n} | {n} | {n} | {n} |

Key findings: {2-3 bullet points}

### 2. Gemeente — Maria van der Berg
...{repeat for all 7 agents, including Bezoeker — Anonymous Visitor}

---

## Critical Findings

{List the most important FAIL issues with details — particularly security, privacy, and data integrity issues}

---

## Improvements Since Last Run

| Issue | Title | Previous | Current | Agent |
|-------|-------|----------|---------|-------|
{issues that improved}

---

## Regressions

| Issue | Title | Previous | Current | Agent |
|-------|-------|----------|---------|-------|
{issues that got worse}

---

## Performance Overview

### Aggregate Performance
| Agent | Total Calls | OK (<500ms) | SLOW (500ms-1s) | FAIL (>1s) | Slowest |
|-------|-------------|-------------|-----------------|------------|---------|
| Leverancier | {n} | {n} | {n} | {n} | {url} ({ms}ms) |
| Gemeente | {n} | {n} | {n} | {n} | {url} ({ms}ms) |
...{repeat for all agents}

### Slowest Endpoints (top 10)
| URL | Time | Agent | Page/Action |
|-----|------|-------|-------------|
| {url} | {ms}ms | {agent} | {context} |
...

---

## Console Errors Overview

### Aggregate Console Errors
| Agent | Pages Checked | Pages with Errors | Unique Errors |
|-------|--------------|-------------------|---------------|
| Leverancier | {n} | {n} | {n} |
...{repeat for all agents}

### Most Frequent Errors
| Error | Occurrences | Agents | Severity |
|-------|-------------|--------|----------|
| {error description} | {n} | {agents} | {severity} |
...

---

## Environment Limitations

{List factors that prevented testing or affected results}

---

## Recommendations

### Immediate (Security)
{numbered list}

### High Priority
{numbered list}

### Before Next Test Run
{numbered list}
```

### Step 5: Report to User

After writing the summary, display a concise overview to the user:
- Total issues tested
- PASS/FAIL/PARTIAL/CANNOT_TEST counts
- Top 3 critical findings
- Link to the full report: `stackiq/test-results/README.md`

### Step 6: Backlog Suggestions

After presenting the report, review the test findings for **suggestions and improvements** that are NOT existing GitHub issues but could be valuable. Present these to the user and ask if they should be added to the backlog at `stackiq/website/docs/backlog.md`.

Examples of backlog-worthy suggestions:
- UX improvements noticed during testing (e.g., inconsistent naming, confusing navigation)
- Accessibility issues not covered by existing issues
- Performance observations that warrant investigation
- Architecture or design decisions that need user group validation
- Missing features that would improve the workflow

**Format:** Present each suggestion as a numbered list with a short description and source (which agent/issue prompted it). Only add items the user approves.

---

## Open Issues Mode (Steps 7-10)

When the argument starts with `issues`, this workflow processes open IGS issues one-by-one or in parallel batches, preparing GitHub reply comments with proof.

### Step 7: Build Issue List

Read `stackiq/aanvullende-informatie.md` to get the full list of open issues with their categories.

**Filter based on argument:**
- `issues` → all 72 open issues
- `issues:15,65,73` → only the listed issue numbers
- `issues:bug` → only the 40 open Bug issues
- `issues:datakwaliteit` → only the 11 open Datakwaliteit issues
- `issues:tekstueel` → only the 7 open Tekstueel issues
- `issues:wens` → only the 11 open Wens issues

### Step 8: Launch Issue Agents in Parallel

Launch up to **6 sub-agents in parallel** (using `browser-1` through `browser-5` and `browser-7`), each processing a batch of issues. Distribute issues across agents evenly.

**Sub-agent prompt template** (replace `{issues}` with the comma-separated list, `{browser_num}` with the browser number):

```
You are an issue analysis agent for the GEMMA Softwarecatalogus.

Your task is to process the following open issues and prepare a GitHub reply comment for each: {issues}

## Workflow per issue

For EACH issue number in your list:

### 1. Read the issue
Read `stackiq/issues/{number}.md` for the full description, comments, and images.

### 2. Determine the category
Look up the issue in `stackiq/aanvullende-informatie.md` to find its category (Bug, Datakwaliteit, Tekstueel, Wens, Nog te bepalen).

### 3. Investigate based on category

**Bug issues:**
1. Navigate to the relevant page in the browser (Frontend: {FRONTEND}, Backend: {BACKEND})
2. Try to reproduce the problem described in the issue
3. Take screenshots showing the current state (whether fixed or still broken)
4. If it involves RBAC, check `stackiq/lib/Settings/softwarecatalogus_register.json`
5. Use the appropriate template from aanvullende-informatie.md (Template A if fixed, Template B if still broken)

**Datakwaliteit issues:**
1. Read the relevant CSV file(s) from `stackiq/data/`
2. Search for the specific data causing the issue (orphaned references, missing fields, etc.)
3. Count affected records and provide examples
4. Use Template C from aanvullende-informatie.md

**Tekstueel issues:**
1. Navigate to the page/wizard mentioned in the issue
2. Check if the text has been corrected
3. Take a screenshot as proof
4. Use Template D from aanvullende-informatie.md

**Wens issues:**
1. Read `stackiq/issues.md` to confirm this is outside the original PvE scope
2. Describe current behavior
3. Use Template E from aanvullende-informatie.md

**Nog te bepalen issues:**
1. Analyze thoroughly
2. Determine the best-fitting category
3. Follow that category's procedure

### 4. Write the reply
Save the prepared reply as: `stackiq/reacties/{number}.md`
Include the issue title as an H1 header, the category, and the reply content using the appropriate template.

### 5. Save screenshots
Save any screenshots to: `stackiq/reacties/screenshots/{number}-{description}.png`

## Browser Assignment
Use browser-{browser_num} for ALL browser operations (mcp__browser-{browser_num}__* tools).
Before navigating, run localStorage.clear() via browser_evaluate.

## Login
For issues requiring authenticated access, log in as admin ({ADMIN_USER}/{ADMIN_PASS}) at {FRONTEND}/login.
For public-facing issues, test without logging in.

## Data Files
CSV import data is in `stackiq/data/`:
- module.csv (applicaties), koppeling.csv, organisatie.csv, contactpersoon.csv
- compliancy.csv, gebruik.csv, gebruik_2.csv, gebruik_3.csv, moduleversie.csv

GEMMA AMEF model: `stackiq/data/GEMMA release.xml`

## Rules — CRITICAL
- NEVER update, close, or comment on GitHub issues — this is PREPARATION ONLY
- NEVER post anything to GitHub — all output is LOCAL files for human review
- Write replies ONLY to local files in stackiq/reacties/
- Take screenshots as evidence
- Do NOT use gh CLI to interact with issues in any way
```

### Step 9: Wait and Collect

Wait for all issue agents to complete. Create the output directory if needed:

```bash
mkdir -p stackiq/reacties/screenshots
```

### Step 10: Generate Issues Summary

After all agents complete, read all files in `stackiq/reacties/` and generate a summary.

**Write to**: `stackiq/reacties/README.md`

```markdown
# IGS Issues — Voorbereide Reacties

**Datum:** {today's date}
**Totaal verwerkt:** {count}

## Overzicht

| # | Issue | Categorie | Status Reactie | Bewijs |
|---|-------|-----------|---------------|--------|
| {num} | {title} | {cat} | Klaar / Concept | {ja/nee} |
...

## Volgende stappen
1. Review alle reacties in `reacties/{nummer}.md`
2. Pas reacties aan waar nodig
3. Plaats reacties op GitHub issues (handmatig of via gh CLI)
```

Report the summary to the user with counts per category and any issues that need manual attention.
