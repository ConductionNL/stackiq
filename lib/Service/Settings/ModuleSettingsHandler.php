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

		if (isset($data['enabled']) === true) {
			$enabledStr = 'false';
			if ($data['enabled'] === true) {
				$enabledStr = 'true';
			}

			$this->config->setValueString(self::APP_NAME, 'amef_enabled', $enabledStr);
		}

		if (isset($data['version']) === true) {
			$this->config->setValueString(self::APP_NAME, 'amef_version', (string)$data['version']);
		}

		if (isset($data['exportPath']) === true) {
			$this->config->setValueString(self::APP_NAME, 'amef_export_path', (string)$data['exportPath']);
		}

		if (isset($data['includeModules']) === true) {
			$includeStr = 'false';
			if ($data['includeModules'] === true) {
				$includeStr = 'true';
			}

			$this->config->setValueString(self::APP_NAME, 'amef_include_modules', $includeStr);
		}

		$this->logger->info('ModuleSettingsHandler: Updated AMEF configuration', ['data' => $data]);

		return $this->getAmefConfig();
	}//end setAmefConfig()

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
