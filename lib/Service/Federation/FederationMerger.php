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
 * @package   OCA\Stackiq\Service\Federation
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Federation;

/**
 * Reconciles a peer's published entries with the locally-stored peer mirrors.
 */
class FederationMerger {
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
	 * @param array{url: string, organisation: string, syncedAt: string} $peer The peer: its base URL
	 *                                                                     (provenance instance), its
	 *                                                                     organisation name (provenance)
	 *                                                                     and the ISO-8601 sync moment.
	 * @param array<int,array<string,mixed>> $peerEntries The peer's published entries (each MUST
	 *                                                    carry a stable id under 'id' or
	 *                                                    'uuid').
	 * @param array<int,array<string,mixed>> $localMirrors The locally-stored mirrors of THIS peer.
	 *
	 * The reconciliation plan. Each create/update item is a full mirror data bag
	 * (with `_source`); each withdraw item is the existing local mirror data bag,
	 * marked withdrawn + stale.
	 *
	 * @return array{create:array<int,array<string,mixed>>,
	 *               update:array<int,array<string,mixed>>,
	 *               withdraw:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function plan(array $peer, array $peerEntries, array $localMirrors): array {
		$run = new FederationPlanRun(
			peer: $peer,
			localByPeerId: $this->indexLocalMirrors(localMirrors: $localMirrors, peerUrl: $peer['url'])
		);

		foreach ($peerEntries as $entry) {
			$this->reconcileEntry(run: $run, entry: $entry);
		}

		// Local mirrors of this peer that the peer no longer publishes → withdraw.
		foreach ($run->localByPeerId as $peerId => $mirror) {
			if (isset($run->seenPeerIds[$peerId]) === false) {
				$run->withdraw[] = $this->markWithdrawn(mirror: $mirror, syncedAt: $peer['syncedAt']);
			}
		}

		return $run->plan();
	}//end plan()

	/**
	 * Index the local mirrors of THIS peer by their stable peer-entry id.
	 *
	 * @param array<int,array<string,mixed>> $localMirrors The locally-stored mirrors of THIS peer.
	 * @param string                         $peerUrl      The peer base URL.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function indexLocalMirrors(array $localMirrors, string $peerUrl): array {
		$localByPeerId = [];
		foreach ($localMirrors as $mirror) {
			// Defensive: never reconcile an entry that is not this peer's mirror.
			$peerId = null;
			if ($this->isOwnedByPeer(mirror: $mirror, peerUrl: $peerUrl) === true) {
				$peerId = $this->peerEntryId(mirror: $mirror);
			}

			if ($peerId !== null) {
				$localByPeerId[$peerId] = $mirror;
			}
		}

		return $localByPeerId;
	}//end indexLocalMirrors()

	/**
	 * Plan one peer entry: a create when it has no local mirror, an update
	 * when its mirror changed; entries without a stable id are skipped.
	 *
	 * @param FederationPlanRun   $run   The plan being built.
	 * @param array<string,mixed> $entry The peer's published entry.
	 *
	 * @return void
	 */
	private function reconcileEntry(FederationPlanRun $run, array $entry): void {
		$peerId = $this->stableId(entry: $entry);
		if ($peerId === null) {
			// Skip entries without a stable id — cannot be merged idempotently.
			return;
		}

		$run->seenPeerIds[$peerId] = true;
		$this->planMirror(run: $run, mirror: $this->buildMirror(entry: $entry, source: $run->provenance(peerId: $peerId)));
	}//end reconcileEntry()

	/**
	 * Plan a freshly-built mirror: a create when its peer entry has no local
	 * mirror yet, an update when the local mirror differs.
	 *
	 * @param FederationPlanRun   $run    The plan being built.
	 * @param array<string,mixed> $mirror The freshly-built mirror, stamped with its peer-entry id.
	 *
	 * @return void
	 */
	private function planMirror(FederationPlanRun $run, array $mirror): void {
		$peerId = $mirror['_source']['peerEntryId'];
		if (isset($run->localByPeerId[$peerId]) === false) {
			$run->create[] = $mirror;
			return;
		}

		$existing = $run->localByPeerId[$peerId];
		$mirror   = $this->withLocalId(mirror: $mirror, existing: $existing);
		if ($this->isUnchanged(existing: $existing, fresh: $mirror) === false) {
			$run->update[] = $mirror;
		}
	}//end planMirror()

	/**
	 * Carry the existing local OR uuid so the caller updates in place.
	 *
	 * @param array<string,mixed> $mirror   The freshly-built mirror.
	 * @param array<string,mixed> $existing The stored mirror.
	 *
	 * @return array<string,mixed>
	 */
	private function withLocalId(array $mirror, array $existing): array {
		if (isset($existing['id']) === true) {
			$mirror['id'] = $existing['id'];
		}

		return $mirror;
	}//end withLocalId()

	/**
	 * Decide whether a peer should be marked stale given its failure streak.
	 *
	 * A peer whose consecutive failed-pull count reaches the threshold has its
	 * merged entries marked stale (`_source.stale: true`) — surfaced, never
	 * deleted. A successful pull (failure count 0) clears staleness.
	 *
	 * @param int $consecutiveFailures The peer's consecutive failed-pull count.
	 * @param int|null $threshold Optional override (default 3).
	 *
	 * @return bool True when the peer's mirrors should be marked stale.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function isStale(int $consecutiveFailures, ?int $threshold = null): bool {
		$limit = self::DEFAULT_STALE_AFTER_FAILURES;
		if ($threshold !== null && $threshold > 0) {
			$limit = $threshold;
		}

		return $consecutiveFailures >= $limit;
	}//end isStale()

	/**
	 * Apply a stale flag to a local mirror data bag (idempotent).
	 *
	 * @param array<string,mixed> $mirror The local mirror data bag.
	 * @param bool $stale Whether the mirror is stale.
	 *
	 * @return array<string,mixed> The mirror with `_source.stale` set.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function applyStale(array $mirror, bool $stale): array {
		$source = [];
		if (is_array($mirror['_source'] ?? null) === true) {
			$source = $mirror['_source'];
		}

		$source['stale'] = $stale;
		$mirror['_source'] = $source;
		return $mirror;
	}//end applyStale()

	/**
	 * Whether a stored mirror belongs to the given peer (provenance match).
	 *
	 * @param array<string,mixed> $mirror The mirror data bag.
	 * @param string $peerUrl The peer base URL.
	 *
	 * @return bool True when the mirror's `_source.instance` is this peer.
	 */
	private function isOwnedByPeer(array $mirror, string $peerUrl): bool {
		$source = $mirror['_source'] ?? null;
		if (is_array($source) === false) {
			return false;
		}

		return (string)($source['instance'] ?? '') === $peerUrl;
	}//end isOwnedByPeer()

	/**
	 * Build the mirror data bag for a peer entry, stamping provenance.
	 *
	 * @param array<string,mixed> $entry  The peer's published entry.
	 * @param array<string,mixed> $source The `_source` provenance to stamp.
	 *
	 * @return array<string,mixed> The mirror data bag with `_source` provenance.
	 */
	private function buildMirror(array $entry, array $source): array {
		// Strip any inbound provenance/control keys so a peer cannot spoof its source.
		unset($entry['_source'], $entry['id'], $entry['uuid']);

		$entry['_source'] = $source;

		return $entry;
	}//end buildMirror()

	/**
	 * Mark a local mirror withdrawn + stale (peer no longer publishes it).
	 *
	 * @param array<string,mixed> $mirror The existing local mirror.
	 * @param string $syncedAt The ISO-8601 sync moment.
	 *
	 * @return array<string,mixed> The mirror marked withdrawn + stale.
	 */
	private function markWithdrawn(array $mirror, string $syncedAt): array {
		$source = [];
		if (is_array($mirror['_source'] ?? null) === true) {
			$source = $mirror['_source'];
		}

		$source['withdrawn'] = true;
		$source['stale'] = true;
		$source['withdrawnAt'] = $syncedAt;
		$mirror['_source'] = $source;
		return $mirror;
	}//end markWithdrawn()

	/**
	 * The stable peer-entry id from a freshly-fetched peer entry.
	 *
	 * @param array<string,mixed> $entry The peer entry.
	 *
	 * @return string|null The stable id, or null when absent.
	 */
	private function stableId(array $entry): ?string {
		foreach (['id', 'uuid'] as $key) {
			$value = $this->stableIdValue(value: $entry[$key] ?? null);
			if ($value !== null) {
				return $value;
			}
		}

		return null;
	}//end stableId()

	/**
	 * A usable stable id: a non-blank string as is, an integer as a string.
	 *
	 * @param mixed $value The candidate id.
	 *
	 * @return string|null The stable id, or null when the value is not one.
	 */
	private function stableIdValue(mixed $value): ?string {
		if (is_string($value) === true && trim($value) !== '') {
			return $value;
		}

		if (is_int($value) === true) {
			return (string)$value;
		}

		return null;
	}//end stableIdValue()

	/**
	 * The stable peer-entry id recorded on a stored mirror's provenance.
	 *
	 * @param array<string,mixed> $mirror The local mirror.
	 *
	 * @return string|null The recorded peer-entry id, or null.
	 */
	private function peerEntryId(array $mirror): ?string {
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
	 * @param array<string,mixed> $fresh The freshly-built mirror.
	 *
	 * @return bool True when the payloads are equal apart from syncedAt.
	 */
	private function isUnchanged(array $existing, array $fresh): bool {
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
	private function normalise(array $mirror): array {
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
	private function ksortRecursive(array $value): array {
		ksort($value);
		foreach ($value as $key => $item) {
			if (is_array($item) === true) {
				$value[$key] = $this->ksortRecursive(value: $item);
			}
		}

		return $value;
	}//end ksortRecursive()
}//end class
