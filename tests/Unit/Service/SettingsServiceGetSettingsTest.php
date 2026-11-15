<?php

/**
 * Unit tests for SettingsService::getSettings(), updateSettings() and getAllSettings().
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The settings payload the admin page loads first: register list with
 * resolved schemas, per-object-type configuration defaults, and the
 * lightweight "all settings" envelope.
 */
final class SettingsServiceGetSettingsTest extends TestCase {

	/**
	 * Backing key/value store of the IAppConfig fake.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Locator mock, configured per test.
	 *
	 * @var CatalogueLocator&MockObject
	 */
	private CatalogueLocator $catalogue;

	/**
	 * Container mock, configured per test.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * Logger mock, configured per test.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Create the shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->catalogue = $this->createMock(CatalogueLocator::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the service over an in-memory IAppConfig.
	 *
	 * @param IAppManager|null $appManager Optional app manager.
	 *
	 * @return SettingsService
	 */
	private function service(?IAppManager $appManager=null): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default=''): string {
				return $this->store[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		return new SettingsService(
			catalogue: $this->catalogue,
			config: $config,
			request: $this->createMock(IRequest::class),
			roleGroupSettings: $this->createMock(RoleGroupSettings::class),
			container: $this->container,
			appManager: $appManager ?? $this->createMock(IAppManager::class),
			logger: $this->logger,
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class),
			emailSettings: $this->createMock(EmailSettings::class)
		);

	}//end service()

	/**
	 * A register entity double that serialises to the given array.
	 *
	 * @param array $data The serialised register.
	 *
	 * @return object
	 */
	private function entity(array $data): object {
		return new class($data) implements \JsonSerializable {

			/**
			 * @param array $data The serialised entity.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * @return int|null
			 */
			public function getId(): ?int {
				return $this->data['id'] ?? null;
			}

			/**
			 * @return array
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};

	}//end entity()

	/**
	 * Wire OpenRegister as available with the given registers.
	 *
	 * @param array $registers Register entities or arrays findAll() returns.
	 *
	 * @return void
	 */
	private function openRegisterWith(array $registers): void {
		$this->catalogue->method('getObjectService')->willReturn($this->createMock(ObjectServiceInterface::class));
		$registerService = $this->createMock(RegisterService::class);
		$registerService->method('findAll')->willReturn($registers);
		$this->catalogue->method('getRegisterService')->willReturn($registerService);

	}//end openRegisterWith()

	/**
	 * Schema ids on a register are resolved through ONE batch lookup, and the
	 * properties field is stripped from every resolved schema.
	 *
	 * @return void
	 */
	public function testSchemaIdsAreResolvedInOneBatchAndLoseTheirProperties(): void {
		$this->openRegisterWith(
			[
				$this->entity(['id' => 1, 'title' => 'Voorzieningen', 'schemas' => [10, '11', 99]]),
			]
		);

		$mapper = new class([
			$this->entity(['id' => 10, 'slug' => 'module', 'properties' => ['a' => []]]),
			$this->entity(['id' => 11, 'slug' => 'suite', 'properties' => []]),
		]) {

			/**
			 * Ids passed to each findMultipleOptimized() call.
			 *
			 * @var array
			 */
			public array $requested = [];

			/**
			 * @param array $schemas Schemas to return.
			 */
			public function __construct(private array $schemas) {
			}

			/**
			 * @param array $ids Requested schema ids.
			 *
			 * @return array
			 */
			public function findMultipleOptimized(array $ids): array {
				$this->requested[] = $ids;
				return $this->schemas;
			}
		};
		$this->container->method('get')->willReturn($mapper);

		$data = $this->service()->getSettings();

		$this->assertTrue($data['openRegisters']);
		$this->assertCount(1, $mapper->requested, 'Schemas are fetched in a single batch call.');
		$this->assertSame([10, 11, 99], array_values($mapper->requested[0]));
		$schemas = $data['availableRegisters'][0]['schemas'];
		$this->assertSame(['module', 'suite'], array_column($schemas, 'slug'), 'An unknown id (99) is dropped.');
		foreach ($schemas as $schema) {
			$this->assertArrayNotHasKey('properties', $schema);
		}

	}//end testSchemaIdsAreResolvedInOneBatchAndLoseTheirProperties()

	/**
	 * A register whose schemas are already full objects keeps them, minus
	 * properties, and needs no batch lookup at all.
	 *
	 * @return void
	 */
	public function testInlineSchemaObjectsAreKeptWithoutALookup(): void {
		$this->openRegisterWith(
			[
				['id' => 2, 'schemas' => [['slug' => 'organization', 'title' => 'Org', 'properties' => ['x' => 1]]]],
			]
		);
		$this->container->expects($this->never())->method('get');

		$data = $this->service()->getSettings();

		$this->assertSame(
			[['slug' => 'organization', 'title' => 'Org']],
			$data['availableRegisters'][0]['schemas']
		);

	}//end testInlineSchemaObjectsAreKeptWithoutALookup()

	/**
	 * A failing batch lookup is logged and leaves the id-only schemas out
	 * rather than failing the whole settings call.
	 *
	 * @return void
	 */
	public function testAFailingSchemaLookupIsLoggedAndTheIdsAreDropped(): void {
		$this->openRegisterWith([['id' => 3, 'schemas' => [5]]]);
		$this->container->method('get')->willThrowException(new \Exception('mapper gone'));
		$this->logger->expects($this->once())->method('warning')
			->with('Failed to batch fetch schemas', ['error' => 'mapper gone']);

		$data = $this->service()->getSettings();

		$this->assertSame([], $data['availableRegisters'][0]['schemas']);

	}//end testAFailingSchemaLookupIsLoggedAndTheIdsAreDropped()

	/**
	 * A TypeError inside OpenRegister empties the register list but keeps
	 * openRegisters true: the service itself is there.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterTypeErrorGivesAnEmptyRegisterList(): void {
		$this->catalogue->method('getObjectService')->willReturn($this->createMock(ObjectServiceInterface::class));
		$registerService = $this->createMock(RegisterService::class);
		$registerService->method('findAll')->willThrowException(new \TypeError('bad arg'));
		$this->catalogue->method('getRegisterService')->willReturn($registerService);
		$this->logger->expects($this->once())->method('warning')
			->with('OpenRegister internal error - using empty registers list', $this->anything());

		$data = $this->service()->getSettings();

		$this->assertTrue($data['openRegisters']);
		$this->assertSame([], $data['availableRegisters']);

	}//end testAnOpenRegisterTypeErrorGivesAnEmptyRegisterList()

	/**
	 * Any other OpenRegister exception is handled the same way, under its own message.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterExceptionGivesAnEmptyRegisterList(): void {
		$this->catalogue->method('getObjectService')->willReturn($this->createMock(ObjectServiceInterface::class));
		$registerService = $this->createMock(RegisterService::class);
		$registerService->method('findAll')->willThrowException(new \Exception('db down'));
		$this->catalogue->method('getRegisterService')->willReturn($registerService);
		$this->logger->expects($this->once())->method('warning')
			->with('OpenRegister getRegisters() failed - using empty registers list', $this->anything());

		$this->assertSame([], $this->service()->getSettings()['availableRegisters']);

	}//end testAnOpenRegisterExceptionGivesAnEmptyRegisterList()

	/**
	 * Without OpenRegister the payload still carries every configuration
	 * default, read through IAppConfig, plus the catalog location.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheDefaultsAndStoredValuesAreReturned(): void {
		$this->catalogue->method('getObjectService')->willThrowException(new \RuntimeException('not installed'));
		$this->logger->expects($this->once())->method('info')->with('OpenRegister service not available', $this->anything());
		$this->store['voorzieningen_module_schema'] = '42';
		$this->store['catalog_location'] = 'https://catalog.example';

		$data = $this->service()->getSettings();

		$this->assertFalse($data['openRegisters']);
		$this->assertSame([], $data['availableRegisters']);
		$this->assertSame('42', $data['configuration']['voorzieningen_module_schema']);
		$this->assertSame('openregister', $data['configuration']['amef_view_source']);
		$this->assertSame('', $data['configuration']['amef_view_register']);
		$this->assertSame('openregister', $data['configuration']['contact_source'], 'Legacy keys stay present.');
		$this->assertSame('https://catalog.example', $data['catalogLocation']);
		$this->assertContains('sbomComponent', $data['registerTypes']['voorzieningen']['objectTypes']);

	}//end testWithoutOpenRegisterTheDefaultsAndStoredValuesAreReturned()

	/**
	 * A null object service means "not available" without any log line.
	 *
	 * @return void
	 */
	public function testANullObjectServiceLeavesOpenRegistersFalse(): void {
		$this->catalogue->method('getObjectService')->willReturn(null);
		$this->catalogue->expects($this->never())->method('getRegisterService');

		$this->assertFalse($this->service()->getSettings()['openRegisters']);

	}//end testANullObjectServiceLeavesOpenRegistersFalse()

	/**
	 * updateSettings stores scalars as strings and arrays as JSON, skips an
	 * empty key, and returns what the store now holds.
	 *
	 * @return void
	 */
	public function testUpdateSettingsStoresStringsAndJsonAndSkipsEmptyKeys(): void {
		$this->logger->expects($this->once())->method('warning')
			->with('Skipping empty key in updateSettings', ['value' => 'ignored']);

		$result = $this->service()->updateSettings(
			[
				'catalog_location' => 'https://x.example',
				'limit' => 25,
				'groups' => ['admin', 'staff'],
				'' => 'ignored',
			]
		);

		$this->assertSame('https://x.example', $this->store['catalog_location']);
		$this->assertSame('25', $this->store['limit']);
		$this->assertSame('["admin","staff"]', $this->store['groups']);
		$this->assertArrayNotHasKey('', $this->store);
		$this->assertSame('25', $result['limit'], 'The result carries the value read back from the store.');

	}//end testUpdateSettingsStoresStringsAndJsonAndSkipsEmptyKeys()

	/**
	 * A failing store surfaces as a RuntimeException naming the cause.
	 *
	 * @return void
	 */
	public function testUpdateSettingsWrapsAStoreFailure(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willThrowException(new \Exception('read only'));
		$service = new SettingsService(
			catalogue: $this->catalogue,
			config: $config,
			request: $this->createMock(IRequest::class),
			roleGroupSettings: $this->createMock(RoleGroupSettings::class),
			container: $this->container,
			appManager: $this->createMock(IAppManager::class),
			logger: $this->logger,
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class),
			emailSettings: $this->createMock(EmailSettings::class)
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Failed to update settings: read only');
		$service->updateSettings(['a' => 'b']);

	}//end testUpdateSettingsWrapsAStoreFailure()

	/**
	 * getAllSettings decodes a stored AMEF config and passes the register
	 * list and version info through.
	 *
	 * @return void
	 */
	public function testGetAllSettingsUsesTheStoredAmefConfig(): void {
		$this->openRegisterWith([['id' => 7, 'schemas' => []]]);
		$configService = $this->createMock(ConfigurationService::class);
		$configService->method('getConfiguredAppVersion')->willReturn('1.0.0');
		$this->catalogue->method('getConfigurationService')->willReturn($configService);
		$this->catalogue->method('getVoorzieningenConfig')->willReturn(['register' => '7']);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.0');
		$this->store['amef_config'] = '{"register":"3","view_schema":"9"}';

		$result = $this->service($appManager)->getAllSettings();

		$this->assertSame([['id' => 7, 'schemas' => []]], $result['availableRegisters']);
		$this->assertSame(['register' => '3', 'view_schema' => '9'], $result['amefConfig']);
		$this->assertSame(['register' => '7'], $result['voorzieningenConfig']);
		$this->assertTrue($result['versionInfo']['versionsMatch']);

	}//end testGetAllSettingsUsesTheStoredAmefConfig()

	/**
	 * Without a decodable AMEF blob the individual legacy keys are used.
	 *
	 * @return void
	 */
	public function testGetAllSettingsFallsBackToTheLegacyAmefKeys(): void {
		$this->catalogue->method('getObjectService')->willReturn(null);
		$configService = $this->createMock(ConfigurationService::class);
		$this->catalogue->method('getConfigurationService')->willReturn($configService);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.0');
		$this->store['amef_config'] = 'not json';
		$this->store['amef_register_id'] = '5';
		$this->store['amef_views_schema'] = '12';

		$amef = $this->service($appManager)->getAllSettings()['amefConfig'];

		$this->assertSame('5', $amef['register']);
		$this->assertSame('12', $amef['view_schema']);
		$this->assertSame('', $amef['model_schema']);

	}//end testGetAllSettingsFallsBackToTheLegacyAmefKeys()

	/**
	 * A failure anywhere turns into a success:false envelope, not an exception.
	 *
	 * @return void
	 */
	public function testGetAllSettingsReportsAFailure(): void {
		$this->catalogue->method('getObjectService')->willReturn(null);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willThrowException(new \Exception('no version'));
		$this->logger->expects($this->atLeastOnce())->method('error');

		$result = $this->service($appManager)->getAllSettings();

		$this->assertFalse($result['success']);
		$this->assertSame('Failed to get version information: no version', $result['error']);

	}//end testGetAllSettingsReportsAFailure()
}//end class
