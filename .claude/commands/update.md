---
name: "SWC: Update"
description: Sync GitHub issues from VNG-Realisatie/Softwarecatalogus, auto-generate acceptance criteria, and update test infrastructure
category: Testing
tags: [testing, softwarecatalogus, sync, issues, acceptance-criteria]
---

# Sync Softwarecatalogus Issues & Update Tests

Synchronize GitHub issues from `VNG-Realisatie/Softwarecatalogus` into local files, auto-generate acceptance criteria, and update both Postman tests and browser test agent skill files.

**Target repo**: `VNG-Realisatie/Softwarecatalogus`
**Local directory**: `stackiq/`

**Input**: Optional argument after `/swc:update`:
- No argument → incremental sync (changes since last run)
- `--force` → ignore .last-update, refetch all open issues
- `--dry-run` → show what would change without writing any files
- `--issues 430,442,445` → sync only specific issue numbers

---

## Phase 1: Detect Changes

### Step 1: Read last-update timestamp

Read `stackiq/.last-update`. This file contains a single ISO 8601 timestamp (e.g., `2026-03-04T12:00:00Z`).

- If the file **exists**: use its content as `SINCE_TIMESTAMP`
- If the file **does not exist**: first run. Set `SINCE_TIMESTAMP` to empty (fetch ALL open issues)
- If `--force` was passed: ignore the file, set `SINCE_TIMESTAMP` to empty

### Step 2: Fetch changed issues from GitHub

Use the `gh` CLI. **Always use `--repo VNG-Realisatie/Softwarecatalogus`**.

**Incremental sync** (SINCE_TIMESTAMP is set):
```bash
gh issue list --repo VNG-Realisatie/Softwarecatalogus \
  --state all \
  --json number,title,labels,state,updatedAt \
  --limit 500 \
  --search "updated:>SINCE_TIMESTAMP"
```

**First run / force** (SINCE_TIMESTAMP is empty):
```bash
gh issue list --repo VNG-Realisatie/Softwarecatalogus \
  --state all \
  --json number,title,labels,state,updatedAt \
  --limit 500
```

**Specific issues** (`--issues` flag):
Skip the list query. Fetch each specified issue individually in Step 4.

**Rate limit handling**: If the command fails or returns truncated results, retry with `--limit 100` and paginate.

### Step 3: Classify each issue

For each issue in the result set:

- **NEW**: No file exists at `stackiq/issues/{number}.md`
- **UPDATED**: File exists AND GitHub `updatedAt` is after `SINCE_TIMESTAMP`
- **CLOSED**: Issue `state` is `"closed"`
- **UNCHANGED**: File exists AND not updated since last sync → skip

Build three lists: `new_issues`, `updated_issues`, `closed_issues`.

**If `--dry-run`**: Print the classification summary and STOP. Do not write any files.

---

## Phase 2: Update Individual Issue Files

### Step 4: Fetch full issue data

For each issue in `new_issues` + `updated_issues`:
```bash
gh issue view {NUMBER} --repo VNG-Realisatie/Softwarecatalogus \
  --json number,title,state,labels,author,createdAt,body,comments
```

### Step 5: Write individual issue files

Write/overwrite `stackiq/issues/{number}.md` using the **established format**:

```markdown
# #{number} — {title}

**Status:** {OPEN|CLOSED} | **Labels:** {comma-separated label names}
**Auteur:** @{author.login} | **Datum:** {createdAt as YYYY-MM-DD}
**Link:** https://github.com/VNG-Realisatie/Softwarecatalogus/issues/{number}

---

## Beschrijving

{issue body — preserve markdown, images, and links as-is}

---

## Reacties ({comment count})

### Reactie 1 — @{comment.author.login} ({comment.createdAt as YYYY-MM-DD})

{comment body — preserve markdown, images as-is}

---

### Reactie 2 — @{author} ({date})
...
```

