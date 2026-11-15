---
kind: code
depends_on:
  - architecture-views-editor
  - mcp-full-action-surface
---

# Let an assistant draft an architecture view for review

## Summary

A user asks Hermiq's assistant for a view ("draw how our case system talks to document management"). The assistant looks up the GEMMA elements that fit through a stackiq tool, then calls a second stackiq tool that writes the view as a draft. The draft opens in the view editor, marked as drafted by an assistant, and a person reviews, adjusts and saves it. Stackiq owns the two tools and the mark. The chat, the model and the agent's rights are Hermiq's.

## Why

This change builds one row of the stackiq parity matrix: `stackiq:arch-ai-diagram`, "Have a diagram drafted for you by an assistant from a description." No tender, feature request or changelog names it for stackiq.

- SAP LeanIX rates yes: "AI agents connected to your workspace via the MCP server can now create, populate, and edit diagrams ... You describe what you want to see, and the agent adds fact sheets to a canvas" (https://updates.leanix.net/announcements/build-and-edit-architecture-diagrams-with-ai-agents).
- BlueDolphin rates yes: "Modelling Assistant or BPMN Generator instantly creates BPMN 2.0-compliant process diagrams from a simple prompt or by uploading existing documentation" (https://help.bluedolphin.io/en/articles/12528662-ai-capabilities-of-bluedolphin).

The lane decided build because two competitors rate yes and architecture is a core area. The matrix notes "Nothing drafts diagrams."

## What stackiq has today

- No MCP surface. `grep -rn "IMcpToolProvider\|McpTool" lib appinfo` returns nothing, and `lib/Mcp` does not exist at development 49e65cb4.
- Two open changes plan one. `stackiq-mcp-adoption` excludes `element`, `view`, `model`, `property-definition` and `relation` (its `design.md` exclusion table and Decision 3). `mcp-full-action-surface` keeps that exclusion for derived tools (its `design.md` section 3, "Excluded from derivation"), adds read-only `stackiq.listViews` and `stackiq.getView` over `ViewService` (its `design.md` section 5), and creates `lib/Mcp/StackiqToolProvider.php` and `lib/Mcp/McpArgumentValidator.php` (its `tasks.md` 2.1 and 2.3). Neither writes a view.
- OpenRegister's `IMcpToolProvider` (`openregister-ro/lib/Mcp/IMcpToolProvider.php:47`) runs a tool in the caller's Nextcloud session, and `ToolRegistryFacade::invokeTool` (`openregister-ro/lib/Service/Mcp/ToolRegistryFacade.php:350-365`) passes no agent identity to the provider.
- `architecture-views-editor` adds the editor, the `origin` field on `view`, `element` and `relation`, and the readers that keep only imported views in the shared list and the full export.

## What this change builds

- `stackiq.searchArchitectureElements`, a read tool that returns a short projection of AMEF elements (uuid, identifier, name, ArchiMate type, GEMMA type) so a draft reuses existing elements instead of inventing duplicates.
- `stackiq.draftView`, a create tool that takes a name, a description, elements (an existing uuid, or a new ArchiMate type and name) and relations (source, target, ArchiMate relation type), and writes a `view` in status draft.
- `lib/Service/ArchitectureViewDraftService.php`, which validates the input, resolves elements, reuses or creates relations, and writes every object with the assistant mark in the same write (ADR-088).
- An `assistant` value on `origin`, a notice in the editor on a drafted view, and a first-open layout for drafted nodes.

## Out of scope

- The chat, the prompt, the model call, the agent's tool grants and the human approval gate. Those are Hermiq's (ADR-034, ADR-063 Decision 4).
- Letting an assistant change an existing view. SAP LeanIX's agents also edit diagrams. This change only drafts new views, and a later change can add an edit tool once drafts have been reviewed in practice.
- Drafting business processes in BPMN, which is BlueDolphin's evidence. Stackiq models processes in `architecture-process-mapping`, and a process draft tool can follow it.
- Reading an uploaded document to draft from it.

## Risks

- An agent can create many drafts. Drafts are ordinary organisation objects under OpenRegister RBAC, the tool caps a draft at 60 elements and 120 relations, and Hermiq's approval gate sits in front of every create tool.
- The relation reuse rule exists twice: in the editor's store (`architecture-views-editor` D5) and in this service. Both are tested against the same fixture.
