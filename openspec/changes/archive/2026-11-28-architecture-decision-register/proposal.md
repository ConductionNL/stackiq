---
kind: code
depends_on:
  - architecture-views-editor
  - landscape-usage-registration
---

# Record architecture decisions with a review and link them to applications

## Summary

A municipal information manager records an architecture decision in stackiq: the context, the decision, the options they rejected and the consequences, classified by category and impact. They name a reviewer and submit it; the reviewer accepts or rejects it, and a later decision can supersede it. Each decision links to the applications in use and the GEMMA elements it affects, and the page of an application in use lists the decisions about it. When a board formally adopts a decision in decidiq, the architecture decision links to that decidiq decision.

## Why

This change builds one row of the stackiq parity matrix: `stackiq:arch-decision-register`, "Record architecture decisions with a status and review flow, and link each decision to the applications it affects." The demand is a changelog entry, https://updates.leanix.net/announcements/classify-architecture-decisions-with-dropdown-fields.

- SAP LeanIX rates yes: admins "add single-select and multi-select dropdown fields to architecture decision templates", and "Document decisions about enterprise architecture in a structured, template-driven format ... Track decisions through a review process with defined statuses" (https://help.sap.com/docs/leanix/ea/architecture-decisions).
- GLPI rates no; GEMMA Softwarecatalogus, BlueDolphin and TOPdesk are unknown.

The lane decided build: architecture is a core area.

## What stackiq has today

- The only decisions are contract approvals and renewals, delegated to decidiq. `lib/Service/ContractApprovalService.php:254` (`submitForApproval`) dispatches decidiq's `DecisionRequestedEvent` with the decision type `contract` or `contract-renewal` (:129, :135) and projects the outcome onto `approvalDecisionId` and `approvalState` (`lib/Settings/register.d/contracts-to-decidesk.json`). It must handle two event class spellings after decidiq's rename (:64 to :104) and fails closed when decidiq is absent.
- `catalogContract.decisions` (`lib/Settings/softwarecatalogus_register.json:3450`) holds decidiq decision uuids with `x-external-register` decidesk and `referenceType` decision: a link, not a copy.
- No schema holds an architecture decision, and no page lists one.
- OpenRegister runs a lifecycle transition through `requires` guards that an app can supply (`openregister-ro/openspec/specs/object-lifecycle/spec.md:126` to :134, `openregister-ro/lib/Lifecycle/LifecycleGuardInterface.php:37`), and its notification engine resolves a recipient from a user id held in an object field (`openregister-ro/lib/Service/Notification/NotificationRecipientResolver.php:187`).

## What this change builds

- A schema `architectureDecision` in the `stackiq` register with the decision text, category, impact, reviewer, links to usages and AMEF elements, supersedes and superseded by, and links to decidiq decisions.
- A declared review lifecycle (draft, in review, accepted, rejected, superseded, deprecated) with one stackiq guard class that enforces a second pair of eyes.
- Notifications to the reviewer on submit and to the owner on the outcome, declared on the schema.
- An Architecture decisions index and a decision page under the Architecture menu group, and a list of decisions on the usage page and on the standard page.

## Out of scope

- Making a formal board or council decision. That is decidiq's: its meetings, voting and signing. Stackiq only links to a decidiq decision the organisation already took. Raising an architecture decision in decidiq through `DecisionRequestedEvent` would need decidiq to accept a new decision type, and can follow the contract pattern later.
- Templates an administrator defines. Category and impact are fixed lists; see design D3.
- Decisions about a catalogue product for every municipality. A decision belongs to the organisation that records it.

## Risks

- The guard class implements an OpenRegister interface. If OpenRegister cannot resolve the guard, the transition fails closed, so a misconfigured instance blocks acceptance rather than letting anyone accept.
- A reviewer who leaves the organisation keeps pending decisions. The owner can send the decision back to draft and name a new reviewer.
