<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Architecture decisions

An architecture decision records why your organisation chose one direction over another: the context, the decision, the options you did not choose and the consequences. A colleague reviews it before it counts, and each decision links to the applications in use and the GEMMA elements it affects.

Specification: [`openspec/changes/architecture-decision-register`](https://github.com/ConductionNL/stackiq/tree/development/openspec/changes/architecture-decision-register).

## Recording a decision

Open **Architecture decisions** in the navigation menu and click **Add**. Fill in:

- **Title**, **Context** (why a decision is needed), **Decision** (what was decided) and **Consequences**.
- **Alternatives**: each option you did not choose, with the reason it was rejected.
- **Category**: application, data, integration, infrastructure, security or standards.
- **Impact**: low, medium or high.
- **Reviewer**: the Nextcloud user who reviews the decision.
- **Applications in use** and **Elements**: what the decision affects.

A new decision starts as a draft. Only people of your own organisation see it.

## The review

A decision moves through these statuses:

| Action | From | To | Who |
|---|---|---|---|
| Submit | Draft | In review | The author, when a reviewer other than the author is named |
| Accept or Reject | In review | Accepted or Rejected | The reviewer only |
| Rework | In review or Rejected | Draft | |
| Supersede | Accepted | Superseded | When **Superseded by** names an accepted decision |
| Deprecate | Accepted | Deprecated | |

The rules apply whether you use the action buttons on the decision page or change the status directly. A refused step says why.

When a decision is submitted, the reviewer gets a Nextcloud notification and an email. When it is accepted or rejected, the people who manage the decision are told. The **History** tab on the decision page shows every step.

Under **Architecture decisions**, the quick filter **To review by me** lists the decisions waiting for your review.

## Where decisions show

- The page of an application in use lists the architecture decisions that name it, in the **Architecture decisions** section.
- The page of a standard lists the decisions that name it the same way.

## Decisions of a board: decidiq

Most architecture decisions are settled by the reviewer. When a board or council formally adopts one, that happens in decidiq, with its meetings, voting and signing. Add the decidiq decision under **Board decisions**: stackiq stores only its id and does not copy it.
