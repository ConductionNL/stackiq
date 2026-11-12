# catalog-ratings specification

## Purpose

A review carries a rating, and stays hidden until a moderator approves it, on every path that writes one. Matrix rows `stackiq:mkt-reviews` and `stackiq:mkt-review-moderation`.

## ADDED Requirements

### Requirement: A pending review is readable only by its author and the moderators (REQ-CLR-008)

A review SHALL be readable by others only once its status is approved. The Reviews index SHALL show a pending review only to its author and the catalogue admins. The review form SHALL NOT let its author set the status.

#### Scenario: A colleague does not see a pending review
@e2e tests/e2e/workflows/review-moderation.spec.ts

- **GIVEN** a pending review written by an application owner of Gemeente Voorbeeld
- **WHEN** a user of another organisation opens the Reviews index
- **THEN** the pending review SHALL NOT be listed

#### Scenario: The author cannot approve their own review
@e2e tests/e2e/workflows/review-moderation.spec.ts

- **GIVEN** an application owner adding a review on the Reviews index
- **WHEN** the form opens
- **THEN** it SHALL NOT offer the field status
- **AND** the saved review SHALL read pending

### Requirement: A review carries a rating from 1 to 10 (REQ-CLR-009)

A review SHALL hold a rating from 1 to 10. The review form SHALL ask for it, and the application page SHALL show it with the review.

#### Scenario: A user rates an application they use
@e2e tests/e2e/workflows/catalog-ratings.spec.ts

- **GIVEN** an application owner on the page of an application their organisation uses
- **WHEN** they write a review with rating 8 and submit it
- **THEN** the review SHALL be saved with rating 8 and status pending
