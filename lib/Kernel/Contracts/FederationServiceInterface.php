<?php

/**
 * Contract of FederationService
 *
 * The public surface of FederationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

/**
 * Contract of FederationService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FederationServiceInterface {

	/**
	 * Add a peer to the federation allowlist (idempotent).
	 *
	 * SSRF-guards the host before persisting so a private/loopback peer can only
	 * be added when explicitly allowlisted via `local_federation_hosts`.
	 *
	 * @param string $peerUrl The peer base URL.
	 *
	 * A new peer asks integriq to resolve the federation connection again
	 * (adopt-connection-registry).
	 *
	 * @return array{ok:bool, reason:string} Result for the settings UI.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function addPeer(string $peerUrl): array;

	/**
	 * Announce this instance's catalog to the configured directory.
	 *
	 * Delegates to OpenCatalogi's BroadcastService. No-ops cleanly (returns a
	 * reason) when OpenCatalogi is missing or federation is disabled.
	 *
	 * @return array{ok:bool, reason:string}
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function announce(): array;

	/**
	 * Federation status for the admin settings UI.
	 *
	 * Each entry in `peers` is a per-peer status object the settings UI renders:
	 * the peer URL, its consecutive-failure streak, whether it has crossed the
	 * stale threshold, and whether its host passes the SSRF allowlist guard. The
	 * top-level `staleAfter` is the threshold those streaks are compared against.
	 *
	 * @return array{available:bool, enabled:bool, directoryUrl:string,
	 *               peers:array<int,array{url:string, failures:int, stale:bool,
	 *               allowed:bool}>, staleAfter:int, message:string}
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function getStatus(): array;

	/**
	 * Whether federation is available — OpenCatalogi installed/enabled AND its
	 * DirectoryService class is loadable.
	 *
	 * @return bool True when federation can run.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function isAvailable(): bool;

	/**
	 * Pull all subscribed peers and reconcile their catalogs into local mirrors.
	 *
	 * Iterates the `federation_peers` allowlist; each peer is pulled
	 * independently so one unreachable peer cannot block the rest. Returns a
	 * per-peer result summary for logging / the admin UI.
	 *
	 * Tells integriq what the pull met, from the Pull now button and from
	 * FederationSyncJob alike (adopt-connection-registry).
	 *
	 * @return array{ok:bool, reason:string, peers:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function pullAllPeers(): array;

	/**
	 * Remove a peer from the federation allowlist and clear its failure streak.
	 *
	 * @param string $peerUrl The peer base URL.
	 *
	 * A removed peer asks integriq to resolve the federation connection again
	 * (adopt-connection-registry).
	 *
	 * @return array{ok:bool, reason:string} Result for the settings UI.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
	 */
	public function removePeer(string $peerUrl): array;
}//end interface
