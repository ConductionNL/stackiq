---
kind: code
depends_on: []
---

# Participants see the usages and connections a cooperation shares with them

## Summary

A cooperation of municipalities (for example Samenwerking Drechtsteden-Zuid) runs applications and connections for its members. The owning organisation can name participants on a usage (`usage.participants`, on the Applications in use page), but a participant cannot see that usage or its connections in stackiq: the usage read rules only match `_organisation` and `consumer`, and only `GET /api/gebruik/deelnemer` and the view enrichment answer for participants, with no page calling them. Boards SkInGebruik ("31 gedeeld via een samenwerking", column Gedeeld), SkGebruikDetail (Deelnemers card), SkKoppelingen (column "Gedeeld met") and SkOrganisaties (a cooperation with its participant count) draw the participant's view. The board is the canon (decision 130), so the two rows move from built to building with this plan.

## Rows

- `conn-shared-with-others` "See which connections you run together with other organisations." Evidence: participants can be named, but the usage read rules ignore them and only the API endpoint answers.
- `org-cooperation` "Register a cooperation of organisations and the landscape they share." Evidence: a cooperation and its participants can be registered and usages recorded with participants, but no page shows a participant the shared landscape; the deelnemers API has no caller.

## What changes

- Read rules: a usage manager or reader of an organisation that is listed in `usage.participants` may read that usage, and the connections it lists in `usage.koppelingen`. Read only: update and delete stay with the owner.
- Applications in use: shared usages appear in the list with a Gedeeld column (cooperation and participant count) and a quick filter, and the header counts them.
- Usage detail: the Deelnemers card names the cooperation, the owner and each participant with the date they joined; a participant sees it read only.
- Connections: a "Gedeeld met" column listing the participant organisations of the usages that hold the connection.
- Organisations: a cooperation row shows its number of participants.

## Out of scope

- The GEMMA plate layer "Gedeeld met partners" (`architecture-shared-usage-on-views`).
- Peer counts across organisations (`gemma-plate-views-peers-and-definitions`).

## Screens

See `screens.md`.
