<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Connection suggestions

The detail page of an **application in use** (`/gebruik/:id`) has a
**Suggested connections** panel. It lists the connections stackiq already
knows for this application that your usage does not have yet, so you do not
have to register each one by hand.

Specification: [`openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md).

## Where suggestions come from

- **Both applications are in your landscape.** A connection in the catalogue
  between this application and another application your organisation also
  uses (or a national provision) is suggested. A connection to an application
  you do not use is not.
- **Carried over.** When your organisation has another usage of the same
  application at another version, that usage's connections are offered as
  they are. When another usage names this application as its planned
  replacement, its connections are offered as drafts in which this
  application takes the replaced end. A draft is only created, with status
  *in development*, when you accept it.

Suggestions are worked out each time the panel opens; they are not stored,
so a usage you add elsewhere shows up straight away.

## Accept and dismiss

- **Accept** adds the connection to the usage's connections and takes it out
  of the panel. **Accept all** does that for every listed suggestion.
- **Dismiss** hides the suggestion for this usage; it stays hidden after a
  reload.
- Only the organisation that uses the application, or a participant in the
  usage, can list, accept or dismiss its suggestions. Anyone else is refused
  and nothing changes.

## API

| Verb | URL | Body |
|---|---|---|
| GET | `/apps/stackiq/api/usages/{uuid}/connection-suggestions` | — |
| POST | `/apps/stackiq/api/usages/{uuid}/connection-suggestions/accept` | `{ "ids": [...] }` |
| POST | `/apps/stackiq/api/usages/{uuid}/connection-suggestions/dismiss` | `{ "ids": [...] }` |

An unknown usage answers 404, another organisation 403.
