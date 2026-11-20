<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Applications on GEMMA views

A GEMMA view shows reference components, the building blocks every municipality needs. You can show inside each component which applications fill it: the applications your organisation uses itself, and the applications a partner uses on your behalf and shares with you.

Specification: [`openspec/changes/architecture-shared-usage-on-views`](https://github.com/ConductionNL/stackiq/tree/development/openspec/changes/architecture-shared-usage-on-views).

## Showing applications on a view

Open **Architecture**, then **Views**, and open a view that came from the GEMMA import. Above the canvas, **Show applications** has two switches. Both are off when the page opens.

- **Our applications** shows the applications your organisation has registered as in use.
- **Shared with partners** shows the applications a partner organisation uses and shares with you, for example within a collaboration.

Each switch reloads the view with only what it asks for. Switch both off and the view looks as it did.

The applications appear inside the reference component they fill. When a component has more applications than fit, the last row reads **+N more**. Click an application to open its record under **Applications in use**.

Views you drew yourself do not have the switches: they already hold the elements you placed.

## Telling own and shared applications apart

The difference never depends on colour alone:

- **Our application**: a solid border.
- **Shared with a partner**: a dashed border and the word **Shared**. A screen reader announces the application with the partner's name, for example "Zaaksysteem, shared by Gemeente Oost".

When your organisation uses an application itself and a partner also shares it on the same component, it is shown once, as your own.

The legend under the canvas lists the kinds that are switched on.

## In the organisation export

The ArchiMate export of an organisation draws the same split. An own application keeps the export's usual style. A shared application is drawn light blue (fill 210,225,255) with a dark blue border (40,80,170) and carries the property **Gedeeld door** with the partner's name, so it stays recognisable in Archi and other ArchiMate tools.
