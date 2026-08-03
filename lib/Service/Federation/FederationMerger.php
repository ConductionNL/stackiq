<?php
/**
 * Federation merge + staleness logic.
 *
 * Pure, side-effect-poor reconciliation of a peer's published catalog entries
 * against the locally-stored mirrors of that same peer. Lives apart from
 * FederationService so the merge invariants (never touch locally-owned entries,
 * idempotent on a stable peer id, mark — never silently delete — staleness) are
 * unit-testable without a live OpenRegister/OpenCatalogi.
 *
 * The merger NEVER performs I/O itself: it is handed the peer's fetched entries
 * and the locally-stored mirrors, and returns a {create,update,withdraw} plan
 * that FederationService applies via the OpenRegister ObjectService abstraction
 * (ADR-022). Every mirror object it plans carries `_source` provenance and is
 * therefore treated as read-only by every local write path (isPeerSourced()).
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service\Federation
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\Federation;

/**
 * Reconciles a peer's published entries with the locally-stored peer mirrors.
 */
class FederationMerger
{
    /**
     * Default consecutive-failure threshold before peer mirrors are marked stale.
     */
    public const DEFAULT_STALE_AFTER_FAILURES = 3;

    /**
     * Build a create/update/withdraw plan from a peer pull.
     *
     * Invariants enforced here:
     *  - Locally-owned entries are NEVER in the plan (the caller passes only the
     *    mirrors of THIS peer as $localMirrors; the merger trusts that, and the
     *    {@see self::isOwnedByPeer()} guard double-checks each mirror's source).
     *  - Idempotent on the stable peer entry id: re-pulling an unchanged catalog
     *    yields an empty create/withdraw plan and only no-op-equal updates are
     *    filtered out.
     *  - Entries present in the peer catalog but missing locally are CREATEs;
     *    locally-mirrored entries no longer in the peer catalog are WITHDRAWs
     *    (marked withdrawn + stale, never silently deleted).
     *
     * @param string                         $peerUrl      The peer base URL (provenance instance).
     * @param string                         $peerOrg      The peer organisation name (provenance).
     * @param array<int,array<string,mixed>> $peerEntries  The peer's published entries (each MUST
     *                                                     carry a stable id under 'id' or
     *                                                     'uuid').
     * @param array<int,array<string,mixed>> $localMirrors The locally-stored mirrors of THIS peer.
     * @param string                         $syncedAt     The ISO-8601 sync moment.
     *
     * @return array{create:array<int,array<string,mixed>>, update:array<int,array<string,mixed>>, withdraw:array<int,array<string,mixed>>}
     *               The reconciliation plan. Each create/update item is a full
     *               mirror data bag (with `_source`); each withdraw item is the
     *               existing local mirror data bag, marked withdrawn + stale.
     *
     * @spec openspec/specs/federated-catalog-sync/spec.md
     *
     * Complexity is the merge decision table itself (new / updated / unchanged /
     * withdrawn / stale), which is only correct read as one pass.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function plan(
        string $peerUrl,
        string $peerOrg,
        array $peerEntries,
        array $localMirrors,
        string $syncedAt
    ): array {
        $create   = [];
        $update   = [];
        $withdraw = [];

        // Index the local mirrors of THIS peer by their stable peer-entry id.
        $localByPeerId = [];
        foreach ($localMirrors as $mirror) {
            if ($this->isOwnedByPeer(mirror: $mirror, peerUrl: $peerUrl) === false) {
                // Defensive: never reconcile an entry that is not this peer's mirror.
                continue;
            }

            $peerId = $this->peerEntryId(mirror: $mirror);
            if ($peerId !== null) {
                $localByPeerId[$peerId] = $mirror;
            }
        }

        $seenPeerIds = [];
        foreach ($peerEntries as $entry) {
            $peerId = $this->stableId(entry: $entry);
            if ($peerId === null) {
                // Skip entries without a stable id — cannot be merged idempotently.
                continue;
            }

            $seenPeerIds[$peerId] = true;

            $mirror = $this->buildMirror(
                entry: $entry,
                peerUrl: $peerUrl,
                peerOrg: $peerOrg,
                peerId: $peerId,
                syncedAt: $syncedAt
            );

            if (isset($localByPeerId[$peerId]) === false) {
                $create[] = $mirror;
                continue;
            }

            // Carry the existing local OR uuid so the caller updates in place.
            $existing = $localByPeerId[$peerId];
            if (isset($existing['id']) === true) {
                $mirror['id'] = $existing['id'];
            }

            if ($this->isUnchanged(existing: $existing, fresh: $mirror) === false) {
                $update[] = $mirror;
            }
        }//end foreach

        // Local mirrors of this peer that the peer no longer publishes → withdraw.
        foreach ($localByPeerId as $peerId => $mirror) {
            if (isset($seenPeerIds[$peerId]) === true) {
                continue;
            }

            $withdraw[] = $this->markWithdrawn(mirror: $mirror, syncedAt: $syncedAt);
        }

        return ['create' => $create, 'update' => $update, 'withdraw' => $withdraw];
    }//end plan()

    /**
     * Decide whether a peer should be marked stale given its failure streak.
     *
     * A peer whose consecutive failed-pull count reaches the threshold has its
     * merged entries marked stale (`_source.stale: true`) — surfaced, never
     * deleted. A successful pull (failure count 0) clears staleness.
     *
     * @param int      $consecutiveFailures The peer's consecutive failed-pull count.
     * @param int|null $threshold           Optional override (default 3).
     *
     * @return bool True when the peer's mirrors should be marked stale.
     *
     * @spec openspec/specs/federated-catalog-sync/spec.md
     *
     * The else branch is a genuine either/or between two disjoint states.
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function isStale(int $consecutiveFailures, ?int $threshold=null): bool
    {
        if ($threshold !== null && $threshold > 0) {
            $limit = $threshold;
        } else {
            $limit = self::DEFAULT_STALE_AFTER_FAILURES;
        }

        return $consecutiveFailures >= $limit;
    }//end isStale()

    /**
     * Apply a stale flag to a local mirror data bag (idempotent).
     *
     * @param array<string,mixed> $mirror The local mirror data bag.
     * @param bool                $stale  Whether the mirror is stale.
     *
     * @return array<string,mixed> The mirror with `_source.stale` set.
     *
     * @spec openspec/specs/federated-catalog-sync/spec.md
     *
     * The else branch is a genuine either/or between two disjoint states.
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function applyStale(array $mirror, bool $stale): array
    {
        if (is_array($mirror['_source'] ?? null) === true) {
            $source = $mirror['_source'];
        } else {
            $source = [];
        }

        $source['stale']   = $stale;
        $mirror['_source'] = $source;
        return $mirror;
    }//end applyStale()

    /**
     * Whether a stored mirror belongs to the given peer (provenance match).
     *
     * @param array<string,mixed> $mirror  The mirror data bag.
     * @param string              $peerUrl The peer base URL.
     *
     * @return bool True when the mirror's `_source.instance` is this peer.
     */
    private function isOwnedByPeer(array $mirror, string $peerUrl): bool
    {
        $source = $mirror['_source'] ?? null;
        if (is_array($source) === false) {
            return false;
        }

        return (string) ($source['instance'] ?? '') === $peerUrl;
    }//end isOwnedByPeer()

