<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Business processes

A business process describes how your organisation does a piece of work, step by step. For each step you record which applications in use support it, how risky it is and whether it passed its compliance check. The process page shows the steps as a flow, and the page of an application in use lists the process steps it supports.

Specification: [`openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md).

## Recording a process

Open **Applications** in the navigation menu, then **Business processes**, and click **Add**. Fill in:

- **Name** and **Description**.
- **Process owner**: the person in your organisation responsible for the process.
- **GEMMA reference process**: the GEMMA business process this one follows, when you imported GEMMA.
- **Tags**, to group processes.

A new process starts as a **draft**. On the process page, **Activate** takes it into use, **Retire** takes it out of use and **Reopen** turns a retired process back into a draft.

## Adding the steps

On the process page, the **Steps** section lists the steps in order. Click **Add step** and fill in:

- **Name**, **Description** and **Step type**: a task is work, an event starts or ends something, a decision chooses a way.
- **Position**: the order of the step, starting at 1.
- **Follows**: the steps this step follows. Leave it empty and the step follows the step before it by position. Name two steps when the process joins two paths.
- **Supporting applications**: the applications in use that support the step.
- **Risk level** (not assessed, low, medium or high) with a **Risk note**.
- **Compliance check** (not checked, compliant, not compliant or not applicable) with a **Compliance note** and the date it was **Checked on**.

## The step flow

Below the steps, the **Step flow** shows the steps as a flow from left to right, with the supporting applications on each step. A step with a high risk or a failed compliance check has a red border and says **Needs attention**, so the colour is never the only signal. The flow is read-only; you change it through the steps.

## From the application's side

The page of an application in use has a section **Process steps this application supports**, with the process, the step, its risk level and its compliance check. On a step's page, **Supporting applications** lists the applications that support it. When a linked application is not visible to you, the page says how many are hidden instead of leaving them out silently.
