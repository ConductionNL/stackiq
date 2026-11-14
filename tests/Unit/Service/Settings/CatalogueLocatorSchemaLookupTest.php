<?php

/**
 * Unit tests for the CatalogueLocator branches the other locator tests leave
 * out: schema ids from the AMEF JSON config, the contact person schema, the
 * resolver failing over to the legacy keys, a missing shared organisation
 * projection, the AMEF register for organisations and the OpenRegister
 * service accessors.
 *
 * @category  Tests
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

use OCA\OpenRegister\Service\ConfigLookup;
use OCA\OpenRegister\Service\RegisterResolverService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the remaining schema and register lookup branches.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Stackiq\Service\Settings\CatalogueLocator
 * @uses   \OCA\Stackiq\Service\Settings\VoorzieningenConfigNormalizer
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class CatalogueLocatorSchemaLookupTest extends TestCase {

	/**
	 * The in-memory app config store.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * The installed apps.
	 *
	 * @var array<int, string>
	 */
	private array $installed = [];

	/**
	 * What the container answers per id; anything else throws.
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * Every container id asked for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * A schema id comes from the AMEF JSON config under that object type's AMEF key.
	 *
	 * @return void
	 */
	public function testSchemaIdsComeFromTheAmefJsonConfig(): void {
		$this->store = [
			'amef_config' => json_encode(['element_schema' => '12', 'relation_schema' => '13', 'view_schema' => '14', 'property_definition_schema' => 15]),
		];
		$service = $this->service();

		$this->assertSame(12, $service->getSchemaIdForObjectType('element'));
		$this->assertSame(13, $service->getSchemaIdForObjectType('relationship'));
		$this->assertSame(14, $service->getSchemaIdForObjectType('view'));
		$this->assertSame(15, $service->getSchemaIdForObjectType('property_definition'));
	}//end testSchemaIdsComeFromTheAmefJsonConfig()

	/**
	 * An empty AMEF entry, an unmapped type or malformed JSON falls through to the generic key.
	 *
	 * @return void
	 */
	public function testAnEmptyOrUnusableAmefEntryFallsThroughToTheGenericKey(): void {
		$this->store = ['amef_config' => json_encode(['model_schema' => '', 'relationship_schema' => '9']), 'model_schema' => '44', 'relationship_schema' => '45'];
		$service = $this->service();
		$this->assertSame(44, $service->getSchemaIdForObjectType('model'));
		// The AMEF key of a relationship is relation_schema, so relationship_schema in the JSON is not read.
		$this->assertSame(45, $service->getSchemaIdForObjectType('relationship'));

		$this->store = ['amef_config' => 'not json', 'model_schema' => '46'];
		$this->assertSame(46, $this->service()->getSchemaIdForObjectType('model'));

		$this->store = ['amef_config' => '{}'];
		$this->assertNull($this->service()->getSchemaIdForObjectType('model'));
	}//end testAnEmptyOrUnusableAmefEntryFallsThroughToTheGenericKey()

	/**
	 * The contact person schema comes from the voorzieningen config, else the generic key.
	 *
	 * @return void
	 */
	public function testTheContactPersonSchemaComesFromTheVoorzieningenConfig(): void {
		$this->store = ['voorzieningen_config' => json_encode(['contactpersoon_schema' => '21']), 'contactPerson_schema' => '99'];
		$this->assertSame(21, $this->service()->getSchemaIdForObjectType('contactPerson'));

		$this->store = ['voorzieningen_config' => json_encode(['contactpersoon_schema' => '']), 'contactPerson_schema' => '99'];
		$this->assertSame(99, $this->service()->getSchemaIdForObjectType('contactPerson'));
	}//end testTheContactPersonSchemaComesFromTheVoorzieningenConfig()

	/**
	 * A resolver that throws falls back to the legacy keys, for schemas and registers alike.
	 *
	 * @return void
	 */
	public function testAFailingResolverFallsBackToTheLegacyKeys(): void {
		$this->installed = ['openregister'];
		$resolver = $this->createMock(RegisterResolverService::class);
		$resolver->expects($this->exactly(2))
			->method('lookupId')
			->with($this->isInstanceOf(ConfigLookup::class))
			->willThrowException(new RuntimeException('missing'));
		$this->services = ['OCA\OpenRegister\Service\RegisterResolverService' => $resolver];
		$this->store = ['widget_schema' => '8', 'widget_register' => '7'];
		$service = $this->service();

		$this->assertSame(8, $service->getSchemaIdForObjectType('widget'));
		$this->assertSame(7, $service->getRegisterIdForObjectType('widget'));
	}//end testAFailingResolverFallsBackToTheLegacyKeys()

	/**
	 * When the projection lookup answers no schema, the organisation has no schema id rather than an error.
	 *
	 * @return void
	 */
	public function testAnAbsentProjectionSchemaLeavesTheOrganisationUnresolved(): void {
		$this->installed = ['openregister'];
		$this->services = [
			'OCA\\OpenRegister\\Db\\SchemaMapper' => new class {
				/**
				 * No such schema.
				 *
				 * @param string $slug The slug.
				 *
				 * @return null
				 */
				public function find(string $slug) {
					return null;
				}//end find()
			},
		];

		$this->assertNull($this->service()->getSchemaIdForObjectType('organization'));
		$this->assertContains('OCA\\OpenRegister\\Db\\SchemaMapper', $this->asked);
	}//end testAnAbsentProjectionSchemaLeavesTheOrganisationUnresolved()

	/**
	 * The AMEF register wins for organisations; other voorzieningen types use the voorzieningen register.
	 *
	 * @return void
	 */
	public function testTheAmefRegisterWinsForOrganisations(): void {
		$this->store = [
			'amef_config' => json_encode(['register' => '3']),
			'voorzieningen_config' => json_encode(['register' => '5']),
		];
		$service = $this->service();

		$this->assertSame(3, $service->getRegisterIdForObjectType('organization'));
		$this->assertSame(5, $service->getRegisterIdForObjectType('contactPerson'));

		$this->store['amef_config'] = json_encode(['register' => '']);
		$this->assertSame(5, $this->service()->getRegisterIdForObjectType('organization'));
	}//end testTheAmefRegisterWinsForOrganisations()

	/**
	 * The register and configuration services come from the container while OpenRegister is installed.
	 *
	 * @return void
	 */
	public function testTheRegisterAndConfigurationServicesComeFromTheContainer(): void {
		$this->installed = ['openregister'];
		$registerService = $this->createMock(\OCA\OpenRegister\Service\RegisterService::class);
		$configurationService = $this->createMock(\OCA\OpenRegister\Service\ConfigurationService::class);
		$this->services = [
			'OCA\OpenRegister\Service\RegisterService' => $registerService,
			'OCA\OpenRegister\Service\ConfigurationService' => $configurationService,
		];
		$service = $this->service();

		$this->assertSame($registerService, $service->getRegisterService());
		$this->assertSame($configurationService, $service->getConfigurationService());
	}//end testTheRegisterAndConfigurationServicesComeFromTheContainer()

	/**
	 * The resolver is null when OpenRegister is absent or the container cannot build it.
	 *
	 * @return void
	 */
	public function testTheResolverIsNullWhenUnavailable(): void {
		$this->assertNull($this->service()->getRegisterResolverService());

		$this->installed = ['openregister'];
		$this->assertNull($this->service()->getRegisterResolverService());
		$this->assertSame(['OCA\OpenRegister\Service\RegisterResolverService'], $this->asked);
	}//end testTheResolverIsNullWhenUnavailable()

	/**
	 * Build the locator over the in-memory doubles.
	 *
	 * @return CatalogueLocator
	 */
	private function service(): CatalogueLocator {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(fn (string $app): bool => in_array($app, $this->installed, true));
		$appManager->method('getInstalledApps')->willReturnCallback(fn (): array => $this->installed);
		$appManager->method('getAppVersion')->willReturn('0.2.0');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				$this->asked[] = $id;
				if (array_key_exists($id, $this->services) === false) {
					throw new RuntimeException('no ' . $id);
				}

				return $this->services[$id];
			}
		);

		return new CatalogueLocator(
			config: $config,
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()
}//end class
