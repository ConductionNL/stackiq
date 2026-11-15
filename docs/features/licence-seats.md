<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Licence seats

A licence contract records how the licence is measured and how many licences were bought and are in use. Stackiq sets the two numbers against each other on the contract and on the License posture page, so you see where use runs over what was bought.

Specification: [`openspec/specs/licence-seats/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/specs/licence-seats/spec.md).

## Recording the licence on a contract

Open a contract and edit it. Three fields describe the licence:

- **Licence metric**: per named user, per concurrent user, per device, per inhabitant, per organisation, or other.
- **Licences bought**: the number the contract pays for.
- **Licences in use**: the number in use today.

All three are optional. A count cannot be negative.

## The licences panel on a contract

The contract page shows a **Licences** panel with the metric, both counts, a bar of in use against bought, and one of these states:

- **Within licence**: in use is at or below bought.
- **Over licence by N**: in use is N above bought. The bar turns red.
- **Unknown**: one of the counts is empty.
- **Not counted**: the metric is per organisation or other, so there is nothing to count and no bar.

The panel also shows the date the contract was last changed, so you can judge how current the count is.

## The Seats section on the License posture page

Open **License posture** in the navigation menu. The **Seats** section has one row per contract with a counted metric and a number of licences bought. Each row names the application, the organisation, the metric, bought, in use and the state. Contracts over their licence come first, the furthest over at the top.

You see the contracts you may read; the section uses the same access rules as the rest of the page.

Screenshots of the panel and the section follow once the feature runs on the demo instance.
