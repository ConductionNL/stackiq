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
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\Federation;

use OCA\SoftwareCatalog\Service\PublicationService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates federation by delegating to OpenCatalogi services.
 */
class FederationService
{
    /**
     * The OpenCatalogi app id (the hard runtime dependency for federation).
     */
    public const OPENCATALOGI_APP_ID = 'opencatalogi';

    /**
     * The catalog object type peer mirrors are merged into (organisation
     * profiles — the federated catalog unit OpenCatalogi exposes by directory).
     */
    public const PEER_MIRROR_TYPE = 'organisatie';

    /**
     * Constructor.
     *
     * @param ContainerInterface   $container       The DI container (lazy OC/OR lookup).
     * @param IAppManager          $appManager      Detects OpenCatalogi availability.
     * @param FederationConfig     $config          The federation configuration.
     * @param FederationMerger     $merger          The merge/staleness reconciler.
     * @param SettingsService|null $settingsService Resolves the mirror register/schema (lazy/optional).
     * @param LoggerInterface      $logger          Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly FederationConfig $config,
        private readonly FederationMerger $merger,
        private readonly ?SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Whether federation is available — OpenCatalogi installed/enabled AND its
     * DirectoryService class is loadable.
     *
     * @return bool True when federation can run.
     *
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function isAvailable(): bool
    {
        if (in_array(self::OPENCATALOGI_APP_ID, $this->appManager->getInstalledApps(), true) === false) {
            return false;
        }
        return class_exists('OCA\\OpenCatalogi\\Service\\DirectoryService');
    }//end isAvailable()

    /**
     * Federation status for the admin settings UI.
     *
     * @return array{available:bool, enabled:bool, directoryUrl:string, peers:array, message:string}
     *
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function getStatus(): array
    {
        $available = $this->isAvailable();
        return [
            'available'    => $available,
            'enabled'      => $this->config->isEnabled(),
            'directoryUrl' => $this->config->getDirectoryUrl(),
            'peers'        => $this->config->getPeers(),
            'message'      => $available === true
                ? 'Federation available'
                : 'Federation unavailable — requires OpenCatalogi',
        ];
    }//end getStatus()

    /**
     * Announce this instance's catalog to the configured directory.
     *
     * Delegates to OpenCatalogi's BroadcastService. No-ops cleanly (returns a
     * reason) when OpenCatalogi is missing or federation is disabled.
     *
     * @return array{ok:bool, reason:string}
     *
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function announce(): array
    {
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

    /**
     * Make a local catalog entry visible to the federation by PUBLISHING it —
     * i.e. set its `publicatiedatum` (the live OR RBAC publish gate) via the
     * PublicationService. Only entries past their publicatiedatum are exposed to
     * anonymous federation reads through the OpenCatalogi/OpenRegister public
     * read surface; drafts (no publicatiedatum) never leave the instance.
     *
     * This is the publication-visibility leg of federation: it reuses the exact
     * same `{group:public, match:{publicatiedatum:{$lte:$now}}}` rule that
     * governs anonymous open-data reads, so one publish model serves both. The
     * live cross-instance pull/merge remains deferred (needs a two-instance
     * testbed) — see the @spec'd federated-catalog-sync subscription leg.
     *
     * @param string $objectType The publishable catalog object type.
     * @param string $uuid       The entry uuid.
     *
     * @return array{ok:bool, reason:string} Result.
     *
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function publishEntryForFederation(string $objectType, string $uuid): array
    {
        $publication = $this->getPublicationService();
        if ($publication === null) {
            return ['ok' => false, 'reason' => 'PublicationService unavailable'];
        }

        $result = $publication->publish($objectType, $uuid);
        return ['ok' => $result['ok'], 'reason' => $result['reason']];
    }//end publishEntryForFederation()

    /**
     * Get the open-data PublicationService (lazy, via the container) — federation
     * reuses the same publicatiedatum publish gate as anonymous open data.
     *
     * @return PublicationService|null The service, or null when unavailable.
     */
    private function getPublicationService(): ?PublicationService
    {
        try {
            return $this->container->get(PublicationService::class);
        } catch (\Throwable $e) {
            $this->logger->error('[Federation] PublicationService unavailable', ['error' => $e->getMessage()]);
            return null;
        }
    }//end getPublicationService()

