---
kind: code
depends_on: []
---

# Publish, withdraw and lock catalogue entries from the list and the entry's page

## Summary

stackiq has a guarded publish and withdraw endpoint (`PUT /api/publication/{objectType}/{uuid}/publish`, `DELETE .../depublish`, `lib/Controller/PublicationController.php`), and dialogs for mass publish and mass lock (`src/modals/object/MassPublishObjects.vue`, `MassLockObjects.vue`). No page calls the endpoint, and the dialogs hang off a view modal no page opens. Today the only way to publish is typing a publication date into the generic edit form, and the only bulk action on the Applications list is delete. Board SkApplicaties draws a selection bar with Publiceren, Intrekken, Vergrendelen and Verwijderen. The board is the canon (decision 130), so this change wires those actions to the existing endpoint and removes the dead modal path. The rows move from built to building.

## Rows

- `land-bulk-edit` "Select many catalogue entries at once and publish, lock or delete them together." Built: bulk delete on the index. Missing: bulk publish, withdraw and lock.
- `share-publish` "Publish or withdraw a single catalogue entry as open data." Built: the endpoint. Missing: a page action.

The other actions in the same bar (Kopiëren, Verplaatsen naar werkruimte, Type wijzigen) belong to `land-copy-entry`, `landscape-move-between-organisations` and `landscape-change-entry-type`.

## Screens

See `screens.md`: SkApplicaties, SkApplicatie.
