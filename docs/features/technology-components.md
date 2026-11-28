<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Technology components

A technology component is a piece of technology your organisation runs: a physical server, a virtual machine, a container platform, a database, middleware, a runtime, an operating system, a network device, storage or an end-user device such as a laptop. You record what each component runs on and which applications in use run on it, and stackiq shows when a component reaches its end of support and which applications that affects.

Stackiq **records** components; it does not discover them. Nothing scans your network. A component is in stackiq because someone added it, by hand or through an import.

Specification: [`openspec/changes/archive/2026-11-24-operations-technology-components/specs/technology-components/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/archive/2026-11-24-operations-technology-components/specs/technology-components/spec.md).

## Who can see components

Components belong to an organisation. Only users of that organisation and catalogue administrators can read them. Suppliers and visitors of the public catalogue cannot, and a component is never published. Serial numbers, hosts and locations help an attacker, so keep them inside your organisation.

## The Technology list

Open **Applications** in the navigation menu, then **Technology**. The list shows each component's name, class, organisation, status and end of support, with the component whose support ends first at the top. The filters above the list select:

- **Hardware**: physical servers, virtual machines, network devices, storage and end-user devices.
- **Platform software**: container platforms, databases, middleware, runtimes and frameworks, and operating systems.
- **To be phased out**, and the archived components.

Click **Add** to record a component. Fill in:

- **Name**, **Class** and **Organisation**. These are required.
- **ArchiMate type**: how the component appears in an ArchiMate model (node, device, system software, technology service or communication network).
- **Status**: planned, in use, to be phased out or phased out. A new component is in use.
- For hardware: **Manufacturer**, **Model**, **Serial number**, **Asset tag** and **Location**.
- **Software**: for platform software, the version of the application in the catalogue that this component is, for example PostgreSQL 13.
- **End of support**: type it for hardware. For a component with a software version, the end-of-life sync fills it in (see below).
- **Runs on**: the components this one runs on. A virtual machine runs on a host, a database on a virtual machine.

## A component's page

A component's page shows its data, the hardware fields, and two lists:

- **Runs on this component**: the components that run on it, each opening its own page. On a host you see its virtual machines.
- **Applications in use here**: the applications in use that run on it, each opening the application in use.

The buttons at the top move the component through its lifecycle: **Plan to in use**, **Phase out** (to be phased out) and **Retire** (phased out). The **History** tab shows every change.

**End of support source** says where the date came from: the end-of-life feed, or empty when someone typed it.

## End of support from the catalogue

When the end-of-life sync stamps an end of support on an application version (see [End-of-life feed](eol-feed-integration.md)), it stamps the same date on every component whose **Software** is that version, with the source and the time of the update. A component without software keeps the date that was typed.

## Runs on, on an application in use

An application in use records the components it runs on under **Runs on**. Its page shows the **Runs on** panel: each component with its end of support and its support state, and underneath each one the components it runs on, one level down.

| State | Meaning |
| --- | --- |
| Supported | The end of support is more than 180 days away. |
| Support ends within 180 days | The end of support is today or within 180 days. |
| Support ended | The end of support has passed. |
| Unknown | No end of support is recorded. |

The line above the table gives the worst state of them all. An application in use that runs on a virtual machine without a date, on a host whose support has ended, is past its end of support through that host.

## Demo data

The demo data has three components of Gemeente Voorbeeld: **Host 01** (a physical server), **App server 01** (a virtual machine on Host 01) and **Database 01** (a database on App server 01, whose software version ended support on 3 March 2026). One application in use runs on Database 01 and App server 01, so its Runs on panel shows Database 01 as past its end of support.

Screenshots of the list, a component's page and the Runs on panel follow once the feature runs on the demo instance.
