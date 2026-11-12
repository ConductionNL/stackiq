<?php

/**
 * Federation plan run.
 *
 * The state one FederationMerger::plan() call shares between its steps: the
 * peer's provenance, the indexed local mirrors, the peer ids seen in the pull
 * and the plan being built.
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
 * One reconciliation of a peer pull against the local mirrors of that peer.
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md
 */
final class FederationPlanRun {
	/**
	 * Peer ids present in the pull.
	 *
	 * @var array<string, true>
	 */
	public array $seenPeerIds = [];

	/**
	 * Mirrors to create.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $create = [];

	/**
	 * Mirrors to update.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $update = [];

	/**
	 * Mirrors to withdraw.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $withdraw = [];

	/**
	 * Constructor.
	 *
	 * @param array{url: string, organisation: string, syncedAt: string} $peer          The peer.
	 * @param array<string, array<string, mixed>>                        $localByPeerId The local mirrors of this peer by peer-entry id.
	 */
	public function __construct(
		public readonly array $peer,
		public readonly array $localByPeerId,
	) {
	}//end __construct()

	/**
	 * The `_source` provenance a mirror of the given peer entry carries.
	 *
	 * @param string $peerId The stable peer-entry id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function provenance(string $peerId): array {
		return [
			'instance' => $this->peer['url'],
			'organisation' => $this->peer['organisation'],
			'peerEntryId' => $peerId,
			'syncedAt' => $this->peer['syncedAt'],
			'stale' => false,
			'withdrawn' => false,
		];
	}//end provenance()

	/**
	 * The plan built so far.
	 *
	 * @return array{create:array<int,array<string,mixed>>,
	 *               update:array<int,array<string,mixed>>,
	 *               withdraw:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function plan(): array {
		return ['create' => $this->create, 'update' => $this->update, 'withdraw' => $this->withdraw];
	}//end plan()
}//end class
