<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Portfolio rationalization (Gartner TIME)

Adds Gartner TIME classification (**T**olerate / **I**nvest / **M**igrate /
**E**liminate) to each application-in-use (`gebruik`), and a
per-organisation **portfolio rationalization report** that combines TIME
quadrant counts with existing end-of-support exposure
(`application-lifecycle-tracking`), cloud-transition share (the existing
`cloudDienstverleningsmodel` field), and annualised cost overlay
(`contract-administration`). See
[VNG Softwarecatalogus issue #54](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/54).

Specification:
[`openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md`](../../openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md).

> Screenshots of the report page are still pending a live-instance capture —
> this document ships with the implementation; the Playwright-captured
> images will follow in a subsequent docs pass (see the design.md Open
> Questions note on the report's live-verification follow-up).

## Classifying a gebruik

Three new **optional** fields on the existing `gebruik` schema — recorded
per organisation's usage of an application, never on the module/application
itself (mirroring how `geplandeVervanging` is scoped per gebruik):

| Field                 | Type              | Notes                                                        |
|------------------------|-------------------|---------------------------------------------------------------|
| `timeClassification`   | enum (string)     | `Tolerate` \| `Invest` \| `Migrate` \| `Eliminate`             |
| `timeRationale`        | string            | Free-text justification for the classification                |
| `timeReviewDate`       | date (string)     | When the classification should next be reviewed                |

A gebruik with no `timeClassification` set is **Unclassified** — it is
never hidden from the report, and is excluded from every quadrant count
until classified.

Editing happens through the app's generic schema-driven object editor
(`ObjectModal.vue`, `object-type-key="gebruik"`), which now renders any
`enum`-on-`string` schema property (not just the three TIME fields — this
also improves `status` and every other enum field project-wide) as a
clearable dropdown instead of free text. Because the editor reads the full
current object before submitting (`formData = cloneDeep(activeObject)`) and
only mutates the edited key, a TIME-only edit carries every other gebruik
field forward unchanged — OpenRegister's `saveObject` is PUT-semantic, so
omitted fields would otherwise be nulled out.

## Cloud-transition share reuses the existing Hosting field

No new deployment-model field is introduced. `gebruik` already carries
`cloudDienstverleningsmodel` ("Hosting": On-premises (self-managed) / IaaS
/ PaaS / SaaS, `facetable: true`) — the report's cloud-transition metric
reads that field directly, so the existing Hosting column and this report
never fork into two competing sources of the same fact.

## The portfolio rationalization report

`GET /apps/stackiq/api/portfolio-report?organisation={uuid}`

Returns a bounded, organisation-scoped aggregate:

```json
{
  "organisation": "org-a",
  "generatedAt": "2026-07-23T12:00:00+00:00",
  "pageSizeCeiling": 500,
  "totalGebruiken": 42,
  "includedGebruiken": 42,
  "truncated": false,
  "quadrants": {
    "Tolerate": { "count": 5, "eolExposedCount": 1, "cloudTransition": { "SaaS": 3, "On-premises (self-managed)": 2 }, "annualisedCost": 12000, "oneOffCost": 0 },
    "Invest": { "...": "..." },
    "Migrate": { "...": "..." },
    "Eliminate": { "...": "..." },
    "Unclassified": { "...": "..." }
  },
  "rows": [
    { "uuid": "g1", "moduleName": "Example App", "timeClassification": "Migrate", "quadrant": "Migrate", "timeRationale": "Vendor lock-in, successor selected", "timeReviewDate": "2027-01-01", "lifecyclePhase": "In productie", "eol": { "passed": false, "withdrawn": false, "endDate": "2027-06-01" }, "eolApproaching": false, "hostingModel": ["SaaS"], "annualisedCost": 4800, "oneOffCost": 0 }
  ]
}
```

Every figure is **computed at query time, never persisted** — the same
"derive, don't cache" principle as the lifecycle-tracking phase/EOL rules.
`PortfolioReportService` composes three existing derivations rather than
forking a second implementation:

- **TIME quadrant counts** — from `gebruik.timeClassification`.
- **EOL exposure** — reusing the `application-lifecycle-tracking`
  end-of-support rule (a moduleVersie's `datumEindeOndersteuning` passed, or
  within the 180-day approaching window).
- **Cloud-transition share** — from `cloudDienstverleningsmodel`.
- **Annualised cost overlay** — reusing the `contract-administration` cost
  derivation (`kosten` × `kostenPeriode`) over each gebruik's linked
  contracts.

Pure phase/EOL/cost/relation-id rules live in `PortfolioReportDerivation`
(no I/O), separated from the OpenRegister-querying orchestration in
`PortfolioReportService` to keep both testable and under the project's
complexity thresholds.

### Bounded, always

Every OpenRegister query the report issues carries an explicit `_limit` (or
uses `searchObjectsPaginated`) — never an unbounded `searchObjects()` call,
per the `bound-unbounded-searchobjects-scans` rule. The gebruik query is
capped at a configurable **page-size ceiling** (`portfolio_report_page_size_ceiling`
app config, default `500`); when an organisation's gebruik count exceeds
it, the response's `truncated: true` + `totalGebruiken` / `includedGebruiken`
disclose the truncation — the report never presents a bounded subset as a
silently-complete total. The linked-contract lookup is bounded to
`5 × the gebruik ceiling` (capped at 5000), since one gebruik may have
several linked contracts (renewals, multiple services).

### RBAC: scoped to the caller's authorised organisation(s)

The controller resolves and checks the caller's organisation access
**before** the service ever issues a query for the requested organisation
(fail closed, per `vendor-visibility-rbac`):

- `admin` / `ambtenaar` may request **any** organisation's report (the
  existing unrestricted-read bypass those roles already have on gebruik
  reads).
- Every other authenticated user may request **only their own** active
  organisation's report — a report synthesises another organisation's
  gebruik/contract data, which is not granted beyond the caller's own
  organisation.
- A request for an unauthorised organisation returns `403` with **no**
  organisation data in the response body, and the CSV export variant is
  denied the same way.

> Re-verify this enforcement point once `vendor-visibility-rbac` lands as
> its own change — the gating mechanism may move from the
> `IConfig::getUserValue('core', 'organisation')` lookup used here to
> whatever canonical mechanism that change introduces.

### CSV export

`GET /apps/stackiq/api/portfolio-report?organisation={uuid}&format=csv`

The **same** bounded, RBAC-scoped row set as the JSON report, serialised as
CSV (one row per gebruik: organisation, module, TIME classification,
rationale, review date, lifecycle phase, EOL status, hosting model,
annualised + one-off cost) — never a second, unbounded/unscoped export
path.

### Report page

`/portfolio-report` (manifest page `PortfolioReport`, component
`PortfolioReportView`) renders:

- An organisation picker.
- A TIME quadrant bar chart (`CnChartWidget`, apexcharts via
  `@conduction/nextcloud-vue`), one bar per quadrant including
  Unclassified, coloured with NL Design System semantic tokens
  (`--color-success` / `--color-warning` / `--color-error` /
  `--color-text-maxcontrast` / `--color-border-dark` — never a hardcoded
  hex).
- A quadrant summary table (count, EOL-exposed count, cloud-transition
  mix, annualised + one-off cost).
- Gebruik-level detail tables, one section per quadrant (Unclassified
  always rendered, even when empty).
- A truncation banner when the report is bounded.
- An **Export CSV** button.

Unlike `LifecycleRoadmapView` / `LicensePostureView` (which derive their
figures client-side over a full collection fetched into the browser), this
page is a thin renderer over the single composed backend endpoint above —
the aggregation and RBAC scoping live server-side, per design.md Decision
3/4.
