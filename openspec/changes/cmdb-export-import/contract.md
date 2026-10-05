# Contract: cmdb-export-import

## Consumers

- `stackiq` frontend: the "CMDB import" admin-settings section (`src/views/settings/sections/CmdbImport.vue`) is the only caller of the two new endpoints.
- `opencatalogi` and `portaliq` call no new endpoint. They read the objects the import writes through their existing OpenRegister paths. Their interface is the data shape below: `module.publicationDate` for OpenCatalogi, and `usage.consumer` / `usage.module` for Portaliq. Neither gets owner data anonymously: `usage` and `contactPerson` have no public read rule, and a public `module` refers to them by id only.

Paths are relative to `/index.php/apps/stackiq`.

## Endpoints

### `POST /api/cmdb-import`
**Auth**: Nextcloud session of a Nextcloud admin, or of a member of a group an admin delegated the stackiq admin settings to (`#[AuthorizedAdminSetting(settings: StackiqAdmin::class)]`), plus CSRF `requesttoken` (header or form field). No `NoAdminRequired`, no `NoCSRFRequired`.

**Request:** `multipart/form-data`

| Field | Type | Required | Default | Meaning |
|---|---|---|---|---|
| `cmdbFile` | file | yes | | the TOPdesk export, `.xlsx`, at most 10 MB |
| `municipalityUuid` | string (uuid) | one of the two | | an existing `organization` of type Municipality |
| `municipalityName` | string | one of the two | | name of a Municipality to reuse (same normalised name) or create |
| `updateExisting` | `true`/`false` | no | `true` | `false` reports matched rows as skipped (`exists`). `1`/`0` are accepted too, trimmed and in any case; any other value is refused with 400 `FIELD_INVALID` |
| `missingRecords` | string | no | `keep` | only `keep` is accepted; `mark` and `remove` are reserved |
| `operationId` | string | no | generated | progress operation id, readable through `GET /api/progress/{operationId}`; `cmdb-` followed by 8 to 64 letters, digits or hyphens (for example `cmdb-` plus a uuid v4). Any other value is replaced by a generated id, returned as `operationId` |

**Response (200):**
```json
{
  "success": true,
  "operationId": "cmdb-00000000-0000-0000-0000-000000000000",
  "cancelled": false,
  "municipality": { "uuid": "00000000-0000-0000-0000-000000000001", "name": "Gemeente Voorbeeldstad", "created": false },
  "summary": { "rowsRead": 2, "processed": 2, "created": 2, "updated": 0, "unchanged": 0, "skipped": 0, "failed": 0, "warnings": 1 },
  "importWarnings": [],
  "rows": [
    {
      "sheet": "Onbeh Applicaties CMDB",
      "row": 2,
      "appId": "1234",
      "name": "Aangetekend Mailen",
      "outcome": "created",
      "reasons": [],
      "warnings": ["Column \"Applicatiesoort\": Value \"Webapplicatie\" has no mapping and no default is configured"],
      "moduleUuid": "00000000-0000-0000-0000-000000000004",
      "usageUuid": "00000000-0000-0000-0000-000000000005"
    }
  ]
}
```

`appId` is the row's APPID, the match key (`''` when the row has none). `outcome` is one of `created`, `updated`, `unchanged`, `skipped`, `failed`. `reasons` and `warnings` are translated strings that name columns and values; a formula cell without a cached value gives the warning `Column "<column>": formula without a cached value, read as empty`. They never contain owner names, e-mail addresses or other person data. `summary.rowsRead` counts the non-empty rows in the workbook; `summary.processed` counts the rows in `rows`, which is lower than `rowsRead` only after a cancel. `summary.warnings` counts row warnings; `importWarnings` are not included.

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `NO_FILE_UPLOADED`, `NOT_XLSX`, `FIELD_INVALID` |
| 401  | not signed in (Nextcloud) |
| 403  | neither a Nextcloud admin nor a delegated stackiq admin (Nextcloud) |
| 412  | missing or invalid CSRF token (Nextcloud) |
| 413  | `FILE_TOO_LARGE` |
| 422  | `MISSING_RECORDS_UNSUPPORTED`, `MUNICIPALITY_REQUIRED`, `MUNICIPALITY_INVALID`, `NO_SOURCE_SHEET`, `MISSING_COLUMN`, `TOO_MANY_ROWS` |
| 500  | `IMPORT_FAILED` (unexpected; generic message, details only in the log) |
| 503  | `MAPPING_UNAVAILABLE`, `READER_UNAVAILABLE`, `NOT_CONFIGURED` |