**Formatting rules** (match existing files in `stackiq/issues/`):
- Title uses `# #{number} — {title}` (em-dash `—`, not hyphen)
- Status is UPPERCASE: `OPEN` or `CLOSED`
- Preserve HTML image tags from GitHub as-is (don't convert to markdown)
- Include ALL comments, including bot comments

---

## Phase 3: Update issues.md Master File

### Step 6: Read and parse current issues.md

Read `stackiq/issues.md`. Understand its structure:
- **Header** (first ~45 lines): date, summary counts, test type legend, recently closed list, new issues list
- **IGS Issues section**: individual `### #{number}: {title}` blocks with acceptance criteria
- **Other Issues section**: table of non-testable issues
- **Distribution table**: issue counts by test step

### Step 7: Auto-generate acceptance criteria for NEW issues

For each new issue, analyze the title, body, labels, and comments. Generate structured acceptance criteria.

**Tag classification — which tag to use:**

| Content signals | Tag |
|----------------|-----|
| Data fields, API endpoints, CRUD operations, field values, search results, export content, RBAC/permissions, JSON response | **[API]** |
| Layout, styling, labels, button placement, wizard flow, modal appearance, dropdown options, column visibility, text content | **[UI]** |
| Feature that needs both API validation AND visual verification (e.g., "after wizard save, field appears correctly") | **[HYBRID]** |

**Test Step assignment — based on labels and content:**

| Label / content keyword | Test Step |
|------------------------|-----------|
| "Aanbod", applicatie wizard, module, versie | Step 7 (applicaties), 8 (diensten), 16 (standaarden) |
| "Gebruik", koppeling, applicatielandschap | Step 10 (beheer gebruik), 11 (koppeling wizard), 17 (benchmarking) |
| "Zoeken", filter, search, facet | Step 14 |
| "Organisatie", organisatiebeheer | Step 3, 6 |
| "Referentiearchitectuur", ArchiMate, AMEFF | Steps 15, 19, 22, 24 |
| "Datamigratie", import, CSV | Step 19 |
| contactpersoon, collega | Step 5 |
| account, profiel, "Mijn Account" | Step 4 or 6 |
| export, Excel, rapportage | Step 13 |
| dashboard, overzicht | Step 2 |
| admin, CMS, pages, configuratie | Step 20 |

**Acceptance criteria format** (match existing style):

```markdown
### #{number}: {title}

**Labels:** {labels}
**Test Step:** Step {N}

**Summary:** {1-2 sentence English summary of what the issue is about}

**Acceptance Criteria:**
- [ ] [{TAG}] {Criterion 1 — specific, testable statement}
- [ ] [{TAG}] {Criterion 2}
- [ ] [{TAG}] {Criterion 3}
...

**Key Context from Comments:** {Brief note about important context from comments, related issues, or workarounds. Include cross-references like "Related to #NNN".}

---
```

**Criteria generation guidelines:**
- Generate 3-8 criteria per issue (fewer for simple text changes, more for complex features)
- Each criterion must be independently testable (clear PASS/FAIL)
- Start with the most concrete/specific criteria
- If screenshots show expected behavior, add visual comparison criteria
- All criteria start unchecked `- [ ]`
- Cross-reference related issues in the Key Context section

**Issue classification — IGS vs Other:**
- If the issue has labels like "question", "help wanted", "Conduction ontwikkeling", "Testbevindingen", "Verzamelissue" → add to **Other Issues** table, not IGS section
- If the issue is a testable feature/bug → add to **IGS Issues** section

### Step 8: Insert new issues into issues.md

Insert new issue blocks into the IGS Issues section in **numerical order** (sorted by issue number). Place each new block after the last existing issue with a lower number.

### Step 9: Update existing issues with new requirements

For each UPDATED issue:
1. Compare the GitHub comments against what's reflected in the existing Key Context section
2. Look for NEW comments that contain:
   - New requirements ("moet ook...", "graag ook...", "additional requirement")
   - Bug reports within comments
   - Scope changes or clarifications
3. If found: add NEW acceptance criteria lines (unchecked `- [ ]`) to the existing issue section
4. Update the "Key Context from Comments" section
5. **NEVER change existing checkbox states** — preserve `[x]` and `[ ]` exactly as-is

### Step 10: Update the header section

Update these fields in the issues.md header:
- `**Date:**` → today's date
- `**Total open issues on GitHub:**` → updated count
- `**IGS issues (detailed with acceptance criteria):**` → updated count
- Recently Closed Issues list → add newly closed issue numbers
- New Issues Added list → add new issue numbers with today's date
- Issue Distribution by Test Step table → update counts

### Step 11: Handle closed issues

For issues that changed to CLOSED:
- Do NOT remove them from issues.md (historical record)
- Add them to the "Recently Closed Issues" list in the header
- Add `**Status: CLOSED ({date})**` after the title in their IGS section

---

## Phase 4: Update Test Infrastructure

### Step 12: Update Postman collection for new [API] criteria

Read `stackiq/postman/softwarecatalogus-tests.json` (Postman v2.1 format).

For each new issue with [API]-tagged criteria, determine the target folder:

| Test Step | Postman Folder |
|-----------|---------------|
| Steps 2, 3, 4, 5, 6 | `06 - User Profile & Authentication` |
| Steps 7, 8 | `03 - Object CRUD` |
| Steps 9, 16 | `08 - Aanbod & Gebruik` |
| Steps 10, 11, 17 | `08 - Aanbod & Gebruik` |
| Step 12 | `02 - RBAC & Organization Scoping` |
| Step 13 | `07 - Export & Reporting` |
| Step 14 | `01 - Public API & Search` |
| Steps 15, 19, 22, 24 | `05 - ArchiMate & Views` (or `04 - Data Migration & Import` for import-specific) |
| Step 20 | `10 - Glossary & Content` |
| Step 21 | `09 - Data Quality & Naming` |

For each [API] criterion, create a Postman request item:

```json
{
  "name": "#{number} AC{N}: {short criterion description}",
  "request": {
    "method": "{GET|POST|PATCH|DELETE}",
    "header": [
      {"key": "OCS-APIRequest", "value": "true", "type": "text"},
      {"key": "Content-Type", "value": "application/json", "type": "text"}
    ],
    "url": {
      "raw": "{{base_url}}/index.php/apps/openregister/api/objects/voorzieningen/{schema}",
      "host": ["{{base_url}}"],
      "path": ["index.php", "apps", "openregister", "api", "objects", "voorzieningen", "{schema}"]
    },
    "auth": {
      "type": "basic",
      "basic": [
        {"key": "username", "value": "{{admin_user}}", "type": "string"},
        {"key": "password", "value": "{{admin_pass}}", "type": "string"}
      ]
    }
  },
  "response": [],
  "event": [
    {
      "listen": "test",
      "script": {
        "exec": [
          "pm.test(\"#{number} AC{N}: {description}\", function() {",
          "    pm.response.to.have.status(200);",
          "    var json = pm.response.json();",
          "    // Add specific assertions based on the criterion",
          "});",
          ""
        ],
        "type": "text/javascript"
      }
    }
  ]
}
```

**Test assertion patterns** (choose based on criterion type):
- Data presence: `pm.expect(json.results).to.be.an("array")`
- Field existence: `pm.expect(json.results[0]).to.have.property("fieldName")`
- Field value: `pm.expect(json.results[0].fieldName).to.eql("expected")`
- Field not UUID: `pm.expect(json.results[0].fieldName).to.not.match(/^[0-9a-f-]{36}$/)`
- RBAC scoping: compare result counts or check `_organisation` field
- Public access: use `"auth": {"type": "noauth"}`
- Column/field removal: `pm.expect(json.results[0]).to.not.have.property("removedField")`

**Use `python3` for JSON manipulation** to safely read, modify, and write the collection:
```bash
python3 -c "
import json
with open('stackiq/postman/softwarecatalogus-tests.json', 'r') as f:
    collection = json.load(f)
# ... add new items to the appropriate folder ...
with open('stackiq/postman/softwarecatalogus-tests.json', 'w') as f:
    json.dump(collection, f, indent='\t', ensure_ascii=False)
"
```

Skip this step for issues that only have [UI]-tagged criteria.

### Step 13: Update persona skill files for new [UI]/[HYBRID] criteria

Determine which persona(s) should test each new issue:

| Label / content | Primary persona | Skill file |
|----------------|----------------|------------|
| "Aanbod", vendor features | leverancier | `stackiq/.claude/skills/test-leverancier.md` |
| "Gebruik" (municipality) | gemeente | `stackiq/.claude/skills/test-gemeente.md` |
| "Gebruik" (collaboration) | samenwerking | `stackiq/.claude/skills/test-samenwerking.md` |
| "Zoeken" (unauthenticated) | bezoeker | `stackiq/.claude/skills/test-bezoeker.md` |
| "Referentiearchitectuur" | architectuur-expert | `stackiq/.claude/skills/test-architectuur-expert.md` |
| Security, privacy, RBAC | security-officer | `stackiq/.claude/skills/test-security-officer.md` |
| Admin, CMS, config | functioneel-beheerder | `stackiq/.claude/skills/test-functioneel-beheerder.md` |

For each persona skill file, find the issues table (format: `| Issue | Title | ... |`) and add the new issue row in numerical order:
```
| #{number} | {title} | Step {N} |
```

If the issue affects multiple personas (e.g., a search bug affects both bezoeker and gemeente), add it to ALL relevant persona files.

Also add brief testing instructions for the new issue in the "Detailed Testing Instructions" section of the skill file, if one exists. Follow the existing pattern in each file.

### Step 14: Update aanvullende-informatie.md

Read `stackiq/aanvullende-informatie.md`. Update:
- The total count in the header
- Add new issues to the appropriate category section
- Note any new functional areas not previously covered

---

## Phase 5: Finalize

### Step 15: Write timestamp

Write the current UTC time as ISO 8601 to `stackiq/.last-update`:
```bash
date -u +"%Y-%m-%dT%H:%M:%SZ" > stackiq/.last-update
```

### Step 16: Present summary

Output a structured summary to the user:

```
## SWC Update Summary — {date}

| Category | Count |
|----------|-------|
| New issues synced | {N} |
| Updated issues synced | {N} |
| Closed issues noted | {N} |
| New acceptance criteria added | {N} |
| New Postman API tests added | {N} |
| Persona skill files updated | {N} |

### New Issues
| # | Title | Labels | Test Step | Tag |
|---|-------|--------|-----------|-----|
| {number} | {title} | {labels} | Step {N} | [API]/[UI]/[HYBRID] |

### Updated Issues (new criteria added)
| # | Title | New criteria | Reason |
|---|-------|-------------|--------|
| {number} | {title} | {count} | {what changed} |

### Closed Issues
{list of closed issue numbers and titles}

### Files Modified
- stackiq/issues.md
- stackiq/issues/{numbers}.md
- stackiq/postman/softwarecatalogus-tests.json (if API tests added)
- stackiq/.claude/skills/test-{persona}.md (list which ones)
- stackiq/aanvullende-informatie.md
- stackiq/.last-update
```

### Step 17: Offer test execution

Ask the user using the **AskUserQuestion tool**:

**Question**: "Do you want to test the new/updated acceptance criteria?"

| Option | Label | Description |
|--------|-------|-------------|
| 1 | **API tests** | Run Newman for the Postman folders that received new tests |
| 2 | **Browser tests** | Run affected persona agents to test new [UI]/[HYBRID] criteria |
| 3 | **Both** | API tests first, then browser tests |
| 4 | **Skip** | Don't test now — just save the updates |

If the user chooses to test:

**API tests**: Run Newman for only the affected folders:
```bash
newman run stackiq/postman/softwarecatalogus-tests.json \
  -e stackiq/postman/environment-local.json \
  --folder "{affected-folder-name}" \
  --reporters cli 2>&1
```
Repeat for each folder that received new tests.

**Browser tests**: Launch the affected persona agents using the same sub-agent pattern from `/swc:test`:
- For each affected persona, launch a Task agent with the sub-agent prompt template from `/swc:test` Step 2
- BUT limit testing to only the new/updated issues (include a list of specific issue numbers in the prompt)
- Write results to `stackiq/test-results/{persona}/results-authenticated.md`

**Both**: Run API first, then browser.

After testing completes, if any tests FAIL, ask the user:

**Question**: "Some new criteria failed. Do you want me to investigate and fix the issues?"

| Option | Label | Description |
|--------|-------|-------------|
| 1 | **Yes, fix them** | I'll investigate the failures and implement fixes in the stackiq app code |
| 2 | **No, just report** | Save the test results for later review |

If the user wants fixes: read the test results, identify the root causes, and implement code fixes in the `stackiq/` app. After fixing, re-run the affected tests to verify.

---

## Rules

### GitHub: READ ONLY — This is critical
- **NEVER** use `gh issue comment`, `gh issue close`, `gh issue edit`, or any write command
- **NEVER** post comments, update labels, change state, or modify GitHub issues in any way
- **ONLY** use `gh issue list` (to discover) and `gh issue view` (to read) — nothing else
- **NEVER** push changes to any remote repository
- All output goes to LOCAL files in `stackiq/` only

### Other rules
- All file writes go to `stackiq/` only — NEVER write to `Softwarecatalogus/`
- Preserve existing acceptance criteria checkbox states (`[x]` and `[ ]`)
- Use `python3` for Postman JSON manipulation (not manual text editing)
- When in doubt about tag classification, default to `[HYBRID]`
- When in doubt about persona assignment, assign to `functioneel-beheerder` (broadest scope)
