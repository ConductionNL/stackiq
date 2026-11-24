<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Security classification (BIO baseline)

An organisation classifies each application it uses on the three BIO aspects:
**availability**, **integrity** and **confidentiality**, each at BBN1, BBN2 or
BBN3, with the main reason. Stackiq derives the overall **BBN level** as the
highest of the three on every save, and records when and by whom an aspect
last changed. The classification belongs to the organisation's own record of
the application (*Applications in use*), because the same product can rightly
be classified differently by two municipalities that put different data in it.

Specification: [`openspec/changes/archive/2026-11-24-security-baseline-classification/`](https://github.com/ConductionNL/stackiq/tree/development/openspec/changes/archive/2026-11-24-security-baseline-classification).

## Classifying an application

Open **Applications in use** and choose an application. The panel
**Security classification (BIO)** holds a level and a main reason per aspect
and shows the derived BBN level.

**Suggest from GEMMA** fills the three levels from GEMMA's scores of the
reference components the usage names, or of the application's reference
components when the usage names none: per aspect the highest score, 1 to 3
as BBN1 to BBN3. It saves nothing; the panel says how many components had a
score, and the classification is stored only when you choose **Save**.

The list **Applications in use** has a **BBN level** column and the quick
filters **BBN1**, **BBN2**, **BBN3** and **Not classified**.

## How other organisations classify an application

The application page shows **How organisations classify this application**:
how many organisations classified it and, once at least three have, per aspect
and overall how many organisations chose each level.

| Who | Sees |
| --- | --- |
| The organisation that uses the application | Its own classification, and can change it |
| Municipalities, collaborations, members of `ambtenaar`, administrators | The counts on the application page, never which organisation chose what |
| Suppliers | Neither: the classification of a usage is not readable for the product's supplier, and the summary answers them 403 |

Below three organisations the summary gives only the number of organisations,
so a single organisation's level cannot be read from it. The threshold is
fixed in the code, not a setting.

## Demo data

The demo data classifies **Voorbeeld Name 1** by three organisations; its
application page shows three organisations, BBN2 twice and BBN3 once overall.
