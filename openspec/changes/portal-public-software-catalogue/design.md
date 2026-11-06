# Design: portal-public-software-catalogue

## D1. A public record list, not a collection

An anonymous collection would expose `module` rows through portaliq's generic
reader with a field whitelist. A public record list instead names two provider
methods that return plain text, so what a visitor sees is decided by stackiq's
code (`PublicCatalogueRecords`) and never by page configuration. The list is
what decidiq uses for council records; portaliq caches the answers.

## D2. Published means dated

A module counts as published when `publicationDate` is set and not in the
future, and `depublicationDate` is empty or in the future. That is the gate the
publication endpoints (`publication#publish`, `publication#depublish`) set.

## D3. Bounded

The list reads at most 500 modules (portaliq keeps at most its own maximum of
entries). The record is read only for an id the list holds; portaliq enforces
that, and `record()` checks publication again so a record never outlives its
publication between two cache refreshes.

## D4. Wiring

`PortalContributionProvider` gets a nullable `PublicCatalogueRecords`, as
decidiq's provider gets its record readers, and declares
`publicRecords` on both audiences it serves, so portaliq finds it whichever
audience it asks first. Without the reader (no OpenRegister), the methods
return an empty list and null.
