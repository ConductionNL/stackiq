<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# The application page

One page per application shows what the catalogue knows about it: its data, the organisations that use it, its versions, its compliance claims and the contracts behind it.

Specification: [`openspec/specs/application-page/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/specs/application-page/spec.md).

## Opening the page

Open **Applications** from the navigation menu and click a row, or use the **View** action on it. The page opens at `/modules/<id>`. The page also opens from an organisation's list of applications.

## What the page shows

- **Application**: the name, the short and long description, the website, the supplier, the supplier's contact person for this product, and the hosting, licence, BBN and DPIA fields.
- **Documentation**: files attached to the application.
- **Vendor and services**: the supplier and the services and connections linked to the application.
- **Application versions**: every registered version with its status. A row opens the version.
- **Usages**: every organisation that registered a usage of the application, with the version it uses and the status of that usage. You see the usages you may read: a municipality sees its own, a supplier sees the usages of its products.
- **Compliance claims**: the standards and BIO measures the application claims, with the evidence. A row opens the claim.
- **Contracts**: every contract on a usage of the application, and every contract on a service that offers it, each listed once, with its number, type, end date and status. A row opens the contract. You see only the contracts you may read.
- **Reviews**: ratings and reviews of the application.

## Why a contract shows up here

A contract in stackiq belongs to a usage and a service, not to the application directly. The page follows both links: a contract appears when its usage is a usage of this application, or when its service offers this application.
