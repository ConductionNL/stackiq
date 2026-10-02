<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Value assessment

An information manager scores each application the organisation uses on business value, technical fit and risk. The scores sit next to the cost stackiq already adds up from contracts, and they point to a TIME class. The TIME class the organisation records stays the decision: the scores back it up or question it, they never change it.

Specification: [`openspec/specs/application-value-assessment/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/specs/application-value-assessment/spec.md).

## Scoring an application

Open the application under **Applications in use** and edit it. Fill in:

- **Business value**: how much the organisation depends on it, from 1 (little) to 5 (critical).
- **Technical fit**: how well it fits the architecture and the standards the organisation follows, from 1 (poor) to 5 (good).
- **Risk**: the risk the organisation sees in running it, from 1 (low) to 5 (high).
- **Scored on**: the date you set the scores.

When you save, stackiq fills in **Suggested TIME classification**:

| Business value | Technical fit | Suggested class |
|---|---|---|
| 3 or more | 3 or more | Invest |
| 3 or more | below 3 | Migrate |
| below 3 | 3 or more | Tolerate |
| below 3 | below 3 | Eliminate |

While either score is missing there is no suggestion. The **Value assessment** section of the page shows the scores, the recorded TIME class and the suggestion side by side.

## Risk signals

Below the data of the page, **Risk signals** shows what backs a risk score: whether the version you run is past its end of support (or withdrawn), and how many known vulnerabilities are linked to the application. You set the risk score yourself; the signals only inform it.

## The portfolio report

The portfolio report (**Reports**, then **Portfolio rationalization**) adds, for the organisation you select:

- **Business value against technical fit**: one circle per scored application, its size the annualised cost. The circle's colour is the suggested class; a dark ring marks a recorded class that differs from the scores. Applications without both scores are counted below the chart.
- Two columns in the table: **Suggested by scores** and **Value / fit / risk**.
- The switch **Recorded class differs from scores**, which lists only the applications whose recorded class and suggestion disagree.

The CSV export carries the columns businessValue, technicalFit, riskScore, scoredOn, suggestedTimeClassification and timeMismatch.
