<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Service desk exchange

Stackiq is your CMDB for applications. It keeps the applications you use, their connections, licences and contracts in step with TOPdesk or ServiceNow, in both directions. Stackiq never holds the service desk password: integriq does.

Specification: [`openspec/changes/sharing-itsm-exchange`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/sharing-itsm-exchange/).

## Who owns which field

Each field has one owner, written in integriq's mapping. The owner's value wins. A timestamp never decides.

| Field | Owner |
|---|---|
| Name, supplier, installed version, status, the record id and link | The service desk |
| BBN level, TIME class, publication | Stackiq |
| Licence and contract fields: number, supplier reference, type, start, end, cost, currency, metric, licences bought | Stackiq |
| Relations between applications | The service desk |

The import only writes fields the service desk owns on a record stackiq already has. The export only sends fields stackiq owns on a record the service desk already has. A new record gets every field, from whichever side creates it.

A licence or contract the service desk knows and stackiq does not is created with all its fields. After that stackiq owns its licence and contract fields.

## Set it up

1. In integriq, open the TOPdesk or ServiceNow source. Fill in your tenant address, the login name, and the password as a credential.
2. In the admin settings, open **Service desk exchange**.
3. Choose the service desk and your organisation. For TOPdesk, also enter the id of your Application asset template: TOPdesk needs it to create an asset.
4. Choose **Set up the exchange**.

Stackiq checks every flow with OpenRegister before it saves any. If one is refused, nothing is created and the message names the step and the reason. Setting up again updates the same flows.

The flows show on stackiq's **Flows** page. The imports run every night at 02:00. A change to a stackiq-owned field goes to the service desk within a minute.

## What the import does

- It reads applications, relations, licences and contracts from the service desk.
- It matches an application on its service desk record first, then on name and supplier.
- It creates the supplier, the application and your organisation's use of it when there is no match.
- A second run updates. It never adds a second copy.

A near-duplicate, such as a spelling variant of an application that already exists, is created as a new application. It then shows up on OpenRegister's duplicate candidates page, where a steward merges the two.

## Why nothing echoes back

Each direction only writes its own fields, and each skips a record whose own fields did not change. So an import never triggers an export call, and an export never comes back as an import write.

## Import a file

No service desk API? On the **CMDB** page, import a CSV or XLSX file. Use these columns: `recordId`, `name`, `supplierName`, `installedVersion`, `status`. Every row needs a `recordId`. Importing the same file again updates the applications instead of adding them twice.

## Next

Open the **CMDB** page under Applications to see what stackiq records, and connect your service desk from there.
