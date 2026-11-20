<?php

/**
 * Federation configuration value object.
 *
 * Reads the stackiq federation app-config keys via IAppConfig and
 * exposes them as a small immutable value object so callers never touch raw
 * config keys. Defaults match the spec (directory.opencatalogi.nl, federation
 * disabled, no peers, hourly sync).
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

use OCA\Stackiq\Kernel\AppIdentity;
use OCA\Stackiq\Kernel\Contracts\FederationConfigInterface;
use OCP\IAppConfig;

/**
 * Immutable view of the federation configuration.
 */
class FederationConfig implements FederationConfigInterface {
	/**
	 * Default OpenCatalogi directory URL.
	 */
	public const DEFAULT_DIRECTORY_URL = 'https://directory.opencatalogi.nl';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config service.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether federation is enabled by the admin.
	 *
	 * @return bool True when enabled.
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getValueBool(AppIdentity::APP_ID, 'federation_enabled', false);
	}//end isEnabled()

	/**
	 * The configured directory URL (default directory.opencatalogi.nl).
	 *
	 * @return string The directory URL.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function getDirectoryUrl(): string {
		$url = trim($this->appConfig->getValueString(AppIdentity::APP_ID, 'federation_directory_url', ''));
		if ($url !== '') {
			return $url;
		}

		return self::DEFAULT_DIRECTORY_URL;
	}//end getDirectoryUrl()

	/**
	 * The subscribed peer allowlist (array of peer base URLs).
	 *
	 * @return array<int,string> The peer URLs.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function getPeers(): array {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, 'federation_peers', '[]');
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter(array_map('strval', $decoded)));
	}//end getPeers()

	/**
	 * Persist the peer allowlist.
	 *
	 * @param array<int,string> $peers The peer URLs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function setPeers(array $peers): void {
		$clean = array_values(array_unique(array_filter(array_map('strval', $peers))));
		$this->appConfig->setValueString(AppIdentity::APP_ID, 'federation_peers', json_encode($clean));
	}//end setPeers()

	/**
	 * The sync interval in seconds (default 3600).
	 *
	 * @return int The interval.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function getSyncInterval(): int {
		$value = $this->appConfig->getValueInt(AppIdentity::APP_ID, 'federation_sync_interval', 3600);
		if ($value > 0) {
			return $value;
		}

		return 3600;
	}//end getSyncInterval()

	/**
	 * The consecutive-failure threshold after which a peer's mirrors are marked
	 * stale (default 3, matching the spec).
	 *
	 * @return int The threshold.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function getStaleAfterFailures(): int {
		$value = $this->appConfig->getValueInt(AppIdentity::APP_ID, 'federation_stale_after_failures', 3);
		if ($value > 0) {
			return $value;
		}

		return 3;
	}//end getStaleAfterFailures()

	/**
	 * The per-peer pull timeout in seconds (default 15), so one unreachable peer
	 * cannot block the rest of the sync pass.
	 *
	 * @return int The timeout in seconds.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function getPeerTimeout(): int {
		$value = $this->appConfig->getValueInt(AppIdentity::APP_ID, 'federation_peer_timeout', 15);
		if ($value > 0) {
			return $value;
		}

		return 15;
	}//end getPeerTimeout()

	/**
	 * The most entries one pull reads from a peer (default 10000, the ceiling
	 * the app's other bounded reads use).
	 *
	 * @return int The cap.
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
	 */
	public function getMaxEntries(): int {
		$value = $this->appConfig->getValueInt(AppIdentity::APP_ID, 'federation_max_entries', 10000);
		if ($value > 0) {
			return $value;
		}

		return 10000;
	}//end getMaxEntries()

	/**
	 * What the last pulls of a peer left: its catalogue name, the last
	 * successful pull, the number of mirrored objects and the last error.
	 *
	 * @param string $peerUrl The peer base URL.
	 *
	 * @return array{name:string, lastPulledAt:?string, objectCount:int, lastError:?string}
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
	 */
	public function getPeerState(string $peerUrl): array {
		$state = ($this->readPeerStates()[$peerUrl] ?? []);
		if (is_array($state) === false) {
			$state = [];
		}

		return [
			'name' => (string)($state['name'] ?? ''),
			'lastPulledAt' => $this->stringOrNull(value: $state['lastPulledAt'] ?? null),
			'objectCount' => (int)($state['objectCount'] ?? 0),
			'lastError' => $this->stringOrNull(value: $state['lastError'] ?? null),
		];
	}//end getPeerState()

	/**
	 * Merge fields into a peer's recorded state; null forgets the peer.
	 *
	 * @param string                    $peerUrl The peer base URL.
	 * @param array<string, mixed>|null $changes The fields to set, or null to forget the peer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
	 */
	public function setPeerState(string $peerUrl, ?array $changes): void {
		$current = $this->getPeerState(peerUrl: $peerUrl);
		$states = $this->readPeerStates();
		unset($states[$peerUrl]);
		if ($changes !== null) {
			$states[$peerUrl] = array_merge($current, $changes);
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, 'federation_peer_state', json_encode($states));
	}//end setPeerState()

	/**
	 * Every recorded peer state, by peer URL.
	 *
	 * @return array<string, mixed> The states.
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
	 */
	private function readPeerStates(): array {
		$decoded = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, 'federation_peer_state', '{}'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end readPeerStates()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The string, or null.
	 *
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end stringOrNull()

	/**
	 * The current consecutive-failure count for a peer (keyed by its base URL).
	 *
	 * @param string $peerUrl The peer base URL.
	 *
	 * @return int The consecutive-failure count.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function getPeerFailures(string $peerUrl): int {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, 'federation_peer_failures', '{}');
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return 0;
		}

		return (int)($decoded[$peerUrl] ?? 0);
	}//end getPeerFailures()

	/**
	 * Record a peer's failure count (0 = healthy/clears the streak).
	 *
	 * @param string $peerUrl The peer base URL.
	 * @param int $failures The new consecutive-failure count.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function setPeerFailures(string $peerUrl, int $failures): void {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, 'federation_peer_failures', '{}');
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		// A non-positive failure count clears the entry entirely, so drop it
		// first and only re-add it when there is a positive count to record.
		unset($decoded[$peerUrl]);
		if ($failures > 0) {
			$decoded[$peerUrl] = $failures;
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, 'federation_peer_failures', json_encode($decoded));
	}//end setPeerFailures()

	/**
	 * The config-gated local-federation host allowlist (comma-separated).
	 *
	 * Mirrors `opencatalogi/local_federation_hosts`: private/loopback peer
	 * hosts are blocked by the SSRF guard unless listed here (empty by default).
	 *
	 * @return array<int,string> The allowlisted hosts.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function getLocalFederationHosts(): array {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, 'local_federation_hosts', '');
		if (trim($raw) === '') {
			return [];
		}

		return array_values(array_filter(array_map('trim', explode(',', $raw))));
	}//end getLocalFederationHosts()
}//end class
