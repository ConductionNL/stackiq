<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Applications in use

An application in use records that your organisation uses an application: which version it runs, where it stands in its lifecycle, and who owns it on the business side and on the technical side. The portfolio views, the lifecycle roadmap and the end-of-support warnings all start from these records.

Specification: [`openspec/specs/application-usage-pages/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/specs/application-usage-pages/spec.md).

## Adding an application to your landscape

Open the application's page under **Applications** and click **Add to our landscape** in the usages section. The application is already filled in. Pick:

- **Consumer**: your organisation.
- **Version**: the version you run. The list only offers versions of this application.
- **Status**: Acquisition, Planned, In production, To be phased out or Phased out.
- **Business owner** and **Technical owner**: contact persons of your organisation.

## Browsing what you use

Open **Applications** in the navigation menu, then **Applications in use**. The list shows each application with its version, status, owners and TIME classification. The tabs above the list filter on status. Your organisation's page lists the same records under **Applications in use**.

## Moving through the lifecycle

Open an application in use. The actions at the top follow its status:

- **Plan** moves Acquisition to Planned.
- **Go live** moves Planned to In production.
- **Phase out** moves In production to To be phased out.
- **Retire** moves To be phased out to Phased out.

Every change is kept in the **History** tab.

## Who sees the owners

The owners are contact persons of the organisation that uses the application. A supplier can read the usages of its own products, but it cannot open the contact persons of its customers.
