# aanbod-listings Specification (delta)

## ADDED Requirements

### Requirement: A supplier answers the usages claimed of its products (REQ-010)

A supplier's organisation page SHALL show, to members of that supplier, a list "Claimed usage of our offering" with every usage whose provider is the supplier, owned by another organisation and not yet answered: the claiming organisation, product, version, module and claim date, with Accept and Decline. Accept SHALL record `providerResponse: accepted`, Decline `providerResponse: declined`, each with date and user, and SHALL NOT delete the usage. Only members of the supplier SHALL be able to answer; the check SHALL run before the write.

#### Scenario: A supplier accepts a claim
- GIVEN Gemeente Zuiddrecht registered that it uses Suite4Gemeenten 10.4 of Centric
- WHEN a Centric user opens Centric's organisation page and chooses Accept on that claim
- THEN the usage records the acceptance and leaves the list

#### Scenario: A supplier declines a claim
- GIVEN Gemeente Oostmaas claims Key2Financiën 2026.1 of Centric
- WHEN the Centric user chooses Decline and confirms
- THEN the usage stays Oostmaas's record with `providerResponse: declined`, and Oostmaas's usage page says the supplier does not recognise the claim

#### Scenario: Another organisation cannot answer
- GIVEN a user of Gemeente Zuiddrecht
- WHEN they call accept on a claim of Centric's product
- THEN the call is refused with 403 and nothing is written
