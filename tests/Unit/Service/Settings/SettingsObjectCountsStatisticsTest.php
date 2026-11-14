<?php

/**
 * Unit tests for the AMEF counts and statistics of SettingsObjectCounts.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\Settings\SettingsObjectCounts;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * AMEF counts, the combined statistics, and how each degrades on failure.
 *
 * @covers \OCA\Stackiq\Service\Settings\SettingsObjectCounts
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class SettingsObjectCountsStatisticsTest extends TestCase {

	/**
	 * The settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The AMEF zero counts.
	 *
	 * @var array<string, int>
	 */
	private const AMEF_ZERO = [
		'totalElementObjects' => 0,
		'totalOrganizationObjects' => 0,
		'totalViewObjects' => 0,
		'totalRelationshipsObjects' => 0,
		'totalModelObjects' => 0,
	];

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Each AMEF count is the number of rows its getter returns.
	 *
	 * @return void
	 */
	public function testAmefCountsCountEachGetter(): void {
		$this->archiMate(
			counts: ['getElementObjects' => 3, 'getOrganizationObjects' => 1, 'getViewObjects' => 0, 'getRelationshipObjects' => 5, 'getModelObjects' => 2]
		);
		$this->logger->expects($this->once())->method('debug')->with(
			'SettingsService: Retrieved AMEF object counts',
			['elementObjects' => 3, 'organizationObjects' => 1, 'viewObjects' => 0, 'relationshipObjects' => 5, 'modelObjects' => 2]
		);

		$this->assertSame(
			[
				'totalElementObjects' => 3,
				'totalOrganizationObjects' => 1,
				'totalViewObjects' => 0,
				'totalRelationshipsObjects' => 5,
				'totalModelObjects' => 2,
			],
			$this->counts()->amefCounts()
		);
	}//end testAmefCountsCountEachGetter()

	/**
	 * An unresolvable ArchiMate service answers zeros and logs the failure.
	 *
	 * @return void
	 */
	public function testAmefCountsFallBackToZeros(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no archimate'));
		$this->logger->expects($this->once())->method('error')->with(
			'SettingsService: Failed to get AMEF object counts',
			$this->callback(static fn (array $context): bool => $context['error'] === 'no archimate' && is_string($context['trace']))
		);

		$this->assertSame(self::AMEF_ZERO, $this->counts()->amefCounts());
	}//end testAmefCountsFallBackToZeros()

	/**
	 * Without an object service every Voorzieningen count is zero, and no
	 * config is read.
	 *
	 * @return void
	 */
	public function testNoObjectServiceCountsZero(): void {
		$this->settings->method('getObjectService')->willReturn(null);
		$this->settings->expects($this->never())->method('getVoorzieningenConfig');

		$counts = $this->counts()->voorzieningenCounts();

		$this->assertCount(19, $counts);
		$this->assertSame([0], array_values(array_unique($counts)));
	}//end testNoObjectServiceCountsZero()

	/**
	 * A failure reading the config answers zeros and logs the failure.
	 *
	 * @return void
	 */
	public function testAFailingConfigCountsZero(): void {
		$this->settings->method('getObjectService')->willReturn($this->createMock(originalClassName: ObjectServiceInterface::class));
		$this->settings->method('getVoorzieningenConfig')->willThrowException(new RuntimeException('config down'));
		$this->logger->expects($this->once())->method('error')->with(
			'SettingsService: Failed to get Voorzieningen object counts',
			$this->callback(static fn (array $context): bool => $context['error'] === 'config down')
		);

		$counts = $this->counts()->voorzieningenCounts();

		$this->assertCount(19, $counts);
		$this->assertSame(0, $counts['totalOrganisatieObjects']);
	}//end testAFailingConfigCountsZero()

	/**
	 * The statistics combine the configs, the counts and whether each side
	 * is configured.
	 *
	 * @return void
	 */
	public function testStatisticsCombineConfigAndCounts(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('countSearchObjects')->willReturn(4);
		$voorzieningen = ['register' => '5', 'organisatie_schema' => '1'];
		$amef = ['register_id' => '9', 'elements_schema' => '2'];
		$this->settings->method('getObjectService')->willReturn($objects);
		$this->settings->method('getVoorzieningenConfig')->willReturn($voorzieningen);
		$this->settings->method('getAmefConfig')->willReturn($amef);
		$this->archiMate(counts: ['getElementObjects' => 2]);

		$before = time();
		$statistics = $this->counts()->statistics();

		$this->assertSame(['voorzieningen', 'amef', 'timestamp'], array_keys($statistics));
		$this->assertSame($voorzieningen, $statistics['voorzieningen']['config']);
		$this->assertTrue($statistics['voorzieningen']['configured']);
		$this->assertSame(4, $statistics['voorzieningen']['object_counts']['totalOrganisatieObjects']);
		$this->assertSame(0, $statistics['voorzieningen']['object_counts']['totalContractObjects']);
		$this->assertSame($amef, $statistics['amef']['config']);
		$this->assertTrue($statistics['amef']['configured']);
		$this->assertSame(2, $statistics['amef']['object_counts']['totalElementObjects']);
		$this->assertGreaterThanOrEqual($before, $statistics['timestamp']);
	}//end testStatisticsCombineConfigAndCounts()

	/**
	 * A side missing its register or main schema is reported unconfigured.
	 *
	 * @return void
	 */
	public function testIncompleteConfigsAreUnconfigured(): void {
		$this->settings->method('getObjectService')->willReturn(null);
		$this->settings->method('getVoorzieningenConfig')->willReturn(['register' => '5', 'organisatie_schema' => '']);
		$this->settings->method('getAmefConfig')->willReturn(['register_id' => '9', 'elements_schema' => '']);
		$this->archiMate(counts: []);

		$statistics = $this->counts()->statistics();

		$this->assertFalse($statistics['voorzieningen']['configured']);
		$this->assertFalse($statistics['amef']['configured']);
	}//end testIncompleteConfigsAreUnconfigured()

	/**
	 * A side whose config cannot be read reports its error; the other side
	 * is unaffected.
	 *
	 * @return void
	 */
	public function testAFailingSideReportsItsError(): void {
		$this->settings->method('getObjectService')->willReturn(null);
		$this->settings->method('getVoorzieningenConfig')->willThrowException(new RuntimeException('voorzieningen down'));
		$this->settings->method('getAmefConfig')->willThrowException(new RuntimeException('amef down'));
		$messages = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$statistics = $this->counts()->statistics();

		$this->assertSame(
			[
				'config' => [],
				'object_counts' => ['totalOrganisatieObjects' => 0, 'totalContactpersoonObjects' => 0],
				'configured' => false,
				'error' => 'voorzieningen down',
			],
			$statistics['voorzieningen']
		);
		$this->assertSame(
			['config' => [], 'object_counts' => self::AMEF_ZERO, 'configured' => false, 'error' => 'amef down'],
			$statistics['amef']
		);
		$this->assertSame(['Failed to get Voorzieningen statistics', 'Failed to get AMEF statistics'], $messages);
	}//end testAFailingSideReportsItsError()

	/**
	 * Bind an ArchiMate service whose getters return the given row counts.
	 *
	 * @param array<string, int> $counts Rows per getter; absent getters return none.
	 *
	 * @return void
	 */
	private function archiMate(array $counts): void {
		$archiMate = $this->createMock(originalClassName: ArchiMateService::class);
		foreach (['getElementObjects', 'getOrganizationObjects', 'getViewObjects', 'getRelationshipObjects', 'getModelObjects'] as $getter) {
			$archiMate->method($getter)->willReturn(array_fill(0, ($counts[$getter] ?? 0), ['id' => 'x']));
		}

		$this->container->method('get')->with(ArchiMateService::class)->willReturn($archiMate);
	}//end archiMate()

	/**
	 * The counter under test.
	 *
	 * @return SettingsObjectCounts
	 */
	private function counts(): SettingsObjectCounts {
		return new SettingsObjectCounts(settings: $this->settings, container: $this->container, logger: $this->logger);
	}//end counts()
}//end class