    /**
     * Build the mirror data bag for a peer entry, stamping provenance.
     *
     * @param array<string,mixed> $entry    The peer's published entry.
     * @param string              $peerUrl  The peer base URL.
     * @param string              $peerOrg  The peer organisation name.
     * @param string              $peerId   The stable peer-entry id.
     * @param string              $syncedAt The ISO-8601 sync moment.
     *
     * @return array<string,mixed> The mirror data bag with `_source` provenance.
     */
    private function buildMirror(
        array $entry,
        string $peerUrl,
        string $peerOrg,
        string $peerId,
        string $syncedAt
    ): array {
        // Strip any inbound provenance/control keys so a peer cannot spoof its source.
        unset($entry['_source'], $entry['id'], $entry['uuid']);

        $entry['_source'] = [
            'instance'     => $peerUrl,
            'organisation' => $peerOrg,
            'peerEntryId'  => $peerId,
            'syncedAt'     => $syncedAt,
            'stale'        => false,
            'withdrawn'    => false,
        ];

        return $entry;
    }//end buildMirror()

    /**
     * Mark a local mirror withdrawn + stale (peer no longer publishes it).
     *
     * @param array<string,mixed> $mirror   The existing local mirror.
     * @param string              $syncedAt The ISO-8601 sync moment.
     *
     * @return array<string,mixed> The mirror marked withdrawn + stale.
     *
     * The else branch is a genuine either/or between two disjoint states.
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    private function markWithdrawn(array $mirror, string $syncedAt): array
    {
        if (is_array($mirror['_source'] ?? null) === true) {
            $source = $mirror['_source'];
        } else {
            $source = [];
        }

        $source['withdrawn']   = true;
        $source['stale']       = true;
        $source['withdrawnAt'] = $syncedAt;
        $mirror['_source']     = $source;
        return $mirror;
    }//end markWithdrawn()

    /**
     * The stable peer-entry id from a freshly-fetched peer entry.
     *
     * @param array<string,mixed> $entry The peer entry.
     *
     * @return string|null The stable id, or null when absent.
     */
    private function stableId(array $entry): ?string
    {
        foreach (['id', 'uuid'] as $key) {
            $value = $entry[$key] ?? null;
            if (is_string($value) === true && trim($value) !== '') {
                return $value;
            }

            if (is_int($value) === true) {
                return (string) $value;
            }
        }

        return null;
    }//end stableId()

