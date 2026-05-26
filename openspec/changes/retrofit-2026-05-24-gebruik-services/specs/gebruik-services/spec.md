---
status: draft
retrofit: true
---

# Gebruik Services Specification

## Purpose

Captures observed behavior of `OCA\SoftwareCatalog\Service\GebruikService`, the read-side service that exposes `gebruik` (usage) records from the voorzieningen register via OpenRegister. The service requires the OpenRegister app to be installed and the voorzieningen register/schema configuration to be set in the admin panel — there is no hardcoded fallback. Reverse-spec'd from existing code 2026-05-24.

## ADDED Requirements

### REQ-001: The system SHALL return paginated gebruik records for the active register/schema configuration

`getGebruiken(options)` MUST resolve the voorzieningen register + gebruik schema via `SettingsService::getVoorzieningenConfig()` and apply them to the search query as `@self.register` / `@self.schema`. The `extend` and `_extend` query options MUST be normalised to a string-array `_extend` (string input is split on commas + trimmed). The service MUST delegate to `ObjectService::searchObjectsPaginated` with `_rbac: false, _multitenancy: false` and return the paginated envelope. Each result MUST be stripped of the `interneAantekening` field before being returned. If configuration is missing OR if OpenRegister is not installed, the service MUST throw an `Exception` rather than return an empty result.

#### Scenario: Configured search returns paginated envelope
- GIVEN the voorzieningen register and gebruik schema are configured
- AND OpenRegister is installed
- WHEN `getGebruiken(['limit' => 10])` is called
- THEN the call MUST delegate to `ObjectService::searchObjectsPaginated` with `query['@self'] = { register: <id>, schema: <gebruik schema> }`
- AND the returned array MUST contain `results` (each without `interneAantekening`) and pagination metadata

#### Scenario: Extend string is split into array
- GIVEN configuration is in place
- WHEN `getGebruiken(['extend' => 'modules,diensten'])` is called
- THEN the query forwarded to ObjectService MUST contain `_extend: ['modules', 'diensten']`
- AND the original `extend` key MUST be removed

#### Scenario: Missing configuration throws
- GIVEN `SettingsService::getVoorzieningenConfig()` returns an array without `register` or `gebruik_schema`
- WHEN `getGebruiken([])` is called
- THEN the call MUST throw an `Exception` whose message references the missing voorzieningen configuration

#### Scenario: OpenRegister not installed throws
- GIVEN OpenRegister is not in the installed-apps list
- WHEN `getGebruiken([])` is called
- THEN the call MUST throw an `Exception` whose message says "OpenRegister app is not installed"

### REQ-002: The system SHALL return application UUIDs scoped to the active register

`getApplicationIds(options)` MUST resolve the voorzieningen register + module (applicatie) schema via SettingsService and run a paginated search via `ObjectService::searchObjectsPaginated` with `_rbac: false, _multitenancy: false`. For each result it MUST extract the application's UUID — prefer `$object['@self']['id']`, fall back to `$object['id']`, fall back to `null`. The returned value MUST be the bare array of UUIDs (not the full paginated envelope).

#### Scenario: Each application result yields a UUID
- GIVEN configuration is in place and the search returns 3 ObjectEntity instances
- WHEN `getApplicationIds([])` is called
- THEN the returned array MUST have length 3
- AND each entry MUST be either the `@self.id` value of the corresponding object or its `id`

## Notes

- **Bug — `getApplicationIds()` ObjectEntity branch is broken.** The current `array_map` callback for non-array `$object` has an empty `else if (method_exists($object, 'getId') === true) { }` block (line 197-198) followed by an *unconditionally indented* `$object = $object->getObject();` call (line 200). The indentation suggests it was meant to be inside the `else if`. As written, every non-array, non-jsonSerialize-supporting object hits `getObject()` regardless of method existence — and the if/else-if structure means `jsonSerialize`-supporting objects also fall through into `getObject()`. Observed behaviour is "best-effort UUID extraction" but the implementation is buggy — REQ-002 describes the *contract* (UUID extraction in `@self.id` → `id` → null order), not the buggy implementation. See follow-up issue.
- **No hardcoded fallback by design.** `getGebruiksConfiguration()` explicitly logs + throws when the admin panel has not been configured — this is the intended behaviour after a 2025 cleanup that removed hardcoded defaults.
- **`_rbac: false, _multitenancy: false`** — gebruik records are scoped per-organisation at the data model level; the service bypasses OR's RBAC + multi-tenancy because the active org is implicit in the data structure. Documented for security review.
- **Acceptance Criteria:** Covered indirectly by `GebruikController` Newman tests; no direct unit coverage at retrofit time.
