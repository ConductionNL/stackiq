---
kind: config
depends_on:
  - landscape-usage-registration
---

# Register the AI systems you use and classify them under the AI Act

## Summary

An information manager registers the AI agents, AI models and AI features the organisation uses, links each to the application it runs in, and records its EU AI Act risk category, the organisation's role under the act and the documents the act asks for. The application page shows its AI systems, and an AI systems list filters on risk category, so a privacy officer sees which high-risk systems still lack their assessment.

## Why

Rows from the stackiq matrix:

- `stackiq:land-ai-agent-inventory`, "Register the AI agents and AI models the organisation uses and link them to the applications and processes they support." Rated no. SAP LeanIX rates yes: https://help.sap.com/docs/leanix/ea/application-modeling-guidelines (AI agent is an application subtype, AI model an IT component subtype), with the changelog row https://updates.leanix.net/announcements/discover-verify-and-govern-ai-assets-with-sap-ai-agent-hub. Core area (landscape).
- `stackiq:comp-ai-act-classification`, "Classify the AI systems in the landscape by EU AI Act risk category and keep the evidence the act requires." Rated no, no competitor yes. Roadmap demand: https://roadmap.leanix.net/c/812-meta-model-eu-ai-act-extension. It rides with `land-ai-agent-inventory`: its whole capability is a risk category and evidence on the AI system record that change adds.

## What stackiq has today

- No schema for AI agents, models or systems; the register's catalogue schemas are listed at `lib/Settings/softwarecatalogus_register.json:817` onwards.
- `module.type` distinguishes Application from System software only.
- Evidence documents already hang on records through Nextcloud files (`allowFiles`, for example `usage` with tags DPIA, Contract, Verwerkingsovereenkomst), and `module.dpiaDocumentRef` links a DPIA.

## What this change builds

1. A schema `aiSystem`: name, description, kind (AI agent, AI model, AI feature), the application it runs in or supports, the supplier, the purpose, the EU AI Act risk category (prohibited, high risk, limited risk, minimal risk, not yet assessed), the organisation's role (provider or deployer), a link to the entry in the Dutch algorithm register, the date of the last assessment, and a status.
2. File tags on `aiSystem` for the documents the act asks of a deployer of a high-risk system: fundamental rights impact assessment, technical documentation from the provider, human oversight procedure, logging arrangement.
3. An AI systems list with filters on kind and risk category, and an AI systems section on the application page.
4. A warning on a high-risk AI system that has no fundamental rights impact assessment, and a quick filter that lists them.

## Out of scope

- Discovering AI systems automatically. The matrix category says stackiq is not a discovery agent.
- Publishing to the Dutch algorithm register (algoritmes.overheid.nl). Stackiq stores the link; exchange with that register is integriq's.
- Linking AI systems to business processes: `architecture-process-mapping` adds processes; a relation from `aiSystem` follows once that schema exists.
- Legal advice on the category. The field records the organisation's own classification.

## Risks

- The AI Act's categories and deployer duties may be refined by guidance. The enum and the file tags live in the fragment and change with a pull request.
