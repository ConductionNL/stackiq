---
kind: code
---

# A public software catalogue on the municipality website

## Summary

Board SkPubliek draws the software catalogue as a page of the municipality's
own website: anyone can search which software suppliers offer to government,
without signing in. The decision (coordinator, 23 Oct) is that this page is a
portaliq page, placed through portaliq's site configuration, and that stackiq
contributes the content. Stackiq gets no public page of its own, so the public
site stays in one place (ADR-108, ADR-109).

## What changes

- Stackiq's portal contribution declares a public record list,
  `softwareCatalogue` (ADR-046 contribution contract, `publicRecords`). Portaliq
  reads it without a session and shows it with its public records block.
- The list holds the published applications: name, and supplier and licence as
  the line under it. A record holds the application's summary (supplier,
  licence, website, reference components) and a table of the reference
  components it fulfils.
- Only published applications appear: a publication date in the past and no
  depublication date that has passed. The rule is stackiq's, in one class,
  never the caller's rights.

## Out of scope

- The "register your organisation" form on the board. Anonymous registration
  stays on stackiq's own intake endpoint for now. Moving it behind portaliq
  (ADR-108 rule 1) needs a portaliq intake form whose destination is
  stackiq's organisation schema with `registrationStatus: pending` fixed; that
  is a separate change.
- A free-text search on the server: portaliq's block searches the list in the
  browser, and the list is bounded.

## Board

SkPubliek.
