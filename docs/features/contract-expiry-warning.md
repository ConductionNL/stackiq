<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Contract expiry warning

Stackiq warns before a contract ends, so a renewal or a replacement can be arranged in time.

Specification: [`openspec/changes/contracts-expiry-warning-fires/specs/contract-expiry-warning/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/contracts-expiry-warning-fires/specs/contract-expiry-warning/spec.md).

## Who gets the warning

- Every member of the `software-catalog-admins` group.
- Everyone who may manage the contract.

Each of them gets a Nextcloud notification and an email.

## When

OpenRegister checks the contracts once a day. A contract is warned about when:

- its status is **Active** (or **Expiring**, once that status exists), and
- its end date falls within the next 90 days.

A contract in negotiation is not warned about: a negotiation is not an expiry. A contract that has already expired is not warned about either.

The warning reads, for example: *Contract expiring: CT-2026-014 (end date 2026-12-31)*.

## Once per end date

A contract is warned about once for each end date. The daily check does not repeat the warning while the end date stays the same. When someone extends the contract and the new end date also falls within the 90 days, a new warning names the new date.

## After an upgrade

Before this version the warning never fired. The first daily check after the upgrade therefore warns about every contract that already ends within the next 90 days, all at once. That is the backlog the warning should have sent, not a fault.

## The window

The window is 90 days and is part of the warning's declaration in the register. It does not follow an app setting: changing it means changing the declaration.
