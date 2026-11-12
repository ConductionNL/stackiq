<?php

/**
 * Module Settings Handler for Stackiq
 *
 * Extracted from SettingsService to reduce ExcessiveClassLength and TooManyMethods.
 * Handles module-domain configuration operations.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Settings;

use InvalidArgumentException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Handles module-domain settings: AMEF configuration and module compliance toggles.
 *
 * SettingsService delegates all module-config methods to this handler, keeping its
 * own class below ExcessiveClassLength and TooManyMethods thresholds.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */
class ModuleSettingsHandler {

	/**
	 * The application name used as the config namespace.
	 *
	 * @var string
	 */
	private const APP_NAME = 'stackiq';

	/**
	 * The app config key of each AMEF field, in the order they are written.
	 */
	private const AMEF_CONFIG_KEYS = [
		'enabled' => 'amef_enabled',
		'version' => 'amef_version',
		'exportPath' => 'amef_export_path',
		'includeModules' => 'amef_include_modules',
	];

	/**
	 * The AMEF fields stored as a 'true'/'false' flag.
	 */
	private const AMEF_FLAG_FIELDS = [
		'enabled',
		'includeModules',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config The application configuration service.
	 * @param LoggerInterface $logger Logger instance.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-1
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the AMEF (Applicatie Module Export Format) configuration.
	 *
	 * @return array<string,mixed> AMEF configuration.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-1
	 */
	public function getAmefConfig(): array {
		return [
			'enabled' => $this->config->getValueString(self::APP_NAME, 'amef_enabled', 'true') === 'true',
			'version' => $this->config->getValueString(self::APP_NAME, 'amef_version', '1.0'),
			'exportPath' => $this->config->getValueString(self::APP_NAME, 'amef_export_path', ''),
			'includeModules' => $this->config->getValueString(self::APP_NAME, 'amef_include_modules', 'true') === 'true',
		];

	}//end getAmefConfig()

	/**
	 * Update the AMEF configuration from a data array.
	 *
	 * @param array<string,mixed> $data AMEF config fields to update.
	 *
	 * @return array<string,mixed> The updated AMEF configuration.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-1
	 */
	public function setAmefConfig(array $data): array {
		$this->validateModuleConfig(data: $data);

		foreach (self::AMEF_CONFIG_KEYS as $field => $key) {
			if (isset($data[$field]) === true) {
				$this->config->setValueString(self::APP_NAME, $key, $this->amefConfigValue(field: $field, value: $data[$field]));
			}
		}

		$this->logger->info('ModuleSettingsHandler: Updated AMEF configuration', ['data' => $data]);

		return $this->getAmefConfig();
	}//end setAmefConfig()

	/**
	 * The stored string of one AMEF field: a flag is 'true' only for boolean true.
	 *
	 * @param string $field The AMEF field.
	 * @param mixed $value The submitted value.
	 *
	 * @return string The config value.
	 */
	private function amefConfigValue(string $field, mixed $value): string {
		if (in_array($field, self::AMEF_FLAG_FIELDS, true) === false) {
			return (string)$value;
		}

		if ($value === true) {
			return 'true';
		}

		return 'false';
	}//end amefConfigValue()

	/**
	 * Validate module configuration data.
	 *
	 * Guard clause: throws when a version value is in an unsupported format.
	 *
	 * @param array<string,mixed> $data Module configuration data.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the configuration contains invalid values.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-1
	 */
	private function validateModuleConfig(array $data): void {
		if (isset($data['version']) === false) {
			return;
		}

		$version = (string)$data['version'];
		if (preg_match('/^\d+\.\d+(\.\d+)?$/', $version) !== 1) {
			throw new InvalidArgumentException(
				sprintf('Invalid AMEF version format "%s". Expected: major.minor[.patch]', $version)
			);
		}

	}//end validateModuleConfig()
}//end class
