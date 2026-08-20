<?php

/**
 * Federation Service.
 *
 * Cross-instance catalog federation by DELEGATING to OpenCatalogi's proven
 * federation stack (DirectoryService / BroadcastService) — never a bespoke
 * wire protocol (design constraint). softwarecatalog contributes only its
 * schema mapping, the merge/provenance semantics, the sync schedule, and the
 * admin controls. When OpenCatalogi is not installed, every entry point
 * degrades to a clean disabled state with a clear message — it never errors.
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

use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates federation by delegating to OpenCatalogi services.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Overall complexity 84 (threshold 50) — the
 * highest in the app. OpenCatalogi is an OPTIONAL runtime dependency, so every delegation point
 * here is wrapped in an app-installed / service-resolvable / shape-of-response guard that has to
 * degrade to a recorded "federation unavailable" instead of fatalling. Much of that guard code is
 * repeated per delegated operation and SHOULD be collapsed behind a single resolver helper;
 * noting that as a deliberate follow-up rather than claiming 84 is a healthy number.
 */
class FederationService {
	/**
	 * The OpenCatalogi app id (the hard runtime dependency for federation).
	 */
	public const OPENCATALOGI_APP_ID = 'opencatalogi';

	/**
	 * The catalog object type peer mirrors are merged into (organisation
	 * profiles — the federated catalog unit OpenCatalogi exposes by directory).
	 */
	public const PEER_MIRROR_TYPE = 'organization';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy OC/OR lookup).
	 * @param IAppManager $appManager Detects OpenCatalogi availability.
	 * @param FederationConfig $config The federation configuration.
	 * @param FederationMerger $merger The merge/staleness reconciler.
	 * @param SettingsService|null $settingsService Resolves the mirror register/schema (lazy/optional).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly FederationConfig $config,
		private readonly FederationMerger $merger,
		private readonly ?SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether federation is available — OpenCatalogi installed/enabled AND its
	 * DirectoryService class is loadable.
	 *
	 * @return bool True when federation can run.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function isAvailable(): bool {
		if (in_array(self::OPENCATALOGI_APP_ID, $this->appManager->getInstalledApps(), true) === false) {
			return false;
		}

		return class_exists('OCA\\OpenCatalogi\\Service\\DirectoryService');
	}//end isAvailable()

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
	public function getStatus(): array {
		$available = $this->isAvailable();
		$staleAfter = $this->config->getStaleAfterFailures();
		$peers = [];
		foreach ($this->config->getPeers() as $peerUrl) {
			$failures = $this->config->getPeerFailures($peerUrl);
			$peers[] = [
				'url' => $peerUrl,
				'failures' => $failures,
				'stale' => ($failures >= $staleAfter),
				'allowed' => $this->isPeerHostAllowed(url: $peerUrl),
			];
		}

		$message = 'Federation unavailable — requires OpenCatalogi';
		if ($available === true) {
			$message = 'Federation available';
		}

		return [
			'available' => $available,
			'enabled' => $this->config->isEnabled(),
			'staleAfter' => $staleAfter,
			'directoryUrl' => $this->config->getDirectoryUrl(),
			'peers' => $peers,
			'message' => $message,
		];
	}//end getStatus()

	/**
	 * Add a peer to the federation allowlist (idempotent).
	 *
	 * SSRF-guards the host before persisting so a private/loopback peer can only
	 * be added when explicitly allowlisted via `local_federation_hosts`.
	 *
	 * @param string $peerUrl The peer base URL.
	 *
	 * @return array{ok:bool, reason:string} Result for the settings UI.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function addPeer(string $peerUrl): array {
		$peerUrl = trim($peerUrl);
		if ($peerUrl === '') {
			return ['ok' => false, 'reason' => 'peer url is required'];
		}

		if ($this->isPeerHostAllowed(url: $peerUrl) === false) {
			return ['ok' => false, 'reason' => 'peer host blocked by SSRF guard'];
		}

		$peers = $this->config->getPeers();
		if (in_array($peerUrl, $peers, true) === true) {
			return ['ok' => true, 'reason' => 'peer already present'];
		}

		$peers[] = $peerUrl;
		$this->config->setPeers(array_values($peers));
		return ['ok' => true, 'reason' => 'peer added'];
	}//end addPeer()

	/**
	 * Remove a peer from the federation allowlist and clear its failure streak.
	 *
	 * @param string $peerUrl The peer base URL.
	 *
	 * @return array{ok:bool, reason:string} Result for the settings UI.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function removePeer(string $peerUrl): array {
		$peerUrl = trim($peerUrl);
		$peers = $this->config->getPeers();
		$filtered = array_values(array_filter($peers, static fn (string $peer): bool => $peer !== $peerUrl));
		if (count($filtered) === count($peers)) {
			return ['ok' => false, 'reason' => 'peer not found'];
		}

		$this->config->setPeers($filtered);
		$this->config->setPeerFailures($peerUrl, 0);
		return ['ok' => true, 'reason' => 'peer removed'];
	}//end removePeer()

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
	public function announce(): array {
		if ($this->config->isEnabled() === false) {
			return ['ok' => false, 'reason' => 'federation disabled'];
		}

		if ($this->isAvailable() === false) {
			$this->logger->info('[Federation] announce skipped — OpenCatalogi unavailable');
			return ['ok' => false, 'reason' => 'OpenCatalogi unavailable'];
		}

		try {
			$broadcast = $this->container->get('OCA\\OpenCatalogi\\Service\\BroadcastService');
			$broadcast->broadcast($this->config->getDirectoryUrl());
			return ['ok' => true, 'reason' => 'announced'];
		} catch (\Throwable $e) {
			$this->logger->error('[Federation] announce failed', ['error' => $e->getMessage()]);
			return ['ok' => false, 'reason' => $e->getMessage()];
		}
	}//end announce()

	/*
	 * NO federation-specific publish entry point lives here, deliberately.
	 *
	 * `publishEntryForFederation()` used to sit at this spot: a pass-through to
	 * `PublicationService::publish()` with ZERO production callers (only two
	 * unit tests that constructed FederationService and called it directly).
	 * Gate 57 (orphaned-write-capability) named it, and tracing the callers
	 * showed the capability itself is NOT missing — it is live through
	 * `PublicationController::publish()`, routed as `publication#publish`
	 * (`PUT /api/publication/{objectType}/{uuid}/publish`, appinfo/routes.php),
	 * which calls the SAME `PublicationService::publish()` behind a per-object
	 * `authorizeEntry()` IDOR guard and additionally honours the optional
	 * ISO-8601 `$when` argument the wrapper silently dropped.
	 *
	 * Federation therefore has nothing of its own to publish: it consumes the
	 * result of that one publish model, because visibility is enforced by the
	 * OpenRegister public RBAC read gate
	 * `{group:public, match:{publicatiedatum:{$lte:$now}}}` — the same rule that
	 * governs anonymous open-data reads. Re-adding a second, guardless publish
	 * seam here would duplicate a live capability and weaken it.
	 */

	/**
	 * Discover peer catalogs from the configured directory.
	 *
	 * Delegates to OpenCatalogi's DirectoryService. Returns an empty list (with
	 * a reason) when OpenCatalogi is missing.
	 *
	 * @return array{ok:bool, reason:string, peers:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function discoverPeers(): array {
		if ($this->isAvailable() === false) {
			return ['ok' => false, 'reason' => 'OpenCatalogi unavailable', 'peers' => []];
		}

		try {
			$directory = $this->container->get('OCA\\OpenCatalogi\\Service\\DirectoryService');
			$listing = $directory->getDirectory([]);
			$peers = [];
			if (is_array($listing['results'] ?? null) === true) {
				$peers = $listing['results'];
			} elseif (is_array($listing) === true) {
				$peers = $listing;
			}

			return ['ok' => true, 'reason' => 'ok', 'peers' => $peers];
		} catch (\Throwable $e) {
			$this->logger->error('[Federation] discoverPeers failed', ['error' => $e->getMessage()]);
			return ['ok' => false, 'reason' => $e->getMessage(), 'peers' => []];
		}
	}//end discoverPeers()

	/**
	 * Pull all subscribed peers and reconcile their catalogs into local mirrors.
	 *
	 * Iterates the `federation_peers` allowlist; each peer is pulled
	 * independently so one unreachable peer cannot block the rest. Returns a
	 * per-peer result summary for logging / the admin UI.
	 *
	 * @return array{ok:bool, reason:string, peers:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function pullAllPeers(): array {
		if ($this->config->isEnabled() === false) {
			return ['ok' => false, 'reason' => 'federation disabled', 'peers' => []];
		}

		if ($this->isAvailable() === false) {
			return ['ok' => false, 'reason' => 'OpenCatalogi unavailable', 'peers' => []];
		}

		$results = [];
		foreach ($this->config->getPeers() as $peerUrl) {
			$results[] = ['peer' => $peerUrl] + $this->pullPeer(peerUrl: $peerUrl);
		}

		return ['ok' => true, 'reason' => 'ok', 'peers' => $results];
	}//end pullAllPeers()

	/**
	 * Pull one peer's published catalog and reconcile it into local mirrors.
	 *
	 * Steps: (1) SSRF-guard the peer host; (2) fetch the peer's published
	 * entries through OpenCatalogi's DirectoryService (never a bespoke HTTP
	 * client); (3) load the locally-stored mirrors of THIS peer; (4) compute a
	 * create/update/withdraw plan via {@see FederationMerger}; (5) apply it via
	 * the OpenRegister ObjectService abstraction (ADR-022). On a failed fetch the
	 * peer's consecutive-failure count increments and, past the threshold, its
	 * mirrors are marked stale (never silently deleted). A successful pull clears
	 * the failure streak and any stale flags.
	 *
	 * @param string $peerUrl The peer base URL (provenance instance).
	 *
	 * @return array{ok:bool, reason:string, created:int, updated:int, withdrawn:int, stale:bool}
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function pullPeer(string $peerUrl): array {
		$empty = ['created' => 0, 'updated' => 0, 'withdrawn' => 0, 'stale' => false];

		if ($this->isPeerHostAllowed(url: $peerUrl) === false) {
			return ['ok' => false, 'reason' => 'peer host blocked by SSRF guard'] + $empty;
		}

		$fetch = $this->fetchPeerCatalog(peerUrl: $peerUrl);
		if ($fetch['ok'] === false) {
			return $this->recordPullFailure(peerUrl: $peerUrl, reason: $fetch['reason']) + ['ok' => false, 'reason' => $fetch['reason']];
		}

		$target = $this->resolveMirrorTarget();
		if ($target === null) {
			return ['ok' => false, 'reason' => 'mirror register/schema not configured'] + $empty;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['ok' => false, 'reason' => 'ObjectService unavailable'] + $empty;
		}

		$localMirrors = $this->loadPeerMirrors(peerUrl: $peerUrl, target: $target);
		$plan = $this->merger->plan(
			$peerUrl,
			(string)($fetch['organisation'] ?? $peerUrl),
			$fetch['entries'],
			$localMirrors,
			$this->now()
		);

		$created = $this->applyMirrors(
			objectService: $objectService,
			target: $target,
			mirrors: $plan['create'],
			uuidKey: null
		);
		$updated = $this->applyMirrors(
			objectService: $objectService,
			target: $target,
			mirrors: $plan['update'],
			uuidKey: 'id'
		);
		$withdrawn = $this->applyMirrors(
			objectService: $objectService,
			target: $target,
			mirrors: $plan['withdraw'],
			uuidKey: 'id'
		);

		// Successful pull clears the failure streak and un-stales surviving mirrors.
		$this->config->setPeerFailures($peerUrl, 0);
		$this->clearStaleMirrors(objectService: $objectService, target: $target, peerUrl: $peerUrl);

		return [
			'ok' => true,
			'reason' => 'pulled',
			'created' => $created,
			'updated' => $updated,
			'withdrawn' => $withdrawn,
			'stale' => false,
		];
	}//end pullPeer()

	/**
	 * Fetch a peer's published catalog entries via OpenCatalogi's DirectoryService.
	 *
	 * Delegates the wire to OpenCatalogi — softwarecatalog never speaks a bespoke
	 * federation protocol. Only published entries are returned (the peer's own
	 * `publicatiedatum<=$now` public RBAC gate governs that surface).
	 *
	 * @param string $peerUrl The peer base URL.
	 *
	 * @return array{ok:bool, reason:string, organisation:?string, entries:array<int,array<string,mixed>>}
	 */
	private function fetchPeerCatalog(string $peerUrl): array {
		try {
			$directory = $this->container->get('OCA\\OpenCatalogi\\Service\\DirectoryService');
			// OpenCatalogi exposes a peer's published catalog through getDirectory
			// / a peer-scoped fetch; we pass the peer URL so OC performs the
			// SSRF-aware HTTP call and returns the published publications.
			$listing = $directory->getDirectory(['url' => $peerUrl]);
		} catch (\Throwable $e) {
			$this->logger->error('[Federation] peer fetch failed', ['peer' => $peerUrl, 'error' => $e->getMessage()]);
			return ['ok' => false, 'reason' => $e->getMessage(), 'organisation' => null, 'entries' => []];
		}

		$entries = [];
		if (is_array($listing['results'] ?? null) === true) {
			$entries = $listing['results'];
		} elseif (is_array($listing) === true) {
			$entries = $listing;
		}

		$organisation = null;
		if (is_string($listing['organisation'] ?? null) === true) {
			$organisation = $listing['organisation'];
		}

		return ['ok' => true, 'reason' => 'ok', 'organisation' => $organisation, 'entries' => array_values($entries)];
	}//end fetchPeerCatalog()

	/**
	 * Record a failed pull: increment the peer's failure streak and, past the
	 * configured threshold, mark its mirrors stale (surfaced, never deleted).
	 *
	 * @param string $peerUrl The peer base URL.
	 * @param string $reason The failure reason (logging).
	 *
	 * @return array{created:int, updated:int, withdrawn:int, stale:bool}
	 */
	private function recordPullFailure(string $peerUrl, string $reason): array {
		$failures = ($this->config->getPeerFailures($peerUrl) + 1);
		$this->config->setPeerFailures($peerUrl, $failures);

		$stale = $this->merger->isStale($failures, $this->config->getStaleAfterFailures());
		if ($stale === true) {
			$target = $this->resolveMirrorTarget();
			$objectService = $this->getObjectService();
			if ($target !== null && $objectService !== null) {
				$this->staleMirrors(objectService: $objectService, target: $target, peerUrl: $peerUrl);
			}

			$this->logger->warning(
				'[Federation] peer marked stale after consecutive failures',
				['peer' => $peerUrl, 'failures' => $failures, 'reason' => $reason]
			);
		}

		return ['created' => 0, 'updated' => 0, 'withdrawn' => 0, 'stale' => $stale];
	}//end recordPullFailure()

	/**
	 * Load the locally-stored mirrors of a single peer (this peer's mirrors only).
	 *
	 * @param string $peerUrl The peer base URL.
	 * @param array{register:int,schema:int} $target The mirror register/schema.
	 *
	 * @return array<int,array<string,mixed>> The peer's local mirror data bags.
	 */
	private function loadPeerMirrors(string $peerUrl, array $target): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$objects = $objectService->searchObjects(
				query: [
					'@self' => ['register' => $target['register'], 'schema' => $target['schema']],
					'_source.instance' => $peerUrl,
					'_limit' => 1000,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error('[Federation] mirror load failed', ['peer' => $peerUrl, 'error' => $e->getMessage()]);
			return [];
		}

		$items = [];
		if (is_array($objects) === true) {
			$items = $objects;
		}

		$mirrors = [];
		foreach ($items as $object) {
			$data = $this->toDataBag(object: $object);
			if ($this->isPeerSourced(objectData: $data) === true
				&& (string)($data['_source']['instance'] ?? '') === $peerUrl
			) {
				$mirrors[] = $data;
			}
		}

		return $mirrors;
	}//end loadPeerMirrors()

	/**
	 * Apply a list of mirror data bags via ObjectService::saveObject.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param array{register:int,schema:int} $target The mirror register/schema.
	 * @param array<int,array<string,mixed>> $mirrors The mirror data bags.
	 * @param string|null $uuidKey Data key holding the existing uuid (null = create).
	 *
	 * @return int The count of successfully applied mirrors.
	 */
	private function applyMirrors(object $objectService, array $target, array $mirrors, ?string $uuidKey): int {
		$count = 0;
		foreach ($mirrors as $mirror) {
			$uuid = '';
			if ($uuidKey !== null) {
				$uuid = (string)($mirror[$uuidKey] ?? '');
			}

			try {
				$objectService->saveObject(
					object: $mirror,
					register: $target['register'],
					schema: $target['schema'],
					uuid: $uuid
				);
				$count++;
			} catch (\Throwable $e) {
				$this->logger->error('[Federation] mirror save failed', ['error' => $e->getMessage()]);
			}
		}

		return $count;
	}//end applyMirrors()

	/**
	 * Mark every mirror of a peer stale (failure threshold reached).
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param array{register:int,schema:int} $target The mirror register/schema.
	 * @param string $peerUrl The peer base URL.
	 *
	 * @return void
	 */
	private function staleMirrors(object $objectService, array $target, string $peerUrl): void {
		foreach ($this->loadPeerMirrors(peerUrl: $peerUrl, target: $target) as $mirror) {
			$staled = $this->merger->applyStale(mirror: $mirror, stale: true);
			$this->applyMirrors(
				objectService: $objectService,
				target: $target,
				mirrors: [$staled],
				uuidKey: 'id'
			);
		}
	}//end staleMirrors()

	/**
	 * Clear the stale flag on every surviving mirror of a peer (healthy pull).
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param array{register:int,schema:int} $target The mirror register/schema.
	 * @param string $peerUrl The peer base URL.
	 *
	 * @return void
	 */
	private function clearStaleMirrors(object $objectService, array $target, string $peerUrl): void {
		foreach ($this->loadPeerMirrors(peerUrl: $peerUrl, target: $target) as $mirror) {
			if (($mirror['_source']['stale'] ?? false) !== true) {
				continue;
			}

			if (($mirror['_source']['withdrawn'] ?? false) === true) {
				// Withdrawn mirrors stay stale by design.
				continue;
			}

			$fresh = $this->merger->applyStale(mirror: $mirror, stale: false);
			$this->applyMirrors(
				objectService: $objectService,
				target: $target,
				mirrors: [$fresh],
				uuidKey: 'id'
			);
		}
	}//end clearStaleMirrors()

	/**
	 * Resolve the register/schema the peer mirrors live in.
	 *
	 * @return array{register:int, schema:int}|null The target, or null when unconfigured.
	 */
	private function resolveMirrorTarget(): ?array {
		if ($this->settingsService === null) {
			return null;
		}

		$register = $this->settingsService->getRegisterIdForObjectType(self::PEER_MIRROR_TYPE);
		$schema = $this->settingsService->getSchemaIdForObjectType(self::PEER_MIRROR_TYPE);
		if ($register === null || $schema === null) {
			return null;
		}

		return ['register' => (int)$register, 'schema' => (int)$schema];
	}//end resolveMirrorTarget()

	/**
	 * Normalise an ObjectService result item to a data bag (with its OR id).
	 *
	 * @param mixed $object The result item (ObjectEntity or array).
	 *
	 * @return array<string,mixed> The data bag.
	 */
	private function toDataBag(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (method_exists($object, 'getUuid') === true && empty($data['id']) === true) {
				$data['id'] = $object->getUuid();
			}

			if (is_array($data) === true) {
				return $data;
			}

			return [];
		}

		return [];
	}//end toDataBag()

	/**
	 * Get the OpenRegister ObjectService from the DI container.
	 *
	 * @return object|null The object service, or null when OR is absent.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error('[Federation] ObjectService unavailable', ['error' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()

	/**
	 * The current moment as a comparable ISO-8601 string.
	 *
	 * @return string Now.
	 */
	private function now(): string {
		return gmdate('Y-m-d\TH:i:sP');
	}//end now()

	/**
	 * Whether an object is a foreign (peer-sourced) entry and therefore
	 * read-only in this instance. An entry carrying a `_source.instance` that
	 * is not this instance MUST refuse local mutation.
	 *
	 * @param array<string,mixed> $objectData The object data bag.
	 *
	 * @return bool True when the object is peer-sourced (read-only).
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function isPeerSourced(array $objectData): bool {
		$source = $objectData['_source'] ?? null;
		if (is_array($source) === false) {
			return false;
		}

		$instance = $source['instance'] ?? null;
		return is_string($instance) && trim($instance) !== '';
	}//end isPeerSourced()

	/**
	 * Whether a peer host passes the SSRF guard for federation.
	 *
	 * Private/loopback hosts are blocked unless explicitly allowlisted via
	 * `local_federation_hosts` (mirrors the OpenCatalogi semantics for
	 * local/test federation).
	 *
	 * @param string $url The peer base URL.
	 *
	 * @return bool True when the host is allowed.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	public function isPeerHostAllowed(string $url): bool {
		$host = parse_url($url, PHP_URL_HOST);
		if (is_string($host) === false || $host === '') {
			return false;
		}

		$allowlist = $this->config->getLocalFederationHosts();
		if (in_array($host, $allowlist, true) === true) {
			return true;
		}

		// Block obvious private/loopback hosts when not allowlisted.
		if ($host === 'localhost' || str_ends_with($host, '.local') === true) {
			return false;
		}

		$ipAddress = filter_var($host, FILTER_VALIDATE_IP);
		if ($ipAddress !== false) {
			$public = filter_var(
				$ipAddress,
				FILTER_VALIDATE_IP,
				(FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
			);
			return $public !== false;
		}

		return true;
	}//end isPeerHostAllowed()
}//end class
