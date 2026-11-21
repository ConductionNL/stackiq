## ADDED Requirements

### Requirement: Every `searchObjects()` call MUST set an explicit `_limit`
Every call to `OCA\OpenRegister\Service\ObjectService::searchObjects()` from stackiq's `lib/` MUST pass an explicit `_limit` key in its query array.
Omitting `_limit` causes `MagicSearchHandler::searchObjects()` to
call `setMaxResults(null)`, which removes the LIMIT clause entirely and
fetches every row in the target register/schema table into PHP memory.

#### Scenario: A view-index query is bounded
- GIVEN `ViewService` builds a query for "all view objects" to populate
  the ArchiMate view index
- WHEN the query array is constructed
- THEN it MUST include an explicit `_limit` value
- AND the value MUST NOT be silently omitted or left to default

#### Scenario: A user-profile event listener does not scan the whole register
- GIVEN a Nextcloud user profile update event fires for one user
- WHEN `UserProfileUpdatedEventListener` looks up that user's contact-person
  record
- THEN the query MUST filter directly on the updated user's identifier
- AND MUST NOT rely on an unbounded full-table scan to find the match

#### Scenario: A sync tick that must cover every object pages instead of scanning unbounded
- GIVEN `OrganizationSyncService` must process every organisation or
  contact-person object during a sync tick
- WHEN more objects exist than fit in one bounded page
- THEN the service MUST page through results via `searchObjectsPaginated()`
  (or an explicit, documented `_limit` ceiling)
- AND MUST NOT issue a single unbounded `searchObjects()` call to cover
  the whole table
