<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Contract expiry and the responsible user

A contract tells you when it is about to run out, and who answers for it.

Specification: [`openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md).

## Statuses

A contract has one of four statuses:

| Status | Meaning |
|---|---|
| Active | The contract runs, and its end date is more than the notice window away. |
| Expiring | The contract runs, and its end date falls inside the notice window. |
| Expired | The end date has passed. |
| In negotiation | The contract is being (re)negotiated. |

Stackiq moves contracts between Active, Expiring and Expired itself, once a day:

- An Active contract whose end date is today or later and at most the notice window away becomes **Expiring**.
- An Active or Expiring contract whose end date has passed becomes **Expired**. The end date itself still counts as running.
- An Expiring contract whose end date was moved past the window, because it was extended, becomes **Active** again.

A contract that is In negotiation or Expired is never changed by the daily pass. To reopen an expired contract, renegotiate it. A contract without an end date is left alone.

## The notice window

The window is the admin setting `contract_expiry_window_days` and defaults to 90 days. An empty, zero or negative value also means 90 days.

## The Contracts page

The Contracts page has a quick filter for each status: All, Active, Expiring, Expired and In negotiation. The **Responsible user** column shows who answers for each contract.

<!-- Screenshot of the Contracts page with the Expiring filter selected: to be added from a running instance (ADR-010). -->

## The responsible user

Each contract can name one **responsible user**, picked from the users of this Nextcloud in the contract form. The field stores the user's Nextcloud id and is optional.

The contract expiry warning goes to the software catalogue administrators, everyone with manage rights on the contract, and the responsible user.
