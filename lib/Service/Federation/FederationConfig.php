<?php
/**
 * Federation configuration value object.
 *
 * Reads the softwarecatalog federation app-config keys via IAppConfig and
 * exposes them as a small immutable value object so callers never touch raw
 * config keys. Defaults match the spec (directory.opencatalogi.nl, federation
 * disabled, no peers, hourly sync).
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service\Federation
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\Federation;

use OCA\SoftwareCatalog\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Immutable view of the federation configuration.
 */
class FederationConfig
{
    /**
     * Default OpenCatalogi directory URL.
     */
    public const DEFAULT_DIRECTORY_URL = 'https://directory.opencatalogi.nl';

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig The app config service.
     */
    public function __construct(private readonly IAppConfig $appConfig)
    {
    }//end __construct()

    /**
     * Whether federation is enabled by the admin.
     *
     * @return bool True when enabled.
     */
    public function isEnabled(): bool
    {
        return $this->appConfig->getValueBool(Application::APP_ID, 'federation_enabled', false);
    }//end isEnabled()

    /**
     * The configured directory URL (default directory.opencatalogi.nl).
     *
     * @return string The directory URL.
     *
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function getDirectoryUrl(): string
    {
        $url = trim($this->appConfig->getValueString(Application::APP_ID, 'federation_directory_url', ''));
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
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function getPeers(): array
    {
        $raw     = $this->appConfig->getValueString(Application::APP_ID, 'federation_peers', '[]');
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
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function setPeers(array $peers): void
    {
        $clean = array_values(array_unique(array_filter(array_map('strval', $peers))));
        $this->appConfig->setValueString(Application::APP_ID, 'federation_peers', json_encode($clean));
    }//end setPeers()

    /**
     * The sync interval in seconds (default 3600).
     *
     * @return int The interval.
     *
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function getSyncInterval(): int
    {
        $value = $this->appConfig->getValueInt(Application::APP_ID, 'federation_sync_interval', 3600);
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
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function getStaleAfterFailures(): int
    {
        $value = $this->appConfig->getValueInt(Application::APP_ID, 'federation_stale_after_failures', 3);
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
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function getPeerTimeout(): int
    {
        $value = $this->appConfig->getValueInt(Application::APP_ID, 'federation_peer_timeout', 15);
        if ($value > 0) {
            return $value;
        }

        return 15;
    }//end getPeerTimeout()

    /**
     * The current consecutive-failure count for a peer (keyed by its base URL).
     *
     * @param string $peerUrl The peer base URL.
     *
     * @return int The consecutive-failure count.
     *
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function getPeerFailures(string $peerUrl): int
    {
        $raw     = $this->appConfig->getValueString(Application::APP_ID, 'federation_peer_failures', '{}');
        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            return 0;
        }

        return (int) ($decoded[$peerUrl] ?? 0);
    }//end getPeerFailures()

    /**
     * Record a peer's failure count (0 = healthy/clears the streak).
     *
     * @param string $peerUrl  The peer base URL.
     * @param int    $failures The new consecutive-failure count.
     *
     * @return void
     *
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function setPeerFailures(string $peerUrl, int $failures): void
    {
        $raw     = $this->appConfig->getValueString(Application::APP_ID, 'federation_peer_failures', '{}');
        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            $decoded = [];
        }

        if ($failures <= 0) {
            unset($decoded[$peerUrl]);
        } else {
            $decoded[$peerUrl] = $failures;
        }

        $this->appConfig->setValueString(Application::APP_ID, 'federation_peer_failures', json_encode($decoded));
    }//end setPeerFailures()

    /**
     * The config-gated local-federation host allowlist (comma-separated).
     *
     * Mirrors `opencatalogi/local_federation_hosts`: private/loopback peer
     * hosts are blocked by the SSRF guard unless listed here (empty by default).
     *
     * @return array<int,string> The allowlisted hosts.
     *
     * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    public function getLocalFederationHosts(): array
    {
        $raw = $this->appConfig->getValueString(Application::APP_ID, 'local_federation_hosts', '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }//end getLocalFederationHosts()
}//end class
