<?php

/**
 * Reads the AMEF (ArchiMate) register and schema configuration.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\ArchiMate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\ArchiMate;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads the AMEF configuration: the stored JSON, or the legacy per-id app config values.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
final class AmefConfigReader {
	/**
	 * The legacy app config key of each configuration id, in read order.
	 */
	private const LEGACY_KEYS = [
		'register_id' => 'amef_register',
		'model_schema_id' => 'amef_model_schema',
		'elements_schema' => 'amef_elements_schema',
		'relationships_schema' => 'amef_relationships_schema',
		'views_schema' => 'amef_views_schema',
		'organizations_schema' => 'amef_organizations_schema',
		'folders_schema' => 'amef_folders_schema',
		'property_definitions_schema' => 'amef_property_definitions_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $config The app configuration holding the AMEF ids.
	 * @param LoggerInterface $logger Logs the read, a missing id and a failed read.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The AMEF configuration.
	 *
	 * @return array<string, mixed> The configuration, or `{success: false, error}` when the read fails.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function read(): array {
		$this->logger->info('Getting AMEF configuration');

		try {
			// Get configuration from app config using the correct method.
			$config = $this->config->getValueString('stackiq', 'amef_config', '{}');
			$decoded = json_decode($config, true);

			if (is_array($decoded) === false) {
				// Fallback to individual config values for backward compatibility.
				$decoded = $this->legacyIds();
			}

			return $decoded;
		} catch (\Exception $e) {
			$this->logger->error('Failed to get AMEF configuration', ['error' => $e->getMessage()]);

			return ['success' => false, 'error' => $e->getMessage()];
		}//end try
	}//end read()

	/**
	 * The ids stored as individual legacy app config values.
	 *
	 * Every id is read through resolveConfiguredId(), which guards the empty
	 * default at the point of the read and omits the key entirely when it is
	 * unset — so `?? null` downstream yields null, which is what the consumers
	 * already check for.
	 *
	 * @return array<string, string> The configured ids.
	 */
	private function legacyIds(): array {
		$ids = [];
		foreach (self::LEGACY_KEYS as $name => $key) {
			$ids[$name] = $this->resolveConfiguredId(key: $key);
		}

		return array_filter(
			$ids,
			static function ($value) {
				return $value !== null;
			}
		);
	}//end legacyIds()

	/**
	 * Read a configured id, failing closed on the empty default.
	 *
	 * The legacy fallback used to read every id with `''` as its default, so
	 * an unconfigured instance produced a config array full of empty STRINGS.
	 * Its consumers guard with `=== null` — `ViewService::getViews()` and
	 * `getView()` throw only when a value `=== null` — and `'' === null` is
	 * false. Today nothing reaches a query only because that fallback writes
	 * PLURAL key names (`views_schema`) while every consumer reads SINGULAR
	 * ones (`view_schema`), so the lookups miss and fall back to `null`. That
	 * is an accident of naming, not a defence: adding the singular keys — the
	 * obvious "cleanup" — would send `register => ''` straight into
	 * `searchObjects()` as an UNPINNED query, and an unpinned query returns
	 * rows, which reads exactly like a correct result.
	 *
	 * Returning null instead of `''` makes `?? null` downstream yield null,
	 * which is what every consumer already checks for, and the warning names
	 * the missing key so a misconfigured import stops reporting "0 objects"
	 * with no explanation.
	 *
	 * @param string $key The app-config key holding the id.
	 *
	 * @return string|null The configured id, or null when it is unset.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	private function resolveConfiguredId(string $key): ?string {
		$value = $this->config->getValueString('stackiq', $key, '');
		if (trim($value) === '') {
			$this->logger->warning(
				'ArchiMate configuration is incomplete — this id is not configured, so it is omitted rather than passed on as an empty string',
				['key' => $key]
			);

			return null;
		}

		return $value;
	}//end resolveConfiguredId()
}//end class
