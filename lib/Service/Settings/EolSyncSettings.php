<?php

/**
 * EOL Sync Settings for Stackiq
 *
 * Moved out of SettingsService: the persisted configuration and last-run
 * status of the end-of-life feed sync, two IAppConfig blobs that only the
 * EOL sync reads and writes.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Settings;

use OCA\Stackiq\AppInfo\AppIdentity;
use OCP\IAppConfig;

/**
 * Reads and writes the EOL sync configuration and its last-run status.
 *
 * Not final, like the other settings handlers in this directory, so the
 * services that depend on it can be tested against a mock.
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
 */
class EolSyncSettings {

	/**
	 * The IAppConfig key backing the EOL sync configuration blob.
	 *
	 * @var string
	 */
	private const EOL_SYNC_CONFIG_KEY = 'eol_sync_config';

	/**
	 * The IAppConfig key backing the EOL sync last-run status blob.
	 *
	 * @var string
	 */
	private const EOL_SYNC_STATUS_KEY = 'eol_sync_status';

	/**
	 * The register slug the sibling Integriq `endoflife-date-source` change
	 * provisions `eol_product`/`eol_cycle` into, used as the default when no
	 * admin override is configured.
	 *
	 * Was `openconnector`. That register was renamed to `integriq` in the fleet
	 * rename and this default was left behind, so a default configuration has
	 * been resolving a register that does not answer to that name. It failed
	 * silently, because the feature is opt-in and an unresolvable register reads
	 * as "no EOL data" rather than as an error.
	 *
	 * @var string
	 */
	private const EOL_DEFAULT_REGISTER = 'integriq';

	/**
	 * The default `eol_product` schema slug (design.md Decision 5).
	 *
	 * Was `eolProduct`, renamed to snake_case in Integriq along with
	 * `eolCycle`; they were the only two camelCase slugs in that register.
	 *
	 * @var string
	 */
	private const EOL_DEFAULT_PRODUCT_SCHEMA = 'eol_product';

	/**
	 * The default `eol_cycle` schema slug (design.md Decision 5).
	 *
	 * Was `eolCycle`. See the note on the product schema above.
	 *
	 * @var string
	 */
	private const EOL_DEFAULT_CYCLE_SCHEMA = 'eol_cycle';

	/**
	 * The default scheduled-sync interval in seconds (24 hours).
	 *
	 * @var integer
	 */
	private const EOL_DEFAULT_INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config The app config holding both blobs.
	 */
	public function __construct(
		private readonly IAppConfig $config,
	) {
	}//end __construct()

	/**
	 * Get the EOL sync configuration: whether the feature is enabled, the
	 * register/schema slugs to read `eol_product`/`eol_cycle` from, and the
	 * scheduled-sync interval. Defaults match what the sibling Integriq
	 * `endoflife-date-source` change provisions (design.md Decision 5) —
	 * the feature is disabled by default until an admin opts in.
	 *
	 * @return array{enabled: bool, register: string, productSchema: string, cycleSchema: string, intervalSeconds: int}
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function getEolSyncConfig(): array {
		$configJson = $this->config->getValueString(AppIdentity::APP_ID, self::EOL_SYNC_CONFIG_KEY, '{}');
		$decoded = json_decode($configJson, true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		return [
			'enabled' => ($decoded['enabled'] ?? false) === true,
			'register' => (string)($decoded['register'] ?? self::EOL_DEFAULT_REGISTER),
			'productSchema' => (string)($decoded['productSchema'] ?? self::EOL_DEFAULT_PRODUCT_SCHEMA),
			'cycleSchema' => (string)($decoded['cycleSchema'] ?? self::EOL_DEFAULT_CYCLE_SCHEMA),
			'intervalSeconds' => (int)($decoded['intervalSeconds'] ?? self::EOL_DEFAULT_INTERVAL_SECONDS),
		];
	}//end getEolSyncConfig()

	/**
	 * Persist the EOL sync configuration. Unknown keys are ignored; missing
	 * keys keep their current value (partial updates are supported, unlike
	 * the OpenRegister object PUT semantics this config intentionally does
	 * NOT share — this is a flat IAppConfig blob, not an OR object).
	 *
	 * @param array $data The submitted configuration fields.
	 *
	 * @return array{success: bool, config: array} The persisted configuration.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function updateEolSyncConfig(array $data): array {
		$current = $this->getEolSyncConfig();

		if (array_key_exists('enabled', $data) === true) {
			$current['enabled'] = ($data['enabled'] === true || $data['enabled'] === 'true');
		}

		if (array_key_exists('register', $data) === true && trim((string)$data['register']) !== '') {
			$current['register'] = trim((string)$data['register']);
		}

		if (array_key_exists('productSchema', $data) === true && trim((string)$data['productSchema']) !== '') {
			$current['productSchema'] = trim((string)$data['productSchema']);
		}

		if (array_key_exists('cycleSchema', $data) === true && trim((string)$data['cycleSchema']) !== '') {
			$current['cycleSchema'] = trim((string)$data['cycleSchema']);
		}

		if (array_key_exists('intervalSeconds', $data) === true && (int)$data['intervalSeconds'] > 0) {
			$current['intervalSeconds'] = (int)$data['intervalSeconds'];
		}

		$this->config->setValueString(AppIdentity::APP_ID, self::EOL_SYNC_CONFIG_KEY, json_encode($current));

		return [
			'success' => true,
			'config' => $current,
		];
	}//end updateEolSyncConfig()

	/**
	 * Get the last-recorded EOL sync status: whether the feed is currently
	 * available, a reason when it is not, and the outcome counts of the most
	 * recent run. Defaults to an "unavailable / never run" status before the
	 * first run.
	 *
	 * @return array{available: bool, reason: string|null, matched: int, skipped: int, lastRunAt: string|null}
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
	 */
	public function getEolSyncStatus(): array {
		$statusJson = $this->config->getValueString(AppIdentity::APP_ID, self::EOL_SYNC_STATUS_KEY, '{}');
		$decoded = json_decode($statusJson, true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		return [
			'available' => ($decoded['available'] ?? false) === true,
			'reason' => $decoded['reason'] ?? 'not-yet-run',
			'matched' => (int)($decoded['matched'] ?? 0),
			'skipped' => (int)($decoded['skipped'] ?? 0),
			'lastRunAt' => $decoded['lastRunAt'] ?? null,
		];
	}//end getEolSyncStatus()

	/**
	 * Persist the outcome of an EOL sync run (scheduled or manual).
	 *
	 * @param array $status The status fields (`available`, `reason`, `matched`, `skipped`, `lastRunAt`).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
	 */
	public function setEolSyncStatus(array $status): void {
		$this->config->setValueString(AppIdentity::APP_ID, self::EOL_SYNC_STATUS_KEY, json_encode($status));
	}//end setEolSyncStatus()
}//end class
