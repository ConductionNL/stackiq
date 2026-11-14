<?php

/**
 * Unit tests for CatalogueLocator.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
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

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Schema ids resolved from the AMEF JSON config, the result cache and the
 * shared organisation projection that answers nothing.
 *
 * @covers \OCA\Stackiq\Service\Settings\CatalogueLocator
 * @uses   \OCA\Stackiq\Service\Settings\VoorzieningenConfigNormalizer
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class CatalogueLocatorAmefSchemaTest extends TestCase {

	/**
	 * Stored app config values by key.
	 *
	 * @var array<string,string>
	 */
	private array $store = [];

	/**
	 * Number of reads per config key.
	 *
	 * @var array<string,int>
	 */
	private array $reads = [];

	/**
	 * Installed apps.
	 *
	 * @var array<int,string>
	 */
	private array $installed = [];

	/**
	 * Services the container holds by id.
	 *
	 * @var array<string,object>
	 */
	private array $services = [];

	/**
	 * The locator under test over in-memory boundaries.
	 *
	 * @return CatalogueLocator
	 */
	private function locator(): CatalogueLocator {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->reads[$key] = (($this->reads[$key] ?? 0) + 1);
				return $this->store[$key] ?? $default;
			}
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturnCallback(fn (): array => $this->installed);
		$appManager->method('getAppVersion')->willReturn('1.0.0');

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if (isset($this->services[$id]) === false) {
					throw new \RuntimeException(message: 'no '.$id);
				}

				return $this->services[$id];
			}
		);

		return new CatalogueLocator(
			config: $config,
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end locator()

	/**
	 * A schema id in the AMEF JSON config wins, also over the voorzieningen organisation schema.
	 *
	 * @return void
	 */
	public function testAmefJsonConfigProvidesSchemaIds(): void {
		$this->store = [
			'amef_config' => json_encode(value: ['element_schema' => '12', 'relation_schema' => 13, 'organization_schema' => 7]),
			'voorzieningen_config' => json_encode(value: ['register' => '2', 'organisatie_schema' => '99']),
		];
		$locator = $this->locator();

		$this->assertSame(expected: 12, actual: $locator->getSchemaIdForObjectType(objectType: 'element'));
		$this->assertSame(expected: 13, actual: $locator->getSchemaIdForObjectType(objectType: 'relationship'));
		$this->assertSame(expected: 7, actual: $locator->getSchemaIdForObjectType(objectType: 'organization'));
	}//end testAmefJsonConfigProvidesSchemaIds()

	/**
	 * An empty AMEF value or an unmapped type falls through to the legacy per-type key.
	 *
	 * @return void
	 */
	public function testEmptyOrUnmappedAmefEntriesFallThrough(): void {
		$this->store = [
			'amef_config' => json_encode(value: ['view_schema' => '', 'property_schema' => '40']),
			'view_schema' => '21',
			'property_schema' => '22',
		];
		$locator = $this->locator();

		$this->assertSame(expected: 21, actual: $locator->getSchemaIdForObjectType(objectType: 'view'));
		$this->assertSame(expected: 22, actual: $locator->getSchemaIdForObjectType(objectType: 'property'));
		$this->assertNull(actual: $locator->getSchemaIdForObjectType(objectType: 'model'));
	}//end testEmptyOrUnmappedAmefEntriesFallThrough()

	/**
	 * A non-object AMEF config is ignored.
	 *
	 * @return void
	 */
	public function testMalformedAmefConfigIsIgnored(): void {
		$this->store = ['amef_config' => '"just a string"', 'element_schema' => '5'];

		$this->assertSame(expected: 5, actual: $this->locator()->getSchemaIdForObjectType(objectType: 'element'));
	}//end testMalformedAmefConfigIsIgnored()

	/**
	 * A resolved id, and a null result too, is cached: the config is read once per type.
	 *
	 * @return void
	 */
	public function testResultsAreCachedPerType(): void {
		$this->store = ['amef_config' => json_encode(value: ['model_schema' => '3'])];
		$locator     = $this->locator();

		$this->assertSame(expected: 3, actual: $locator->getSchemaIdForObjectType(objectType: 'model'));
		$this->store['amef_config'] = json_encode(value: ['model_schema' => '4']);
		$this->assertSame(expected: 3, actual: $locator->getSchemaIdForObjectType(objectType: 'model'));
		$this->assertNull(actual: $locator->getSchemaIdForObjectType(objectType: 'nothing'));
		$this->assertNull(actual: $locator->getSchemaIdForObjectType(objectType: 'nothing'));
		$this->assertSame(expected: 2, actual: $this->reads['amef_config']);
		$this->assertSame(expected: 1, actual: $this->reads['nothing_schema']);
	}//end testResultsAreCachedPerType()

	/**
	 * When OpenRegister has no shared organisation projection the organisation schema stays null.
	 *
	 * @return void
	 */
	public function testMissingSharedOrganisationProjectionLeavesNull(): void {
		$this->installed = ['openregister'];
		$this->services  = [
			'OCA\\OpenRegister\\Db\\SchemaMapper' => new class {
				/**
				 * The schema lookups received.
				 *
				 * @var array<int,string>
				 */
				public array $lookups = [];

				/**
				 * No schema exists.
				 *
				 * @param string $id The schema slug.
				 *
				 * @return null
				 */
				public function find(string $id) {
					$this->lookups[] = $id;
					return null;
				}
			},
		];

		$this->assertNull(actual: $this->locator()->getSchemaIdForObjectType(objectType: 'organization'));
		$this->assertSame(expected: ['nc-organisation'], actual: $this->services['OCA\\OpenRegister\\Db\\SchemaMapper']->lookups);
	}//end testMissingSharedOrganisationProjectionLeavesNull()
}//end class