    /**
     * Discover peer catalogs from the configured directory.
     *
     * Delegates to OpenCatalogi's DirectoryService. Returns an empty list (with
     * a reason) when OpenCatalogi is missing.
     *
     * @return array{ok:bool, reason:string, peers:array<int,array<string,mixed>>}
     *
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function discoverPeers(): array
    {
        if ($this->isAvailable() === false) {
            return ['ok' => false, 'reason' => 'OpenCatalogi unavailable', 'peers' => []];
        }

        try {
            $directory = $this->container->get('OCA\\OpenCatalogi\\Service\\DirectoryService');
            $listing   = $directory->getDirectory([]);
            $peers     = is_array($listing['results'] ?? null) ? $listing['results'] : (is_array($listing) ? $listing : []);
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
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function pullAllPeers(): array
    {
        if ($this->config->isEnabled() === false) {
            return ['ok' => false, 'reason' => 'federation disabled', 'peers' => []];
        }
        if ($this->isAvailable() === false) {
            return ['ok' => false, 'reason' => 'OpenCatalogi unavailable', 'peers' => []];
        }

        $results = [];
        foreach ($this->config->getPeers() as $peerUrl) {
            $results[] = ['peer' => $peerUrl] + $this->pullPeer($peerUrl);
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
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function pullPeer(string $peerUrl): array
    {
        $empty = ['created' => 0, 'updated' => 0, 'withdrawn' => 0, 'stale' => false];

        if ($this->isPeerHostAllowed($peerUrl) === false) {
            return ['ok' => false, 'reason' => 'peer host blocked by SSRF guard'] + $empty;
        }

        $fetch = $this->fetchPeerCatalog($peerUrl);
        if ($fetch['ok'] === false) {
            return $this->recordPullFailure($peerUrl, $fetch['reason']) + ['ok' => false, 'reason' => $fetch['reason']];
        }

        $target = $this->resolveMirrorTarget();
        if ($target === null) {
            return ['ok' => false, 'reason' => 'mirror register/schema not configured'] + $empty;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['ok' => false, 'reason' => 'ObjectService unavailable'] + $empty;
        }

        $localMirrors = $this->loadPeerMirrors($peerUrl, $target);
        $plan         = $this->merger->plan(
            $peerUrl,
            (string) ($fetch['organisation'] ?? $peerUrl),
            $fetch['entries'],
            $localMirrors,
            $this->now()
        );

        $created = $this->applyMirrors($objectService, $target, $plan['create'], null);
        $updated = $this->applyMirrors($objectService, $target, $plan['update'], 'id');
        $withdrawn = $this->applyMirrors($objectService, $target, $plan['withdraw'], 'id');

        // Successful pull clears the failure streak and un-stales surviving mirrors.
        $this->config->setPeerFailures($peerUrl, 0);
        $this->clearStaleMirrors($objectService, $target, $peerUrl);

        return [
            'ok'        => true,
            'reason'    => 'pulled',
            'created'   => $created,
            'updated'   => $updated,
            'withdrawn' => $withdrawn,
            'stale'     => false,
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
    private function fetchPeerCatalog(string $peerUrl): array
    {
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

        $organisation = is_string($listing['organisation'] ?? null) ? $listing['organisation'] : null;

        return ['ok' => true, 'reason' => 'ok', 'organisation' => $organisation, 'entries' => array_values($entries)];
    }//end fetchPeerCatalog()

    /**
     * Record a failed pull: increment the peer's failure streak and, past the
     * configured threshold, mark its mirrors stale (surfaced, never deleted).
     *
     * @param string $peerUrl The peer base URL.
     * @param string $reason  The failure reason (logging).
     *
     * @return array{created:int, updated:int, withdrawn:int, stale:bool}
     */
    private function recordPullFailure(string $peerUrl, string $reason): array
    {
        $failures = ($this->config->getPeerFailures($peerUrl) + 1);
        $this->config->setPeerFailures($peerUrl, $failures);

        $stale = $this->merger->isStale($failures, $this->config->getStaleAfterFailures());
        if ($stale === true) {
            $target        = $this->resolveMirrorTarget();
            $objectService = $this->getObjectService();
            if ($target !== null && $objectService !== null) {
                $this->staleMirrors($objectService, $target, $peerUrl);
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
     * @param string                       $peerUrl The peer base URL.
     * @param array{register:int,schema:int} $target  The mirror register/schema.
     *
     * @return array<int,array<string,mixed>> The peer's local mirror data bags.
     */
    private function loadPeerMirrors(string $peerUrl, array $target): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        try {
            $objects = $objectService->searchObjects(
                query: [
                    '@self' => ['register' => $target['register'], 'schema' => $target['schema']],
                    '_source.instance' => $peerUrl,
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->error('[Federation] mirror load failed', ['peer' => $peerUrl, 'error' => $e->getMessage()]);
            return [];
        }

        $mirrors = [];
        foreach ((is_array($objects) ? $objects : []) as $object) {
            $data = $this->toDataBag($object);
            if ($this->isPeerSourced($data) === true && (string) ($data['_source']['instance'] ?? '') === $peerUrl) {
                $mirrors[] = $data;
            }
        }
        return $mirrors;
    }//end loadPeerMirrors()

    /**
     * Apply a list of mirror data bags via ObjectService::saveObject.
     *
     * @param object                          $objectService The OR ObjectService.
     * @param array{register:int,schema:int}  $target        The mirror register/schema.
     * @param array<int,array<string,mixed>>  $mirrors       The mirror data bags.
     * @param string|null                     $uuidKey       Data key holding the existing uuid (null = create).
     *
     * @return int The count of successfully applied mirrors.
     */
    private function applyMirrors(object $objectService, array $target, array $mirrors, ?string $uuidKey): int
    {
        $count = 0;
        foreach ($mirrors as $mirror) {
            $uuid = ($uuidKey !== null) ? (string) ($mirror[$uuidKey] ?? '') : '';
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
     * @param object                         $objectService The OR ObjectService.
     * @param array{register:int,schema:int} $target        The mirror register/schema.
     * @param string                         $peerUrl       The peer base URL.
     *
     * @return void
     */
    private function staleMirrors(object $objectService, array $target, string $peerUrl): void
    {
        foreach ($this->loadPeerMirrors($peerUrl, $target) as $mirror) {
            $staled = $this->merger->applyStale($mirror, true);
            $this->applyMirrors($objectService, $target, [$staled], 'id');
        }
    }//end staleMirrors()

    /**
     * Clear the stale flag on every surviving mirror of a peer (healthy pull).
     *
     * @param object                         $objectService The OR ObjectService.
     * @param array{register:int,schema:int} $target        The mirror register/schema.
     * @param string                         $peerUrl       The peer base URL.
     *
     * @return void
     */
    private function clearStaleMirrors(object $objectService, array $target, string $peerUrl): void
    {
        foreach ($this->loadPeerMirrors($peerUrl, $target) as $mirror) {
            if (($mirror['_source']['stale'] ?? false) !== true) {
                continue;
            }
            if (($mirror['_source']['withdrawn'] ?? false) === true) {
                // Withdrawn mirrors stay stale by design.
                continue;
            }
            $fresh = $this->merger->applyStale($mirror, false);
            $this->applyMirrors($objectService, $target, [$fresh], 'id');
        }
    }//end clearStaleMirrors()

    /**
     * Resolve the register/schema the peer mirrors live in.
     *
     * @return array{register:int, schema:int}|null The target, or null when unconfigured.
     */
    private function resolveMirrorTarget(): ?array
    {
        if ($this->settingsService === null) {
            return null;
        }
        $register = $this->settingsService->getRegisterIdForObjectType(self::PEER_MIRROR_TYPE);
        $schema   = $this->settingsService->getSchemaIdForObjectType(self::PEER_MIRROR_TYPE);
        if ($register === null || $schema === null) {
            return null;
        }
        return ['register' => (int) $register, 'schema' => (int) $schema];
    }//end resolveMirrorTarget()

    /**
     * Normalise an ObjectService result item to a data bag (with its OR id).
     *
     * @param mixed $object The result item (ObjectEntity or array).
     *
     * @return array<string,mixed> The data bag.
     */
    private function toDataBag(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }
        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (method_exists($object, 'getUuid') === true && empty($data['id']) === true) {
                $data['id'] = $object->getUuid();
            }
            return is_array($data) ? $data : [];
        }
        return [];
    }//end toDataBag()

    /**
     * Get the OpenRegister ObjectService from the DI container.
     *
     * @return object|null The object service, or null when OR is absent.
     */
    private function getObjectService(): ?object
    {
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
    private function now(): string
    {
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
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function isPeerSourced(array $objectData): bool
    {
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
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function isPeerHostAllowed(string $url): bool
    {
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
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            );
            return $public !== false;
        }

        return true;
    }//end isPeerHostAllowed()
}//end class
