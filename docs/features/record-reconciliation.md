<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Record reconciliation

The same application or service is sometimes recorded twice, for example once by the supplier and once by an import. An administrator finds such pairs in OpenRegister and merges them there. Stackiq then moves everything that pointed at the duplicate to the record you keep, and takes the duplicate out of its lists.

Specification: [`openspec/specs/record-reconciliation/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/specs/record-reconciliation/spec.md).

## Finding duplicates

Nextcloud admins and members of the group `functioneel-beheerder` see **Find duplicates** at the top of **Applications** and **Services**. On an organisation's page, Nextcloud admins find the same action in the merge panel. Other users do not see it.

**Find duplicates** opens the **Duplicate candidates** page of OpenRegister. Pick the catalogue register and the schema (applications, services or organisations). The page lists pairs that look alike, with a score. Stackiq tells OpenRegister what counts as alike:

- Applications and services: a similar name, the same supplier and the same website.
- Organisations: the same contact in Nextcloud Contacts.

There you merge a pair or dismiss it as not a duplicate.

## What a merge changes in the catalogue

When OpenRegister merges a duplicate application or service into the record you keep, stackiq moves every reference to the duplicate within a few minutes (it runs as a background job):

- Applications: suites, services, vulnerabilities, usages (including a planned replacement), connections, reviews, compliance records and versions.
- Services: usages, contracts, connections, reviews and the applications that offer the service.

A list that named both records names the one you keep once. Every moved reference gets an audit entry with the merge operation.

The duplicate gets the record status **Merged** and a link to the record you keep. It leaves **Applications**, **Services** and their filter counts. An old link to its page still works and shows **Merged into** with a link to the record you keep.

Existing applications and services get the record status **Active** when the app is upgraded.

## Merging organisations

Merging organisations stays in stackiq's own merge panel on the organisation's page, see [Organisation merge](organisation-merge.md). It now also moves the organisation in usages, collaborations and architecture models.

## What a reversal does not restore yet

OpenRegister can reverse a merge within 30 days. The reversal restores the duplicate itself. It does not yet move the references stackiq moved back to the duplicate: after a reversal, usages and connections keep pointing at the record you kept. Moving them back is OpenRegister's part and follows later.

## Demo data

The demo data holds one pair: **Voorbeeld Name 1** and **Voorbeeld name 1**, from the same supplier with the same website. Import the demo data and open the duplicate candidates for applications to see it.
