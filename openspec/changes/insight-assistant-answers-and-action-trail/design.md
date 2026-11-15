# Design: insight-assistant-answers-and-action-trail

Read at stackiq development `71912795`, OpenRegister and hermiq development on 7 Oct 2026.

## Context

Stackiq keeps every catalogue entry as an OpenRegister object in the voorzieningen register. Hermiq offers two ways in: the MCP tools an agent calls, and `POST /api/assistant/converse`, a tool-free grounded chat for a leaf app (hermiq `case-assistant-surface`). OpenRegister audits each MCP tool call as `mcp.<verb>` with its `toolId`. ADR-022: stackiq consumes these; it builds no search engine, no chat engine and no audit store of its own.

## D1. Ask: search first, then converse

`lib/Service/AskService.php`:

- `ask(string $question, ?string $sessionId): array` runs one OpenRegister search (the `ObjectService` search with `_search` set to the question) over the schemas `module`, `catalogService`, `organization`, `usage`, `connection`, `compliancy` and `catalogContract`, as the calling user, so RBAC and organisation scoping apply unchanged. It caps the result at 20 entries.
- It builds `contextData` as a list of `{ref, schema, title, summary, url}`, where `ref` is `E1`..`E20`, `url` is the entry's stackiq route (`ModuleDetail` and the other detail pages in `src/manifest.json`), and `summary` holds at most the fields shown on the entry's list page.
- It posts `{sessionId, message, context: {app: "stackiq", contextData}}` to hermiq's converse endpoint in process (resolved from the container, a runtime lookup that answers 503 when hermiq is absent), with an instruction to cite entries by their `ref`.
- It returns `{sessionId, reply, sources}`, where `sources` are the entries whose `ref` occurs in the reply, and `searched` is the full list sent.

Route `POST /api/ask`, `#[NoAdminRequired]`, rate limited per user. No write happens.

Rejected: letting hermiq call stackiq's MCP search tools to find the entries. That path can act, and an answer box on a catalogue page should not be able to.

## D2. The Ask panel

A custom page `Ask` at `/ask` (`src/views/AskView.vue`), with a menu entry under Reports & Compliance, shown only when hermiq is enabled for the user (initial state from `lib/Settings` through `IInitialState`). It has one question field, the reply, a "Sources" list of linked entries, and a collapsed "Searched" list. An empty search answers "No catalogue entries matched this question." without calling hermiq.

## D3. Assistant actions page

A custom page `AssistantActions` at `/assistant-actions` (`src/views/AssistantActionsView.vue`) that reads `GET /apps/openregister/api/audit-trails` filtered on register voorzieningen and `action` starting with `mcp.`, and keeps rows whose `toolId` starts with `stackiq.`. Columns: when, user, tool, record (linked), outcome. Filters: tool, user, period. Paged by OpenRegister. Visible to stackiq admins and functional administrators; other users see only their own rows, which OpenRegister's audit RBAC already enforces.

If OpenRegister's index cannot filter on an action prefix, write the gap to OpenRegister instead of filtering in the browser.

## D4. History tab label

The History tab uses the library `audit` widget. If it can label an entry by action, stackiq passes a label map (`mcp.*` to "Assistant: {toolId}"); if it cannot, file the need on nextcloud-vue and leave the tab as it is. The Assistant actions page carries the row either way.

## Screens

Stackiq has no design-canvas board (decision 75), so the layout follows the existing custom pages (`ComplianceMatrixView`, `LicensePostureView`).
