<?php

/**
 * Unit tests for locating the catalogue in OpenRegister: the OpenRegister
 * service accessors, the voorzieningen register lookup, the id caches and
 * the voorzieningen and AMEF register configuration.
 *
 * The schema and register id resolution per object type is pinned by
 * SettingsServiceCatalogTypeResolutionTest, SettingsServiceSharedOrganisationTest
 * and SettingsServiceResolverWiringTest.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the catalogue location methods.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class CatalogueLocatorTest extends TestCase {

	/**
	 * The in-memory app config store.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Every write, in order, as [key, value].
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $writes = [];

	/**
	 * The installed apps.
	 *
	 * @var array<int, string>
	 */
	private array $installed = ['openregister'];

	/**
	 * The installed OpenRegister version.
	 *
	 * @var string
	 */
	private string $version = '0.2.0';

	/**
	 * What the container answers per id; anything else throws.
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * Every log call, in order, as [level, message].
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $logs = [];

	/**
	 * Installed and version checks.
	 *
	 * @return void
	 */
	public function testOpenRegisterInstalledAndVersion(): void {
		$service = $this->service();
		$this->assertTrue($service->isOpenRegisterInstalled());
		$this->assertTrue($service->isOpenRegisterInstalled(null));
		$this->assertFalse($service->isOpenRegisterInstalled('9.0.0'));

		$this->version = '0.1.6';
		$this->assertFalse($service->isOpenRegisterInstalled());
		$this->assertTrue($service->isOpenRegisterInstalled('0.1.6'));

		$this->installed = [];
		$this->assertFalse($service->isOpenRegisterInstalled(null));
		$this->assertTrue($service->isOpenRegisterEnabled());
	}//end testOpenRegisterInstalledAndVersion()

	/**
	 * The service accessors answer the container's service, or throw without OpenRegister.
	 *
	 * @return void
	 */
	public function testTheServiceAccessors(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$this->services = ['OCA\OpenRegister\Service\ObjectService' => $objectService];
		$service = $this->service();

		$this->assertSame($objectService, $service->getObjectService());

		$this->installed = ['files'];
		$messages = [];
		foreach (['getObjectService', 'getRegisterService', 'getConfigurationService'] as $method) {
			try {
				$service->$method();
			} catch (\RuntimeException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame(
			[
				'OpenRegister service is not available.',
				'OpenRegister RegisterService is not available.',
				'Configuration service is not available.',
			],
			$messages
		);
		$this->assertNull($service->getRegisterResolverService());
	}//end testTheServiceAccessors()

	/**
	 * The voorzieningen register: organisatie key, then contactpersoon key,
	 * then the organisation register, else null with a warning.
	 *
	 * @return void
	 */
	public function testTheVoorzieningenRegisterFallsBackInOrder(): void {
		$this->installed = [];
		$this->store = [
			'voorzieningen_organisatie_register' => '4',
			'voorzieningen_contactpersoon_register' => '5',
			'organization_register' => '6',
		];
		$this->assertSame(4, $this->service()->getVoorzieningenRegisterId());

		unset($this->store['voorzieningen_organisatie_register']);
		$this->assertSame(5, $this->service()->getVoorzieningenRegisterId());

		unset($this->store['voorzieningen_contactpersoon_register']);
		$this->assertSame(6, $this->service()->getVoorzieningenRegisterId());

		$this->logs = [];
		$this->store = [];
		$this->assertNull($this->service()->getVoorzieningenRegisterId());
		$this->assertSame(['warning', 'SettingsService: No register ID found for voorzieningen'], end($this->logs));
	}//end testTheVoorzieningenRegisterFallsBackInOrder()

	/**
	 * Ids are cached until the cache is cleared, and a config write clears it.
	 *
	 * @return void
	 */
	public function testIdsAreCachedUntilCleared(): void {
		$this->installed = [];
		$this->store = ['widget_register' => '7', 'widget_schema' => '8'];
		$service = $this->service();
		$this->assertSame(7, $service->getRegisterIdForObjectType('widget'));
		$this->assertSame(8, $service->getSchemaIdForObjectType('widget'));

		$this->store = ['widget_register' => '17', 'widget_schema' => '18'];
		$this->assertSame(7, $service->getRegisterIdForObjectType('widget'));
		$this->assertSame(8, $service->getSchemaIdForObjectType('widget'));

		$service->clearConfigurationCache();
		$this->assertSame(17, $service->getRegisterIdForObjectType('widget'));
		$this->assertSame(18, $service->getSchemaIdForObjectType('widget'));

		$this->store['widget_schema'] = '28';
		$service->setVoorzieningenConfig([]);
		$this->assertSame(28, $service->getSchemaIdForObjectType('widget'));

		$this->store['widget_schema'] = '38';
		$service->setAmefConfig(['register' => '']);
		$this->assertSame(38, $service->getSchemaIdForObjectType('widget'));

		$this->assertContains(['info', 'SettingsService: Configuration cache cleared'], $this->logs);
	}//end testIdsAreCachedUntilCleared()

	/**
	 * The AMEF config: ArchiMateService's own answer, else the stored JSON,
	 * else the legacy keys; a write stores pretty JSON.
	 *
	 * @return void
	 */
	public function testTheAmefConfig(): void {
		$this->services = [
			\OCA\Stackiq\Service\ArchiMateService::class => new class {
				/**
				 * @return array
				 */
				private function getAmefConfig(): array {
					return ['from' => 'archimate'];
				}
			},
		];
		$this->assertSame(['from' => 'archimate'], $this->service()->getAmefConfig());

		$this->services = [];
		$this->store = ['amef_config' => '{"register":"3"}'];
		$this->assertSame(['register' => '3'], $this->service()->getAmefConfig());

		$this->store = ['amef_config' => 'x', 'amef_register_id' => '9', 'amef_views_schema' => '10'];
		$this->assertSame(
			[
				'register_id' => '9',
				'organizations_schema' => '',
				'elements_schema' => '',
				'relationships_schema' => '',
				'views_schema' => '10',
				'models_schema' => '',
			],
			$this->service()->getAmefConfig()
		);
		$this->assertContains(['error', 'SettingsService: Failed to get AMEF config from ArchiMateService'], $this->logs);

		$this->writes = [];
		$this->service()->setAmefConfig(['register' => '3', 'element_schema' => '4']);
		$this->assertSame([['amef_config', json_encode(['register' => '3', 'element_schema' => '4'], JSON_PRETTY_PRINT)]], $this->writes);
	}//end testTheAmefConfig()

	/**
	 * The voorzieningen config reads the JSON, or the legacy keys, and the
	 * schema key of an object type follows the legacy key map.
	 *
	 * @return void
	 */
	public function testTheVoorzieningenConfigAndSchemaKey(): void {
		$this->store = ['voorzieningen_config' => 'x', 'voorzieningen_register' => '2', 'voorzieningen_organisatie_schema' => '3'];
		$config = $this->service()->getVoorzieningenConfig();
		$this->assertSame('2', (string) $config['register']);
		$this->assertSame('3', (string) $config['organisatie_schema']);

		$service = $this->service();
		$this->assertSame('beoordeeling_schema', $service->voorzieningenSchemaKey('software-review'));
		$this->assertSame('suite_schema', $service->voorzieningenSchemaKey('suite'));
		$this->assertSame('contactpersoon_schema', SettingsService::LEGACY_SCHEMA_KEY['contactPerson']);
	}//end testTheVoorzieningenConfigAndSchemaKey()

	/**
	 * Build the service over the in-memory doubles.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('stackiq', $app);
				$this->writes[] = [$key, $value];
				return true;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(fn (string $app): bool => in_array($app, $this->installed, true));
		$appManager->method('getInstalledApps')->willReturnCallback(fn (): array => $this->installed);
		$appManager->method('getAppVersion')->willReturnCallback(fn (): string => $this->version);
		$appManager->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => $app === 'openregister');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if (array_key_exists($id, $this->services) === false) {
					throw new \RuntimeException('no ' . $id);
				}

				return $this->services[$id];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logs[] = [$level, $message];
				}
			);
		}

		return new SettingsService(
			config: $config,
			request: $this->createMock(IRequest::class),
			container: $container,
			appManager: $appManager,
			logger: $logger,
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class)
		);
	}//end service()
}//end class
