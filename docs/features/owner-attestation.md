<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Confirmation rounds

An information manager asks the owners of the organisation's entries to confirm that each entry is still right, or to correct it, by a deadline. The round shows who answered, who corrected and who is overdue, and every confirmed entry counts as fresh again in its data quality score.

Specification: [`openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md).

## Starting a round

Open **Organisations → Confirmation rounds** and choose **Start round**. Pick:

- the organisation,
- which entries are asked about: its **applications in use**, or its **products** as a supplier,
- the deadline, and optionally a name, for example the quarter the round covers.

Every entry gets one request per owner:

- an application in use is asked of its business owner and its technical owner,
- a product is asked of its contact person.

An owner gets a request only when they have a Nextcloud account. Entries without such an owner are listed when the round starts, under **Entries without an owner**, so an owner can be assigned first and a new round started for them.

Only an administrator, or a member of the `maintainer` group who belongs to the organisation, may start a round. Anyone else gets a refusal and nothing is created.

## Notifications

When a request is created its owner gets a Nextcloud notification and an email naming the entry. Three days before the deadline an owner whose request is still pending gets a reminder.

## Answering

The owner opens **Organisations → My confirmation requests**. It lists their pending requests with the entry, its type and the deadline.

- **Confirm**: the entry is right. Its *last confirmed* date becomes now, and the request reads *confirmed*.
- **Open entry**: opens the application in use or the product. When the owner changes it and saves, their request for it reads *corrected* and the entry's *last confirmed* date becomes now as well.

Only the owner a request was sent to can answer it. When a colleague edits the entry, the owner's request stays pending. A request that is already answered cannot be confirmed again.

## Following a round

Every night a pending request whose deadline has passed becomes *overdue*. An overdue request can still be confirmed or corrected.

The round's page shows four counts, **Pending**, **Confirmed**, **Corrected** and **Overdue**, the round's data, and the requests per status with their owner, so the overdue owners can be followed up.

## Endpoints

| Method | Path | What |
| --- | --- | --- |
| `POST` | `/api/attestation-rounds` | Start a round (`organisation`, `scopeSchema` `usage` or `module`, `deadline`, `name`) |
| `POST` | `/api/attestation-requests/{id}/confirm` | Confirm a request, as its owner |
