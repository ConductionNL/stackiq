<?php

/**
 * Unit tests for the outer failure path of SettingsObjectCounts::statistics.
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

use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Settings\SettingsObjectCounts;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A failure escaping one side's statistics turns the whole answer into the failed statistics.
 *
 * @covers \OCA\Stackiq\Service\Settings\SettingsObjectCounts
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class SettingsObjectCountsFailureTest extends TestCase {

	/**
	 * When logging a side's failure itself fails, both sides report that error,
	 * unconfigured and with zero counts, and the failure is logged once more.
	 *
	 * @return void
	 */
	public function testAnEscapingFailureAnswersTheFailedStatistics(): void {
		$settings = $this->createMock(originalClassName: RegisterConfigReaderInterface::class);
		$settings->method('getVoorzieningenConfig')->willThrowException(new RuntimeException('voorzieningen down'));
		$settings->expects($this->never())->method('getAmefConfig');
		$logged = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
				if (count($logged) === 1) {
					throw new RuntimeException('log sink full');
				}
			}
		);
		$counts = new SettingsObjectCounts(
			settings: $settings,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $logger
		);

		$before = time();
		$statistics = $counts->statistics();

		$this->assertSame(['voorzieningen', 'amef', 'timestamp', 'error'], array_keys($statistics));
		$this->assertSame('log sink full', $statistics['error']);
		$this->assertSame(
			[
				'config' => [],
				'object_counts' => ['totalOrganisatieObjects' => 0, 'totalContactpersoonObjects' => 0],
				'configured' => false,
				'error' => 'log sink full',
			],
			$statistics['voorzieningen']
		);
		$this->assertSame(
			[
				'config' => [],
				'object_counts' => [
					'totalElementObjects' => 0,
					'totalOrganizationObjects' => 0,
					'totalViewObjects' => 0,
					'totalRelationshipsObjects' => 0,
					'totalModelObjects' => 0,
				],
				'configured' => false,
				'error' => 'log sink full',
			],
			$statistics['amef']
		);
		$this->assertGreaterThanOrEqual($before, $statistics['timestamp']);
		$this->assertSame(
			[
				['Failed to get Voorzieningen statistics', ['error' => 'voorzieningen down']],
				['SettingsService: Failed to get object counts statistics', ['exception' => 'log sink full']],
			],
			$logged
		);
	}//end testAnEscapingFailureAnswersTheFailedStatistics()
}//end class
