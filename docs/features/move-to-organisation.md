<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Move to organisation

Moves chosen entries from one organisation to another, for example when a
department becomes its own organisation or a task passes to a partner. The
entries keep their identity: the same records, with the same links and
history, now owned by the other organisation. Nothing is copied and nothing
is deleted.

Unlike an [organisation merge](organisation-merge.md), a move takes only the
entries you pick and leaves the source organisation as it is.

Specification: [`openspec/changes/landscape-move-between-organisations`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md).

## Who may move

Only an administrator of **both** organisations: a Nextcloud administrator,
or a member of both organisations who is in the `maintainer` group. Anyone
else is refused before anything is read, and the dialog says so.

## Moving entries

1. Make the organisation the entries belong to your active organisation.
2. Open one of the lists: **Applications**, **Services**, **Applications in
   use**, **Connections** or **Contracts**.
3. Select the entries and choose **Move to organisation** in the selection bar.
4. Pick the target organisation and choose **Preview**.
5. Check the preview, then choose **Move**.

## The preview

The preview runs the move without saving anything and lists:

| Section | What it shows |
|---------|---------------|
| **Moving** | The entries that will move. |
| **Skipped** | Entries that will not move, each with the reason: not owned by the source organisation, not found, a kind of entry that cannot move, or a target organisation that is unknown or the same as the source. |
| **Staying with** *source* | Linked entries that stay with the source organisation. For an application in use these are its connections. |

**Move** is only offered when at least one entry moves. After the move the
dialog reports how many entries moved.

## What changes on a moved entry

- Its owning organisation (`@self.organisation`) becomes the target.
- The field that names its organisation is re-pointed when it named the
  source: `provider` for applications, services and connections, `consumer`
  for applications in use.
- Every other field stays as it was.

At most 500 entries move in one go.
