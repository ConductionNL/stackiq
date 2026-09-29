<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Maintenance and roadmap

Suppliers announce planned maintenance on their applications and publish where each application is heading. Organisations that use an application see the maintenance on their dashboard and on the application's page, and read the roadmap before they plan an upgrade.

Specification: [`openspec/specs/maintenance-and-supplier-roadmap/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/specs/maintenance-and-supplier-roadmap/spec.md).

## Announcing maintenance

Open your application's page under **Applications** and click **Announce maintenance** in the **Planned maintenance** section. Fill in:

- **Title** and **Description**: what happens and what users should do.
- **Version**: only when the maintenance concerns one version.
- **Starts at** and **Ends at**.
- **Impact**: No impact, Degraded or Unavailable.

A new window starts as Planned. From its page you move it to In progress, Completed or Cancelled.

## Who is told

When you announce a window, stackiq looks up every organisation that uses the application and the business owner and technical owner of each usage. Those owners get a Nextcloud notification, and a reminder the day before the window starts while it is still planned. Owners are set on the usage, under **Applications in use**; a usage without owners still shows the window on its organisation's dashboard.

## Following maintenance

The dashboard lists **Planned maintenance** on the applications your organisation uses in the next 30 days, with the time window and the impact. Each application's page lists all its planned maintenance.

## The roadmap

A supplier writes the direction of an application in the **Roadmap** field of the application. The application's page shows it with the application's versions on a timeline, the planned versions first. A version is placed on its go-live date, or on the date development started when it has no go-live date yet.

Under **Module versions**, the **Planned releases** tab lists the versions still in development, with the date development started. A supplier releases a planned version from its page with **Release**.
