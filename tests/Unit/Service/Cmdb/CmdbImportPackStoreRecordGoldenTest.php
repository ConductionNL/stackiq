<?php

/**
 * Golden tests for the pack ids CmdbImportPackStore records and the app config it reads them from.
 *
 * Pins which recorded entries count (a string slug with a positive integer
 * id) and how the app config is resolved (given, from the container, not
 * there, the wrong type, failing, and remembered once found), so splitting
 * recordedPackIds() and appConfig() keeps every outcome.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\Cmdb
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

use OCA\Stackiq\Service\Cmdb\CmdbImportPackStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden tests for CmdbImportPackStore::recordedPackIds().
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\Cmdb
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class CmdbImportPackStoreRecordGoldenTest extends TestCase {

	/**
	 * An app config holding this recorded value.
	 *
	 * @param string $recorded The stored JSON.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(string $recorded): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use ($recorded): string {
				$this->assertSame(['stackiq', 'cmdb_import_pack_ids', ''], [$app, $key, $default]);
				return $recorded;
			}
		);
		return $config;
	}//end appConfig()

	/**
	 * Only string slugs with a positive integer id count.
	 *
	 * @return void
	 */
	public function testOnlyStringSlugsWithAPositiveIdCount(): void {
		$recorded = '{"usage":7,"zero":0,"negative":-1,"text":"8","float":9.0,"0":5,"list":[1],"ok":12}';
		$store = new CmdbImportPackStore(container: $this->createMock(ContainerInterface::class), appConfig: $this->appConfig(recorded: $recorded));

		$this->assertSame(['usage' => 7, 'ok' => 12], $store->recordedPackIds());
		foreach (['', 'null', '"text"', '5', '[3]', 'not json'] as $value) {
			$other = new CmdbImportPackStore(container: $this->createMock(ContainerInterface::class), appConfig: $this->appConfig(recorded: $value));
			$this->assertSame([], $other->recordedPackIds(), $value);
		}
	}//end testOnlyStringSlugsWithAPositiveIdCount()

	/**
	 * The app config comes from the container when not given, and is remembered once found.
	 *
	 * @return void
	 */
	public function testTheAppConfigIsResolvedFromTheContainer(): void {
		$config = $this->appConfig(recorded: '{"usage":7}');
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('has')->with(IAppConfig::class)->willReturn(true);
		$container->expects($this->once())->method('get')->with(IAppConfig::class)->willReturn($config);
		$store = new CmdbImportPackStore(container: $container);

		$this->assertSame(['usage' => 7], $store->recordedPackIds());
		$this->assertSame(['usage' => 7], $store->recordedPackIds());
	}//end testTheAppConfigIsResolvedFromTheContainer()

	/**
	 * No app config, the wrong type or a failing container record nothing, and are asked again.
	 *
	 * @return void
	 */
	public function testWithoutAnAppConfigNothingIsRecorded(): void {
		$absent = $this->createMock(ContainerInterface::class);
		$absent->expects($this->exactly(2))->method('has')->willReturn(false);
		$absent->expects($this->never())->method('get');

		$wrongType = $this->createMock(ContainerInterface::class);
		$wrongType->expects($this->exactly(2))->method('has')->willReturn(true);
		$wrongType->expects($this->exactly(2))->method('get')->willReturn(new \stdClass());

		$failing = $this->createMock(ContainerInterface::class);
		$failing->expects($this->exactly(2))->method('has')->willThrowException(new \RuntimeException('no container'));

		foreach ([$absent, $wrongType, $failing] as $container) {
			$store = new CmdbImportPackStore(container: $container);
			$this->assertSame([], $store->recordedPackIds());
			$this->assertSame([], $store->recordedPackIds());
		}
	}//end testWithoutAnAppConfigNothingIsRecorded()

}//end class
