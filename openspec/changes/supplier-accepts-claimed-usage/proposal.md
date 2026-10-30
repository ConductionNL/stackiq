---
kind: code
depends_on: []
---

# A supplier accepts or declines the usage organisations claim of its products

## Summary

The accept and decline logic for offers is complete and authorised (`PUT /api/aanbod/{uuid}/accept`, `DELETE /api/aanbod/{uuid}/deny`, `lib/Controller/AanbodController.php`), but nothing in `src/` calls it, so only the external VNG frontend or a script can use it. It also covers one direction only: a usage where the active organisation is the consumer. Board SkOrganisatie draws the other direction on a supplier's page: "Geclaimd gebruik van ons aanbod, organisaties die aangeven uw product te gebruiken", each with the product, version, module and claim date, and Afwijzen and Accepteren. The board is the canon (decision 130), so the row moves from built to building with this plan.

## Rows

- `mkt-offer-accept` "As a supplier, accept or decline a usage another organisation has claimed of your product."

## Decisions taken in this change (to confirm in review)

- A claimed usage is a usage whose `provider` is the supplier and whose owner (`@self.organisation`) is another organisation, not yet confirmed or declined by the supplier.
- Declining does not delete the usage: it belongs to the claiming organisation. It records `providerResponse: declined` with date and user, and the claiming organisation sees that the supplier does not recognise the claim. Accepting records `providerResponse: accepted`.

## Screens

See `screens.md`: SkOrganisatie.
