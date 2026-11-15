<?php

/**
 * Unit tests pinning the precedence of the CatalogueLocator schema and
 * register lookups: AMEF JSON before voorzieningen, voorzieningen before the
 * generic keys, the merged organisation schema before the AMEF organisation
 * key, and what gets cached and logged.
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

use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Pins the lookup order of schema and register ids.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Stackiq\Service\Settings\CatalogueLocator
 * @uses   \OCA\Stackiq\Service\Settings\VoorzieningenConfigNormalizer
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class CatalogueLocatorLookupOrderTest extends TestCase {

	/**
	 * The in-memory app config store.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Every log record, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * Every config key read, in order.
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * The AMEF JSON entry wins over the voorzieningen config and the generic key.
	 *
	 * @return void
	 */
	public function testTheAmefJsonEntryWinsOverEveryOtherSource(): void {
		$this->store = [
			'amef_config' => json_encode(['organization_schema' => '31']),
			'voorzieningen_config' => json_encode(['organisatie_schema' => '32']),
			'amef_organization_schema' => '33',
			'organization_schema' => '34',
		];

		$this->assertSame(31, $this->service()->getSchemaIdForObjectType('organization'));
	}//end testTheAmefJsonEntryWinsOverEveryOtherSource()

	/**
	 * The merged voorzieningen organisation schema wins over the AMEF organisation key, which is the last local resort.
	 *
	 * @return void
	 */
	public function testTheMergedOrganisationSchemaWinsOverTheAmefKey(): void {
		$this->store = [
			'voorzieningen_config' => json_encode(['organisatie_schema' => '32']),
			'amef_organization_schema' => '33',
			'organization_schema' => '34',
		];
		$this->assertSame(32, $this->service()->getSchemaIdForObjectType('organization'));

		$this->store = [
			'voorzieningen_config' => json_encode(['organisatie_schema' => '']),
			'amef_organization_schema' => '33',
			'organization_schema' => '34',
		];
		$this->assertSame(33, $this->service()->getSchemaIdForObjectType('organization'));

		// Without OpenRegister there is no projection, so the generic key is next.
		$this->store = ['organization_schema' => '34'];
		$this->assertSame(34, $this->service()->getSchemaIdForObjectType('organization'));
	}//end testTheMergedOrganisationSchemaWinsOverTheAmefKey()

	/**
	 * A mapped voorzieningen type reads its stored key before the generic `<type>_schema` key.
	 *
	 * @return void
	 */
	public function testAVoorzieningenTypeReadsItsStoredKeyFirst(): void {
		$this->store = [
			'voorzieningen_config' => json_encode(['beoordeeling_schema' => '41', 'gebruik_schema' => '']),
			'software-review_schema' => '90',
			'usage_schema' => '91',
		];
		$service = $this->service();

		$this->assertSame(41, $service->getSchemaIdForObjectType('software-review'));
		// An empty stored key casts to 0, which is a result: the generic key is not consulted.
		$this->assertSame(0, $service->getSchemaIdForObjectType('usage'));
		$this->assertNotContains('usage_schema', $this->reads);
	}//end testAVoorzieningenTypeReadsItsStoredKeyFirst()

	/**
	 * The AMEF JSON is read even for a type it does not map, and an unmapped type falls to the generic key.
	 *
	 * @return void
	 */
	public function testAnUnmappedTypeFallsToTheGenericKey(): void {
		$this->store = [
			'amef_config' => json_encode(['element_schema' => '5']),
			'voorzieningen_config' => json_encode(['widget_schema' => '6']),
			'widget_schema' => '7',
		];

		$this->assertSame(7, $this->service()->getSchemaIdForObjectType('widget'));
		$reads = array_values(array_filter($this->reads, fn (string $k): bool => $k !== 'voorzieningen_config'));
		$this->assertSame(['amef_config', 'widget_schema'], $reads);
	}//end testAnUnmappedTypeFallsToTheGenericKey()

	/**
	 * A found schema id is logged at info and cached; a miss is logged at debug and cached as null.
	 *
	 * @return void
	 */
	public function testHitsAndMissesAreLoggedAndCached(): void {
		$this->store = ['widget_schema' => '7'];
		$service = $this->service();

		$this->assertSame(7, $service->getSchemaIdForObjectType('widget'));
		$this->assertNull($service->getSchemaIdForObjectType('gadget'));

		$messages = array_map(fn (array $log): string => $log[0] . ':' . $log[1], $this->logs);
		$this->assertContains('info:SettingsService: Found schema ID and cached result', $messages);
		$this->assertContains('debug:SettingsService: No schema ID found, cached null result', $messages);

		$found = array_values(array_filter($this->logs, fn (array $log): bool => $log[1] === 'SettingsService: Found schema ID and cached result'))[0];
		$this->assertSame('widget', $found[2]['objectType']);
		$this->assertSame(7, $found[2]['schemaId']);
		$this->assertFalse($found[2]['fromCache']);
		$this->assertStringEndsWith('ms', $found[2]['lookupTime']);

		$this->store = ['widget_schema' => '8', 'gadget_schema' => '9'];
		$this->logs = [];
		$this->assertSame(7, $service->getSchemaIdForObjectType('widget'));
		$this->assertNull($service->getSchemaIdForObjectType('gadget'));
		$this->assertSame('SettingsService: Schema ID retrieved from cache', $this->logs[0][1]);
		$this->assertTrue($this->logs[0][2]['fromCache']);
		$this->assertNull($this->logs[1][2]['cachedValue']);
	}//end testHitsAndMissesAreLoggedAndCached()

	/**
	 * A catalog type with a stored schema key uses the voorzieningen register; any other type its generic key.
	 *
	 * @return void
	 */
	public function testTheRegisterFollowsTheStoredSchemaKey(): void {
		$this->store = [
			'voorzieningen_config' => json_encode(['register' => '5', 'beoordeeling_schema' => '41']),
			'software-review_register' => '90',
			'widget_register' => '91',
			'contact_register' => '92',
		];
		$service = $this->service();

		$this->assertSame(5, $service->getRegisterIdForObjectType('software-review'));
		$this->assertSame(5, $service->getRegisterIdForObjectType('contact'));
		$this->assertSame(91, $service->getRegisterIdForObjectType('widget'));
		$this->assertNull($service->getRegisterIdForObjectType('gadget'));
	}//end testTheRegisterFollowsTheStoredSchemaKey()

	/**
	 * An empty voorzieningen register falls to the generic key; register lookups are cached and logged.
	 *
	 * @return void
	 */
	public function testAnEmptyVoorzieningenRegisterFallsToTheGenericKey(): void {
		$this->store = [
			'voorzieningen_config' => json_encode(['register' => '']),
			'contactPerson_register' => '12',
		];
		$service = $this->service();

		$this->assertSame(12, $service->getRegisterIdForObjectType('contactPerson'));

		$last = $this->logs[count($this->logs) - 1];
		$this->assertSame('debug', $last[0]);
		$this->assertSame('SettingsService: Register ID looked up and cached', $last[1]);
		$this->assertSame(['objectType' => 'contactPerson', 'result' => 12, 'fromCache' => false], $last[2]);

		$this->store['contactPerson_register'] = '13';
		$this->assertSame(12, $service->getRegisterIdForObjectType('contactPerson'));
		$this->assertSame('SettingsService: Register ID retrieved from cache', $this->logs[count($this->logs) - 1][1]);
	}//end testAnEmptyVoorzieningenRegisterFallsToTheGenericKey()

	/**
	 * Build the locator over in-memory doubles, with OpenRegister absent.
	 *
	 * @return CatalogueLocator
	 */
	private function service(): CatalogueLocator {
		$this->reads = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->reads[] = $key;
				return ($this->store[$key] ?? $default);
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$appManager->method('getInstalledApps')->willReturn([]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no service'));

		$logs = &$this->logs;
		$logger = new class ($logs) extends AbstractLogger {
			/**
			 * The shared log buffer.
			 *
			 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
			 */
			private array $buffer;

			/**
			 * Bind the buffer by reference.
			 *
			 * @param array<int, array{0: string, 1: string, 2: array<string, mixed>}> $buffer The buffer.
			 */
			public function __construct(array &$buffer) {
				$this->buffer = &$buffer;
			}//end __construct()

			/**
			 * Record one log call.
			 *
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->buffer[] = [(string) $level, (string) $message, $context];
			}//end log()
		};

		return new CatalogueLocator(
			config: $config,
			appManager: $appManager,
			container: $container,
			logger: $logger
		);
	}//end service()
}//end class
