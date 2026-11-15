---
kind: code
depends_on: []
---

# The federation pull fetches the peer, and mirrored entries show where they came from

## Summary

`FederationService::pullPeer()` is meant to mirror a peer catalogue's published entries. Its fetch (`fetchPeerCatalog()`, `lib/Service/Federation/FederationService.php:428`) calls OpenCatalogi's `DirectoryService::getDirectory(['url' => $peerUrl])`, and OpenCatalogi ignores that parameter, so what gets mirrored is the local directory listing, not the peer. Mirrors are stamped with `_source.instance`, but no page shows it. Board SkInstellingenUitwisseling draws the peers table (catalogue, address, last pulled, objects, an error state "Fout: niet bereikbaar", Nu ophalen) and the provenance line "Uit: Softwarecatalogus Drechtsteden". The board is the canon (decision 130), so the row moves from built to building with this plan.

## Rows

- `share-federation-pull` "Pull published entries from peer catalogues, marked with where they came from."

## What changes

- The pull fetches the peer's public published listing over HTTP through Nextcloud's `IClientService`, after the existing SSRF guard, page by page with a cap, and hands those entries to the existing `FederationMerger::plan()`.
- The peers table shows last pulled, object count and the last error per peer.
- A mirrored entry shows "From: <peer catalogue>" with a link on its detail page and a badge on the list, and stays read only.

## Screens

See `screens.md`: SkInstellingenUitwisseling, SkApplicatie.
