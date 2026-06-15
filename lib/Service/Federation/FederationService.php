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
     * Constructor.
     *
     * @param ContainerInterface $container  The DI container (lazy OC lookup).
     * @param IAppManager        $appManager Detects OpenCatalogi availability.
     * @param FederationConfig   $config     The federation configuration.
     * @param LoggerInterface    $logger     Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly FederationConfig $config,
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
