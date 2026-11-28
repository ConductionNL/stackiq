<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Assistant actions

Review what AI assistants did on catalogue records through stackiq's tools.

Specification: [`openspec/changes/archive/2026-11-24-insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/archive/2026-11-24-insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md).

## Where

**Reports & Compliance > Assistant actions**.

## What is listed

Every time an assistant calls one of stackiq's tools, OpenRegister writes an entry in its audit trail. The page lists those entries, newest first:

| Column | What it shows |
|--------|---------------|
| When | The moment the tool was called |
| User | The user on whose behalf the assistant acted |
| Tool | The tool, for example `stackiq.submitContractApproval` |
| Record | The catalogue record the call touched, linked to its page |
| Outcome | **Succeeded**, or **Failed** with the kind of error |

Filter on a tool (its full id, or just its name), a user and a period, then choose **Apply filters**. The list loads 50 entries at a time; **Load more** shows the next ones.

## Where the data comes from

The page reads OpenRegister's audit trail directly; stackiq keeps no copy. OpenRegister selects the entries itself: only assistant tool calls (`mcp.` actions) of stackiq's tools.

## Who can see it

Who may read the audit trail is decided by OpenRegister. Administrators see every assistant action; other users see a notice that only administrators can review assistant actions.

## In a record's history

A record's **History** tab shows assistant actions as well, under their action name (for example `mcp.submitContractApproval`). A dedicated "Assistant: tool" label there waits for support in the shared history widget.