Error body: `{"success": false, "error": "<CODE>", "message": "<translated text>", "details": {...}}`. `details` is always an object, empty when the code has none. For `MISSING_COLUMN`, `details` is `{"sheet": "...", "column": "..."}`. For `NO_SOURCE_SHEET`, it is `{"expected": ["Onbeh Applicaties CMDB", "Beheerde Applicaties CMDB"]}`. For `TOO_MANY_ROWS`, it is `{"sheet": "...", "limit": 10000}`. For `FILE_TOO_LARGE`, it is `{"maxBytes": 10485760}`. For `MISSING_RECORDS_UNSUPPORTED`, it is `{"accepted": ["keep"]}`. For `FIELD_INVALID`, it is `{"field": "updateExisting", "accepted": ["true", "false"]}`.

### `POST /api/cmdb-import/{operationId}/cancel`
**Auth**: the same as the import: a Nextcloud admin or delegated stackiq admin session, plus CSRF token.

**Request:** no body.

**Response (200):**
```json
{ "success": true, "cancelRequested": true }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 403  | neither a Nextcloud admin nor a delegated stackiq admin |
| 404  | `OPERATION_NOT_FOUND`: no `cmdb_import` operation with this id |
| 412  | missing or invalid CSRF token |

### `GET /api/progress/{operationId}` (existing, unchanged)
Returns the `ProgressTracker` snapshot for the `cmdb_import` operation: `progress.total_items` is the number of non-empty rows read and `progress.processed_items` the rows done so far, updated after every row. `progress.status` is `running`, `completed` or `cancelled`. After completion, `progress.statistics.report` holds the report from the 200 response above, for as long as the tracker keeps the entry (one hour).

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| `NO_FILE_UPLOADED` | no file | `cmdbFile` missing |
| `NOT_XLSX` | not an xlsx workbook | extension is not `.xlsx`, no ZIP signature, or no `xl/workbook.xml` |
| `FILE_TOO_LARGE` | too large | larger than the profile's `maxFileBytes` (10 MB) |
| `MISSING_RECORDS_UNSUPPORTED` | option not supported | `missingRecords` is not `keep` |
| `FIELD_INVALID` | malformed field (400) | `updateExisting` is not `true`, `false`, `1` or `0` |
| `MUNICIPALITY_REQUIRED` | no consumer | neither `municipalityUuid` nor `municipalityName` given |
| `MUNICIPALITY_INVALID` | wrong consumer | uuid unknown, or the organisation is not of type Municipality |
| `NO_SOURCE_SHEET` | nothing to read | neither "Onbeh Applicaties CMDB" nor "Beheerde Applicaties CMDB" present |
| `MISSING_COLUMN` | required column absent | a present source sheet lacks "APPID" or "Applicatie Naam" |
| `TOO_MANY_ROWS` | file too large to process | a source sheet has more non-empty rows than `maxRowsPerSheet` (10,000) |
| `MAPPING_UNAVAILABLE` | mapping cannot run | OpenRegister's `MappingEngine`/`PackDefinitionValidator` missing, or a shipped pack is invalid |
| `READER_UNAVAILABLE` | xlsx reader missing | PhpSpreadsheet's Xlsx reader cannot be loaded |
| `NOT_CONFIGURED` | stackiq not configured (503) | OpenRegister's object service, the stackiq register, or the `module`, `organization`, `usage` or `contactPerson` schema cannot be resolved; checked before the file is read |
| `OPERATION_NOT_FOUND` | unknown operation | cancel for an id without a `cmdb_import` operation |
| `IMPORT_FAILED` | unexpected error | anything not listed above |

## Versioning

Internal app API, unversioned like the other stackiq settings endpoints. The report fields above are additive-only: new fields MAY be added, and existing fields keep their meaning. (Before the first release the row field `middelId` was renamed to `appId`, together with the switch of the match key to the APPID.) The `module` properties `externalId`, `externalNumber`, `externalKey`, `externalCreatedAt` and `externalModifiedAt` are part of the register schema and follow the register's versioning (`module` 0.3.5).

## Breaking Change Policy

A breaking change to the endpoints only affects stackiq's own settings section and ships in the same release. A change to the meaning of `externalKey` (the matching rule) is breaking for repeat imports. It requires a new OpenSpec change with a migration that rewrites the stored keys.

## SLA

Synchronous request. An unchanged 1,100-row export SHALL finish within PHP's default execution limits on the local rig. Progress is readable while the request runs. No availability promise beyond the Nextcloud instance itself.