    /**
     * The stable peer-entry id recorded on a stored mirror's provenance.
     *
     * @param array<string,mixed> $mirror The local mirror.
     *
     * @return string|null The recorded peer-entry id, or null.
     */
    private function peerEntryId(array $mirror): ?string
    {
        $source = $mirror['_source'] ?? null;
        if (is_array($source) === false) {
            return null;
        }

        $value = $source['peerEntryId'] ?? null;
        if (is_string($value) === true && trim($value) !== '') {
            return $value;
        }

        return null;
    }//end peerEntryId()

    /**
     * Whether the freshly-built mirror equals the stored one (ignoring the
     * volatile `syncedAt` timestamp) — so an unchanged re-pull is a no-op.
     *
     * @param array<string,mixed> $existing The stored mirror.
     * @param array<string,mixed> $fresh    The freshly-built mirror.
     *
     * @return bool True when the payloads are equal apart from syncedAt.
     */
    private function isUnchanged(array $existing, array $fresh): bool
    {
        return $this->normalise(mirror: $existing) === $this->normalise(mirror: $fresh);
    }//end isUnchanged()

    /**
     * Normalise a mirror for equality comparison: drop the OR-assigned id and
     * the volatile `_source.syncedAt`, then sort keys recursively.
     *
     * @param array<string,mixed> $mirror The mirror data bag.
     *
     * @return array<string,mixed> The normalised comparison shape.
     */
    private function normalise(array $mirror): array
    {
        unset($mirror['id']);
        if (is_array($mirror['_source'] ?? null) === true) {
            unset($mirror['_source']['syncedAt'], $mirror['_source']['withdrawnAt']);
        }

        return $this->ksortRecursive(value: $mirror);
    }//end normalise()

    /**
     * Recursively key-sort an array for order-insensitive comparison.
     *
     * @param array<string,mixed> $value The array to sort.
     *
     * @return array<string,mixed> The recursively key-sorted array.
     */
    private function ksortRecursive(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item) === true) {
                $value[$key] = $this->ksortRecursive(value: $item);
            }
        }

        return $value;
    }//end ksortRecursive()
}//end class
