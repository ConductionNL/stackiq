# gebruik-services Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-24-gebruik-services. Update Purpose after archive.

@e2e exclude PHP gebruik (usage) service backend (usage record assembly/mapping into OpenRegister) — no UI surface; covered by PHPUnit service tests.

## Requirements
### Requirement: The system SHALL return paginated gebruik records for the active register/schema configuration (REQ-001)

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

### Requirement: The system SHALL return application UUIDs scoped to the active register (REQ-002)

`getApplicationIds(options)` MUST resolve the voorzieningen register + module (applicatie) schema via SettingsService and run a paginated search via `ObjectService::searchObjectsPaginated` with `_rbac: false, _multitenancy: false`. For each result it MUST extract the application's UUID — prefer `$object['@self']['id']`, fall back to `$object['id']`, fall back to `null`. The returned value MUST be the bare array of UUIDs (not the full paginated envelope).

#### Scenario: Each application result yields a UUID
- GIVEN configuration is in place and the search returns 3 ObjectEntity instances
- WHEN `getApplicationIds([])` is called
- THEN the returned array MUST have length 3
- AND each entry MUST be either the `@self.id` value of the corresponding object or its `id`

