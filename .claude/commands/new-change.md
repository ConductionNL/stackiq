---
description: Start a new OpenSpec change for Stackiq
---

Start a new OpenSpec change named `$ARGUMENTS` (kebab-case, describing what it does, for example `contracts-expiry-and-owner`).

1. Read `openspec/config.yaml` (project context and per-artifact rules) and look at one or two existing changes in `openspec/changes/` for the expected shape, for example `openspec/changes/landscape-completeness-score/`.
2. Check `openspec/specs/` for an existing capability spec that the change extends, and `openspec/changes/` for an open change that already covers it.
3. Create `openspec/changes/<name>/` with:
   - `proposal.md`: front matter (`kind`, optional `depends_on`), then Summary, Why, what the app has today, and what the change builds.
   - `design.md`: the decisions, each with the alternatives considered and why they were rejected.
   - `specs/<capability>/spec.md`: delta requirements with GIVEN/WHEN/THEN scenarios.
   - `tasks.md`: numbered tasks, each with `spec_ref`, `files`, `acceptance_criteria` and Implement/Test checkboxes.

   Templates for each artifact are in `openspec/schemas/conduction/templates/`.
4. Do not write code yet. Show the proposal and ask for review.

When the change has shipped, it is moved to `openspec/changes/archive/<date>-<name>/` and its requirements are merged into `openspec/specs/`.
