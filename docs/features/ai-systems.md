<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# AI systems

An AI system is an AI agent, an AI model or an AI feature that your organisation uses. You register it next to the application it runs in, classify it under the EU AI Act, and keep the documents the act asks for.

Specification: [`openspec/specs/ai-system-inventory/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/specs/ai-system-inventory/spec.md).

## Registering an AI system

Open **Applications** in the navigation menu, then **AI systems**, and click **Add**. Fill in:

- **Name** and **Description**.
- **Kind**: an AI agent acts on its own, an AI model is a trained model, an AI feature is part of an application.
- **Application**: the application it runs in or supports.
- **Supplier** and **Purpose**: who supplies it and what it decides, recommends or produces.

The page of the application shows its AI systems in the **AI systems** section.

## Classifying it under the AI Act

Each AI system records:

- **AI Act risk category**: prohibited, high risk, limited risk, minimal risk, or not yet assessed. A new system starts as not yet assessed.
- **Role under the AI Act**: provider or deployer.
- **Last assessed on** and the **Algorithm register entry**, the link to the system in the Dutch algorithm register.

The category is your organisation's own classification. Stackiq records it; it does not decide it.

## Evidence

Attach documents to the AI system under **Documents** and tag each one: FRIA (fundamental rights impact assessment), Technical documentation, Human oversight or Logging. The **AI Act evidence** panel on the page lists the four tags and shows which have a document.

Fill in **Fundamental rights impact assessment** with a reference to the FRIA. A high-risk AI system without one:

- reads **FRIA missing** in the list,
- shows a warning on its page,
- and appears under the **High risk without FRIA** filter above the list.

The other filters above the list select one risk category each.

Screenshots follow once the feature runs on the demo instance.
