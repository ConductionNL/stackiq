---
description: Run the Stackiq Playwright end-to-end tests against an isolated Nextcloud instance
---

Run the Playwright suite (`playwright.config.ts`, specs in `tests/e2e/`).

1. The suite has no default target. Set one of the variables accepted by `tests/e2e/base-url.ts`, for example `PLAYWRIGHT_BASE_URL`, to an isolated instance with Stackiq and OpenRegister enabled. Never point it at a shared development container: the suite creates data. Admin credentials come from `NC_ADMIN_USER` and `NC_ADMIN_PASS` (both default to `admin`).

2. Make sure browsers are installed: `npx playwright install chromium`.

3. Run the smoke project first; it only checks that the app bundle mounts:

   ```bash
   npm run test:smoke
   ```

4. If smoke passes, run the full suite:

   ```bash
   npm run test:e2e
   ```

   To run one spec: `npx playwright test <path-to-spec> --project chromium`.

Report passed and failed specs. The `visual` project is opt-in and not gating; do not update snapshots unless asked.
