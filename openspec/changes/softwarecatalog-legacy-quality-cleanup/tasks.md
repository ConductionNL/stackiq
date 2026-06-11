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
      and capture violation count + categories. **~163 violations**,
      dominated by **162 ElseExpression** + 1 LongParameterList
      (11-arg DI constructor in `SoftwareCatalogueService`).
- [x] Run `composer phpstan` for the first time as a unified gate
      and capture error count + categories. A pre-existing
      `phpstan-baseline.neon` exists; the fresh run surfaced **6
      above-baseline errors** (3 never-read properties, 1 wrong
      return type, 2 strict-comparison-always-false) — all fixed
      outright.
- [x] Decide per gate: fix-outright (if <50 violations) or capture
      a fresh baseline (if larger). Decision:
      - **PHPCS**: fix-outright (1 error, phpcbf-autofixed).
      - **PHPMD**: >50 violations → capture fresh baseline
        (`phpmd.baseline.xml`), matching the fleet pattern
        (pipelinq / openbuild / procest / decidesk). The 162
        ElseExpression hits live in large legacy service files;
        hand-reshaping them risks real-code breakage (CLAUDE.md
        "no scripting for code changes") so they are baselined for
        incremental burn-down, not bulk-rewritten.
      - **PHPStan**: 6 errors → fix-outright (all fixed).
- [x] Confirm CI runs the quality gate on every PR before starting
      burn-down work. `.forgejo/workflows/pre-merge-check-strict.yaml`
      added (runs `composer check:strict` + all 19 Hydra gates on
      every PR to main/beta/development). Also `.github/workflows/
      code-quality.yml` calls shared `Conduction/.github` reusable
      quality workflow.

## Phase 2 — PHPCS burn-down (per excluded file)

- [x] No legacy-debt `<exclude-pattern>` block exists in `phpcs.xml`
      (only standard vendor/node_modules/template excludes remain),
      so there is nothing to burn down. The single surfacing sniff
      error was fixed outright (see Phase 1).
- [x] phpcs runs error-clean across all 59 lib files (`phpcs -n`
      exits 0).

## Phase 3 — PHPMD burn-down

Baseline captured (volume > 50). `phpmd.baseline.xml` added and
`--baseline-file phpmd.baseline.xml` wired into composer.json's
`phpmd` script using `./vendor/bin/phpmd` — matching the fleet
(pipelinq/openbuild/procest).

- [x] Baseline captured so the gate is green; incremental burn-down
      of the baselined rules below is left for follow-up PRs:
  - [~] ElseExpression — re-shape `if/else` to early-return; left for
        follow-up PRs (162 hits, large legacy service files, CLAUDE.md
        "no scripting for code changes" rule precludes bulk rewrite)
  - [~] LongParameterList (`SoftwareCatalogueService::__construct`,
        11 DI deps) — introduce a parameter object if it grows; left
        for follow-up PR (touches every caller; risk vs benefit deferred)
- [~] Once baseline reaches 0 lines: delete `phpmd.baseline.xml`
      and drop `--baseline-file` from composer.json's phpmd script —
      deferred until the two ElseExpression / LongParameterList rules
      burn down (follow-up PR series)

## Phase 4 — PHPStan burn-down

- [x] Inventory phpstan state: 6 above-baseline errors surfaced by
      the first run; all fixed outright:
      - `ModuleRegistrationHandler`: removed unused `$objectService`
        injected dependency
      - `SyncHandler`: removed unused `$settingsService` injected
        dependency
      - `OrganizationSettingsHandler`: removed unused `$groupManager`
        injected dependency; widened `$groups` PHPDoc to `mixed[]`
        to allow runtime `is_string()` guard
      - `SettingsController::updateConfigSettings()`: changed return
        type from `?JSONResponse` to `void` (method never returned
        a response); updated caller accordingly
      - `GebruikBulkHandler::validateBulkInput()`: widened `$items`
        PHPDoc from `array<int,array<string,mixed>>` to
        `array<int,mixed>` to allow runtime `is_array()` guard
- [x] Gate runs clean (0 errors) against current code.
- [~] Incremental burn-down of the existing 631-line
      `phpstan-baseline.neon` entries (return/param types, mixed
      types, possibly-null derefs) is left for follow-up PRs.
      Baseline keeps the gate green; per-file burn-down is sized for
      separate PRs.

## Phase 5 — CI integration

- [x] `.forgejo/workflows/pre-merge-check-strict.yaml` added:
      - runs `composer check:strict` on `codeberg-small`
      - clones Hydra + runs all 19 gates diff-scoped per ADR-020
      - uses short-form `uses: https://code.forgejo.org/actions/...`
        (not reusable workflow — inline steps for full control)
      - triggers on PR to `development`, `main`, `beta`
- [x] `phpmd` composer script updated to use `./vendor/bin/phpmd`
      with `--baseline-file phpmd.baseline.xml` (fleet pattern)
- [~] Once all baselines are empty:
  - [~] Delete `phpmd.baseline.xml` — gated on PHPMD burn-down (see Phase 3)
  - [~] Delete `phpstan-baseline.neon` — gated on PHPStan burn-down (see Phase 4)
- [~] Add a smoke-test cron that runs the strict gate weekly on
      `development` (follow-up; the per-PR gate is the active guard
      and a stricter signal than a weekly cron — the per-PR gate
      runs on every push including direct-to-dev).

## Phase 6 — Documentation

- [x] tasks.md updated with actual findings + decisions.
- [x] `app-config.json` does not exist in this repo — no marker to
      set; the README is the canonical quality-gates record. N/A;
      the absence of `app-config.json` makes this task a no-op.
- [~] Close the burn-down tracking issue once the last baseline
      line is removed — coordinator-owned; gated on the PHPMD / PHPStan
      burn-downs above. Not part of this build.
