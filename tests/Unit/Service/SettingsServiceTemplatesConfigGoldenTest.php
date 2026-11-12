<?php

/**
 * Golden test for the SettingsService e-mail template defaults and the
 * voorzieningen config normalisation.
 *
 * Records what getDefaultEmailTemplate(), getEmailTemplateVariables() and
 * getVoorzieningenConfig() return, and what setVoorzieningenConfig() stores,
 * for known, unknown and legacy input. Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Golden cases for the template defaults and the config normalisation.
 *
 * @covers \OCA\Stackiq\Service\SettingsService
 * @uses   \OCA\Stackiq\Service\Settings\EmailTemplateDefaults
 * @uses   \OCA\Stackiq\Service\Settings\VoorzieningenConfigNormalizer
 * @uses   \OCA\Stackiq\Service\Settings\CatalogueLocator
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class SettingsServiceTemplatesConfigGoldenTest extends TestCase {

	/**
	 * Templates and variables for every shipped name and an unknown one.
	 *
	 * @return void
	 */
	public function testTemplates(): void {
		$service = $this->service(stored: []);
		$out     = [];
		foreach (['organization_registration', 'organization_activation', 'user_creation', 'welcome', ''] as $name) {
			$out[($name === '' ? '(empty)' : $name)] = [
				'template' => $service->getDefaultEmailTemplate($name),
				'variables' => $service->getEmailTemplateVariables($name),
			];
		}

		$this->check(key: 'templates', value: $out);

	}//end testTemplates()

	/**
	 * The voorzieningen config read back for stored, legacy and odd input,
	 * and what setVoorzieningenConfig() writes.
	 *
	 * @return void
	 */
	public function testVoorzieningenConfig(): void {
		$inputs = [
			'empty'   => '{}',
			'full'    => '{"register":5,"organisatie_schema":7,"gebruik_schema":null,"suite_schema":"9","x_source":"a","organisatie_register":"b"}',
			'legacy'  => 'not json',
			'list'    => '[1,2]',
			'strings' => '{"register":"","sector_schema":"0","maintenanceWindow_schema":12.5,"sbomComponent_schema":true}',
		];
		$out    = [];
		foreach ($inputs as $name => $json) {
			$stored = ['voorzieningen_config' => $json, 'voorzieningen_register' => '3', 'voorzieningen_organisatie_schema' => '4'];
			$out[$name] = $this->catalogue(stored: $stored)->getVoorzieningenConfig();
		}

		$written = [];
		$service = $this->catalogue(stored: [], written: $written);
		$service->setVoorzieningenConfig(['register' => 8, 'contract_schema' => 2, 'contract_source' => 'x', 'module_schema' => null]);
		$out['written'] = $written;

		$this->check(key: 'voorzieningen config', value: $out);

	}//end testVoorzieningenConfig()

	/**
	 * Build the service over an in-memory app config.
	 *
	 * @param array $stored  The stored values by key.
	 * @param array $written The writes, by reference.
	 *
	 * @return SettingsService
	 */
	private function service(array $stored, array &$written=[]): SettingsService {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default='') => ($stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$written) {
				$written[] = [$app, $key, $value];
				return true;
			}
		);

		$reflection = new \ReflectionClass(SettingsService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		$properties = [
			'config' => $config,
			'logger' => new NullLogger(),
			'appName' => 'stackiq',
			'catalogue' => new CatalogueLocator(
				config: $config,
				appManager: $this->createMock(originalClassName: IAppManager::class),
				container: $this->createMock(originalClassName: ContainerInterface::class),
				logger: new NullLogger()
			),
		];
		foreach ($properties as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		return $service;

	}//end service()

	/**
	 * Build the catalogue locator over an in-memory app config.
	 *
	 * @param array $stored  The stored values by key.
	 * @param array $written The writes, by reference.
	 *
	 * @return CatalogueLocator
	 */
	private function catalogue(array $stored, array &$written=[]): CatalogueLocator {
		$service = $this->service(stored: $stored, written: $written);

		return (new \ReflectionProperty(SettingsService::class, 'catalogue'))->getValue($service);

	}//end catalogue()

	/**
	 * Compare a case with the golden file, or record it.
	 *
	 * @param string $key   The case.
	 * @param array  $value What the case observed.
	 *
	 * @return void
	 */
	private function check(string $key, array $value): void {
		$file  = __DIR__ . '/golden/settingsServiceTemplatesConfig.json';
		$saved = [];
		if (file_exists($file) === true) {
			$saved = json_decode((string) file_get_contents($file), true);
		}

		if (getenv('RECORD_GOLDEN') !== false) {
			$saved[$key] = $value;
			file_put_contents($file, json_encode($saved, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertArrayHasKey(key: $key, array: $saved, message: 'No golden for ' . $key);
		$this->assertSame(expected: $saved[$key], actual: json_decode(json_encode($value), true));

	}//end check()

}//end class
