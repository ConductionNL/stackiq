# Tasks: SoftwareCatalog Legacy Quality Cleanup

## Phase 1 — Inventory + planning

- [x] Run `composer phpcs` and capture current baseline error count.
      Actual state: phpcs.xml has NO legacy-debt exclude block (the
      proposal's "4 exclude-patterns" predate the current config —
      only the standard `*/vendor/*`, `*/vendor-bin/*`,
      `*/node_modules/*`, `composer-setup.php` and the no-op
      `lib/Resources/template/*` excludes remain). First run found
      **1 real error** (MultipleStatementAlignment in
      `OrganizationSyncService.php`) plus `@spec` SpecTagSniff
      warnings (warning-only, `ignore_warnings_on_exit=1`).
- [x] Run `composer phpmd` for the first time as a unified gate
      and capture violation count + categories. **~180 violations**,
      dominated by **170 ElseExpression**, plus 3 LongVariable,
      2 NPathComplexity, 2 CyclomaticComplexity, 2 LongMethod,
      1 LongParameterList (an 11-arg DI constructor).
- [x] Run `composer phpstan` for the first time as a unified gate
      and capture error count + categories. A 631-line
      `phpstan-baseline.neon` already exists; the gate runs
      **clean (0 above-baseline errors)** at level 5.
- [x] Decide per gate: fix-outright (if <50 violations) or capture
      a fresh baseline (if larger). Decision:
      - **PHPCS**: fix-outright (1 error, phpcbf-autofixed).
      - **PHPMD**: >50 violations → capture fresh baseline
        (`phpmd.baseline.xml`), matching the fleet pattern
        (pipelinq / openbuild / procest / decidesk). The 170
        ElseExpression hits live in 6000-line legacy service files;
        hand-reshaping them risks real-code breakage (CLAUDE.md
        "no scripting for code changes") so they are baselined for
        incremental burn-down, not bulk-rewritten.
      - **PHPStan**: baseline already exists and is green; nothing
        to capture.
- [x] Confirm CI runs the quality gate on every PR before starting
      burn-down work. `.github/workflows/code-quality.yml` calls the
      shared `Conduction/.github` `quality.yml` reusable workflow on
      every PR to main/beta/development (psalm + phpstan + phpcs +
      phpmd + phpunit + eslint).

## Phase 2 — PHPCS burn-down (per excluded file)

- [x] No legacy-debt `<exclude-pattern>` block exists in `phpcs.xml`
      (only standard vendor/node_modules/template excludes remain),
      so there is nothing to burn down. The single surfacing sniff
      error was fixed outright (see Phase 1).
- [x] phpcs runs error-clean across all 43 lib files (`phpcs -n`
      exits 0).

## Phase 3 — PHPMD burn-down

Baseline captured (volume > 50). `phpmd.baseline.xml` added and
`--baseline-file phpmd.baseline.xml` wired into composer.json's
`phpmd` script — matching the fleet (pipelinq/openbuild/procest).

- [x] Baseline captured so the gate is green; incremental burn-down
      of the baselined rules below is left for follow-up PRs:
  - [ ] ElseExpression — re-shape `if/else` to early-return
  - [ ] CyclomaticComplexity / NPathComplexity — extract methods
  - [ ] LongMethod — extract methods
  - [ ] LongVariable — rename
  - [ ] LongParameterList (`SoftwareCatalogueService::__construct`,
        11 DI deps) — introduce a parameter object if it grows
- [ ] Once baseline reaches 0 lines: delete `phpmd.baseline.xml`
      and drop `--baseline-file` from composer.json's phpmd script

## Phase 4 — PHPStan burn-down

- [x] Inventory phpstan state: 631-line `phpstan-baseline.neon`
      already in place; gate runs clean at level 5.
- [ ] Incremental burn-down of the existing baseline entries
      (return/param types, mixed types, possibly-null derefs) is
      left for follow-up PRs.
- [x] Confirm gate runs clean against current code (it does).

## Phase 5 — CI integration

- [x] Verify the quality gate runs in CI on every PR
      (`code-quality.yml`, see Phase 1).
- [ ] Once all baselines are empty:
  - [ ] Delete `phpmd.baseline.xml`
  - [ ] Delete `phpstan-baseline.neon`
- [ ] Add a smoke-test cron that runs the strict gate weekly on
      `development` (follow-up; the per-PR gate is the active guard).

## Phase 6 — Documentation

- [x] Update README quality-gates section (note the phpmd baseline
      + burn-down posture).
- [ ] `app-config.json` does not exist in this repo — no marker to
      set; the README is the canonical quality-gates record.
- [ ] Close the burn-down tracking issue once the last baseline
      line is removed (follow-up).
