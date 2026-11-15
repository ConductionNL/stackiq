## REMOVED Requirements

### Requirement: The aanbod listing endpoint MUST require authentication explicitly, not implicitly (REQ-009)

**Reason**: Ruben decided on 12 Nov 2026 (decision 392) that published entries are publicly readable without login, as `open-data-publishing` says. The new REQ-009 below keeps the explicit guard and lets an anonymous caller read published entries only.

**Migration**: `AanbodController::getAanbod()` answers an anonymous caller with the published entries instead of rejecting it; see the tasks of change `anonymous-aanbod-listing-is-published-only`.

## ADDED Requirements

### Requirement: The aanbod listing endpoint serves anonymous callers published entries only (REQ-009)

`AanbodController::getAanbod()` MUST decide explicitly what an unauthenticated caller gets, before it invokes `AanbodService::getAanbod()`, and MUST NOT rely on `getCurrentOrganisation()` returning `null` as the only safeguard. An unauthenticated caller MUST receive only published aanbod entries (`publicatiedatum` at or before now and no `depublicatiedatum` passed) in the sanitized open-data projection, as `open-data-publishing` says. Every other aanbod entry, and every organisation-scoped field, MUST need login.

#### Scenario: An anonymous caller lists the aanbod
- GIVEN a published aanbod entry "Zaaksysteem X" and an unpublished entry "Concept Y"
- AND no authenticated user session
- WHEN `GET /api/aanbod` is called
- THEN the response contains "Zaaksysteem X" in the sanitized open-data projection
- AND it does not contain "Concept Y" or any organisation-scoped field

#### Scenario: Authenticated caller with no active organisation gets the documented empty envelope
- GIVEN an authenticated user with no active organisation set
- WHEN `GET /api/aanbod` is called
- THEN the response MUST be the empty-result envelope
- AND no cross-organisation data MUST be returned
