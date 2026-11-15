---
kind: code
depends_on: [mcp-full-action-surface]
---

# Ask the catalogue a question, and see what an assistant did

## Summary

Two stackiq screens on top of the MCP surface that `mcp-full-action-surface` specifies. An Ask panel lets a signed-in user put a question about the landscape in plain language and get an answer that lists, with links, the catalogue entries it was grounded in. An Assistant actions page lists every action an AI assistant took on catalogue records through a stackiq tool: which tool, on which record, for which user, and when.

## Why

Two rows of the stackiq matrix were linked on 27 Sep to changes that do not specify stackiq's own side:

- `stackiq:ins-natural-language-query`, "Ask a question about the landscape in plain language inside the tool and get an answer that cites the entries it used." Linked to `stackiq-mcp-adoption`, which only declares read-only search and get tools for hermiq and has no screen in stackiq and no answer that cites entries. SAP LeanIX and BlueDolphin rate yes.
- `stackiq:ins-ai-action-audit`, "See which actions an AI assistant took on the data, when and on which records." Linked to `mcp-full-action-surface`, which sends each invocation to the audit trail but shows nothing in stackiq. TOPdesk has it in Building on its roadmap.

The round 1 spec PR (#1245) named both links as thin. This change gives each row a spec for the part stackiq owns.

## What stackiq has today

- No assistant code in `lib/` or `src/`. `mcp-full-action-surface` (open, not built) specifies the tool provider; `stackiq-mcp-adoption` is superseded by it.
- Hermiq answers grounded questions through `POST /api/assistant/converse` (hermiq `openspec/specs/case-assistant-surface`): one request, one reply, grounded in the `contextData` the caller sends, no tool execution, with hermiq's guardrails and audit.
- OpenRegister writes one audit record per MCP tool call with `action` `mcp.<verb>`, the `toolId` and the object when known (`AuditTrailMapper::createToolInvocationEntry()`, openregister `lib/Db/AuditTrailMapper.php:2741`), readable through `GET /api/audit-trails` and per object through `GET /api/objects/{register}/{schema}/{id}/audit-trails`. The acting agent is not yet recorded apart from the user (the mapper's own docblock defers it to hermiq's governance change).
- The History tab on 11 detail pages (`src/manifest.json:445` and others, widget type `audit`) shows every change per object without singling out assistant actions.

## What this change builds

1. An Ask panel (`/ask`) that searches the catalogue with the user's own rights through OpenRegister, sends the matching entries to hermiq's converse endpoint as grounding, and shows the reply with the list of entries it used, each linked to its stackiq page.
2. An Assistant actions page (`/assistant-actions`) listing OpenRegister audit records with an `mcp.` action and a `stackiq.` tool id, with filters on tool, user and period, each row linked to the record.
3. On the History tab, an assistant action is labelled as such with its tool id.

Both screens hide themselves when hermiq is not installed.

## Rows covered

- `stackiq:ins-natural-language-query`
- `stackiq:ins-ai-action-audit`

## Out of scope

- The tool provider itself: `mcp-full-action-surface`.
- Telling one agent from another: needs the acting-agent identity OpenRegister does not record yet.
- Answers that act on the catalogue. The converse endpoint has no tools, which is the point of using it here.

## Risks

- The answer is only as good as the search that fed it. The panel shows the entries it sent, so a user sees when the search missed.
- If `GET /api/audit-trails` cannot filter on an action prefix, the page must not fetch the whole trail and filter in the browser; the gap goes to OpenRegister (ADR-022).
