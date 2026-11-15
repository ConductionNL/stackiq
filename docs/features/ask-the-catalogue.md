<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Ask the catalogue

Ask a question about the catalogue in plain language and get an answer that names the catalogue entries it is based on.

Specification: [`openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md).

## Where

**Reports & Compliance > Ask the catalogue**. The page is offered only when the Hermiq assistant app is installed. Without Hermiq the menu entry is hidden, and a direct visit says the assistant is not available.

## What is searched

When you ask a question, stackiq first searches the catalogue in OpenRegister, **as you**: only the entries you may see are found. It searches these kinds of entries:

- applications (modules)
- services
- organisations
- usages
- connections
- compliance records
- contracts

At most 20 entries are used for one question. When nothing matches, the page says so and the assistant is not asked at all.

## What is sent to the assistant

Only the entries the search found are sent to Hermiq, together with your question. For each entry that is its kind, its title, a short summary with the fields shown on its list page, and its link in stackiq. Nothing else from the catalogue is sent. Stackiq asks the assistant to cite the entries it used.

## The answer

- **The reply** of the assistant.
- **Sources**: the entries the reply cites, each linked to its page.
- **Entries searched**: folded away, every entry that was sent, so you can check what the answer could have used.

You can ask a follow-up question; it continues the same conversation. A question is at most 2000 characters, and asking very many questions in a short time is slowed down.
