---
kind: code
depends_on: []
---

# BIO measures ship with the app, show their level, and a claim can say "does not meet"

## Summary

The BIO measures page exists, but its level column reads `bbnNiveau` while the schema property is `bbnLevel`, so the column is empty (the level only shows on the detail page). The BIO 2.0 measures themselves are not shipped: only the mock register holds a few, so an administrator has to type them in. On the assessment side, a compliance claim can only say an application meets a measure. There is no way to record that it does not, so "does not meet" cannot be told apart from "not assessed", and the compliance column `bioMaatregel` differs from the property `bioMeasure`. Boards SkStandaarden and SkComplianceMatrix draw the level, and the states Geverifieerd, Geclaimd, Voldoet niet and Onbekend. The board is the canon (decision 130), so the two rows move from built to building with this plan.

## Rows

- `comp-bio-measures` "Browse the BIO information security measures with their theme and level."
- `comp-bio-assessment` "Record which BIO measures an application meets and which it does not."

## What changes

- The BIO measures index reads `bbnLevel`.
- A register fragment ships the BIO 2.0 measures (code, name, theme, BBN level, source), imported on install and upgrade without duplicating measures an administrator already entered (matched on code).
- `compliancy` gets a `result` property: `meets`, `does-not-meet` (default `meets` for existing claims). The compliance matrix and the application's compliance list show four states: verified (meets with third-party evidence), claimed (meets without), does not meet, and unknown (no claim).
- The matrix column key `bioMaatregel` becomes `bioMeasure`.

## Screens

See `screens.md`: SkStandaarden, SkComplianceMatrix.
