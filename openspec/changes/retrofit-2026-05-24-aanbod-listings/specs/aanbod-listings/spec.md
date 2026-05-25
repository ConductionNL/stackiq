---
status: draft
retrofit: true
---

# Aanbod Listings Specification

## Purpose

Captures observed behavior of the public HTTP surface for `aanbod` (software-catalogue offers — modules, diensten, koppelingen, gebruiks) exposed via `OCA\SoftwareCatalog\Controller\AanbodController`. Allows the active organisation to browse offers it consumes or provides, and to accept (claim ownership) or deny (delete) individual offers. Reverse-spec'd from existing code 2026-05-24.

## ADDED Requirements

### REQ-001: The system SHALL list aanbod objects for the active organisation

`GET /api/aanbod` MUST return modules, diensten, and koppelingen where the current organisation is in the `aanbieder` property, plus gebruiks where the current organisation is in the `afnemer` property. Objects whose `@self.organisation` equals the current organisation MUST be excluded. The endpoint MUST honour pagination via `limit`, `offset`, `page` query parameters and MUST force `_source: database` for real-time data. On service-level failure the response MUST be HTTP 500 with `error` set; on unexpected exception the response MUST be HTTP 500 with `error: 'Internal server error: <message>'` and empty paginated envelope.

#### Scenario: Successful listing returns 200 with paginated envelope
- GIVEN the active organisation has 5 aanbod objects assigned
- WHEN `GET /api/aanbod?limit=20` is called
- THEN the response status MUST be `200`
- AND the JSON body MUST contain `results` (array) and `total` (int)

#### Scenario: Service error returns 500 with error key
- GIVEN `AanbodService::getAanbod()` returns a payload containing `error`
- WHEN the controller handles the request
- THEN the response status MUST be `500`
- AND the response body MUST preserve the service payload (including `error`)

#### Scenario: Unhandled exception returns canonical envelope
- GIVEN the service throws an exception
- WHEN the controller catches it
- THEN the response status MUST be `500`
- AND the body MUST equal `{results: [], total: 0, page: 1, pages: 0, limit: 20, offset: 0, error: "Internal server error: <message>"}`

### REQ-002: The system SHALL allow accepting an aanbod offer

`PUT /api/aanbod/{uuid}/accept` MUST set the offer's `@self.organisation` to the active organisation, allowed only when the active org is the offer's `afnemer` (for gebruiks) or `aanbieder` (for modules/diensten/koppelingen). Empty `uuid` MUST return HTTP 400 with `{success: false, error: "Aanbod UUID is required", aanbod: null}`. The endpoint MUST forward optional body parameters (excluding `uuid`) to `AanbodService::acceptAanbod`. Status code mapping MUST be: success → 200; `Aanbod object not found` → 404; service error string containing `Operation not allowed` → 403; any other service failure or exception → 500.

#### Scenario: Empty uuid is rejected before service call
- GIVEN the request path resolves uuid to an empty string
- WHEN the controller runs
- THEN the response status MUST be `400`
- AND the body MUST equal `{success: false, error: "Aanbod UUID is required", aanbod: null}`

#### Scenario: Permission denial maps to 403
- GIVEN `AanbodService::acceptAanbod()` returns `{success: false, error: "Operation not allowed: not the afnemer"}`
- WHEN the controller maps the response
- THEN the response status MUST be `403`

#### Scenario: Not-found maps to 404
- GIVEN the service returns `{success: false, error: "Aanbod object not found"}`
- WHEN the controller maps the response
- THEN the response status MUST be `404`

### REQ-003: The system SHALL allow denying an aanbod offer by deletion

`DELETE /api/aanbod/{uuid}/deny` MUST delete the offer when the active org is the `afnemer` (for gebruiks) or `aanbieder` (for modules/diensten/koppelingen). Empty `uuid` MUST return HTTP 400 with `{success: false, error: "Aanbod UUID is required", deleted: false}`. Optional body parameters (excluding `uuid`) MUST be forwarded to `AanbodService::denyAanbod`. Status code mapping MUST mirror REQ-002 (success → 200; not found → 404; `Operation not allowed` → 403; otherwise → 500).

#### Scenario: Successful deletion returns 200
- GIVEN the service returns `{success: true, deleted: true}`
- WHEN the controller maps the response
- THEN the response status MUST be `200`
- AND the response body MUST contain `deleted: true`

#### Scenario: Empty uuid is rejected
- GIVEN the request path resolves uuid to an empty string
- WHEN the controller runs
- THEN the response status MUST be `400`

## Notes

- **`parseQueryOptions` is private plumbing**, not a separate REQ. Its behaviour (force `_source: database`, duplicate `limit`/`_limit` keys for compatibility) is implied by REQ-001's contract and tested indirectly via the listing scenarios.
- **`@PublicPage` + `@NoCSRFRequired` + `@NoAdminRequired`** are all present on every endpoint. The endpoints accept unauthenticated requests but rely on session-resolved `aanbieder`/`afnemer` matching for authorisation. This is intentional for the listing UX but should be documented in the security ADRs (out of scope here).
- **Service-side behaviour** (`AanbodService::getAanbod / acceptAanbod / denyAanbod`) is already covered by Bucket 1 REQ-DECOMP-011; this spec scopes the HTTP-level contract only.
- **Acceptance Criteria:** Endpoint contracts are covered by Newman collection runs against `/api/aanbod*`; no PHPUnit coverage of the controller layer at retrofit time.
