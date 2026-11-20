# AGENTS.md

Guidance for coding agents working in this repository. Every command and path below exists in this repo.

## What this app is

Stackiq is a Nextcloud app for software portfolio management: it registers the applications, modules and connections (koppelingen) of an organisation's IT landscape, aligned with the Dutch GEMMA standards. It stores all its data as OpenRegister objects (no own database tables) and provisions Nextcloud users and groups from register contacts (see `README.md` and `appinfo/info.xml`).

## Setup

- `CONTRIBUTING.md` (Development Setup) lists the prerequisites: PHP 8.1+, Node.js 20+, Composer, then `composer install` and `npm install`, inside the external Nextcloud development environment ConductionNL/nextcloud-docker-dev.
- `docker-compose.yml` is an evaluation stack (Nextcloud with Stackiq and OpenRegister, PostgreSQL, Redis): `docker compose -f docker-compose.yml up -d --build`, then open http://localhost:8080/apps/stackiq/. Its header comments list the settings.
- `stackiq-compose.yaml` is a demo environment, explicitly not a development environment: it does not bind-mount your working copy.
- OpenRegister must be installed and enabled for the app to work.

## Build

```bash
npm run build      # production webpack bundle (runs check:vue-demi first)
npm run dev        # development bundle
npm run watch      # development bundle, rebuild on change
```

## Test

PHP (PHPUnit):

```bash
composer test:unit     # unit tests, phpunit-unit.xml, tests/Unit
composer test:all      # full phpunit.xml suite; needs a Nextcloud server tree (skips otherwise)
```

JavaScript (Vitest, config in `vitest.config.js`, specs in `tests/vitest/`):

```bash
npm test               # vitest run
npm run test:coverage  # with coverage summary
```

End-to-end (Playwright, config in `playwright.config.ts`, specs in `tests/e2e/`):

```bash
npm run test:e2e       # all projects
npm run test:smoke     # smoke project only
```

Translations and manifest:

```bash
npm run test:l10n          # every t()/n() key is present in l10n/en.json
npm run test:l10n:parity   # opt-in check that every required locale is complete
npm run check:manifest     # schema-validates src/manifest.json
```

## Lint and quality

```bash
composer check         # lint, phpcs, psalm, test:unit
composer check:strict  # lint, phpcs, phpmd, psalm, phpstan, test:all
composer cs:check      # php-cs-fixer dry run (composer cs:fix to apply)
composer phpcs         # PHP_CodeSniffer with phpcs.xml
composer phpstan
composer psalm
npm run lint           # ESLint over src, tests, scripts
npm run stylelint
npm run format         # Prettier check (npm run format:fix to apply)
```

## OpenSpec flow

Work is planned with OpenSpec (`openspec/config.yaml`, schema `conduction`):

1. A change starts in `openspec/changes/<name>/` with `proposal.md`, `design.md`, `tasks.md` and delta specs under `specs/`.
2. Accepted requirements live in `openspec/specs/<capability>/spec.md` (see `openspec/specs/README.md`).
3. Finished changes move to `openspec/changes/archive/<date>-<name>/`.

Templates for these files are in `openspec/schemas/conduction/templates/`.

## Architecture decisions

App-level decisions that have shipped are recorded as numbered records in [docs/adr/](docs/adr/README.md). New decisions are first written in the `design.md` file of an OpenSpec change (`openspec/changes/**/design.md`, including `archive/`). `docs/ARCHITECTURE.md` describes the services and the synchronisation design. The files under `.claude/openspec/architecture/` are copies of organisation-wide records, not decisions specific to this app.

## Repo conventions

- Thin client: the app owns no database tables; all data goes through OpenRegister. The register definition is `lib/Settings/softwarecatalogus_register.json`, and there is no `lib/Migration/` directory.
- Pages and navigation are manifest configuration: `src/manifest.json` declares the pages, and `src/main.js` merges the per-change fragments in `src/manifest.d/` at build time and primes the router from the manifest pages.
- User-visible strings are wrapped for translation; `l10n/` holds the catalogues and `npm run test:l10n` checks coverage.

## Commit format

Conventional Commits (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`), present tense, imperative mood, first line at most 72 characters, as stated in `CONTRIBUTING.md`.
