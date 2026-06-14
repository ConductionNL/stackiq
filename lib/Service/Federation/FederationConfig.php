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
        return $url !== '' ? $url : self::DEFAULT_DIRECTORY_URL;
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
        $raw = $this->appConfig->getValueString(Application::APP_ID, 'federation_peers', '[]');
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
        return $value > 0 ? $value : 3600;
    }//end getSyncInterval()

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
