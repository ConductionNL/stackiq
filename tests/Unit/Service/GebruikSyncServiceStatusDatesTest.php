<?php

/**
 * The status a gebruik moves to from its phase start dates.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\GebruikSyncService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * processSpecificGebruik(): the latest phase whose start date has passed becomes the status and is saved.
 * Dates are relative to the run, so no test pins a clock value.
 *
 * @covers \OCA\Stackiq\Service\GebruikSyncService
 */
final class GebruikSyncServiceStatusDatesTest extends TestCase {

	/**
	 * The objects saved, as [data, register, schema, uuid].
	 *
	 * @var list<array{0: array<string, mixed>, 1: mixed, 2: mixed, 3: mixed}>
	 */
	private array $saved = [];

	/**
	 * The log records, as [level, message].
	 *
	 * @var list<array{0: mixed, 1: string}>
	 */
	private array $records = [];

	/**
	 * A service over that voorzieningen config, whose save throws when asked to.
	 *
	 * @param array<string, mixed> $config   The voorzieningen config.
	 * @param bool                 $saveFails Whether the save throws.
	 *
	 * @return GebruikSyncService
	 */
	private function service(array $config = ['register' => '3', 'gebruik_schema' => '9'], bool $saveFails = false): GebruikSyncService {
		$records = &$this->records;
		$logger  = new class($records) extends AbstractLogger {
			/**
			 * @param array $records The shared record list.
			 */
			public function __construct(private array &$records) {
			}

			/**
			 * @param mixed  $level   The level.
			 * @param mixed  $message The message.
			 * @param array  $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->records[] = [$level, (string) $message];
			}
		};

		$settings = $this->createMock(originalClassName: RegisterConfigReaderInterface::class);
		$settings->method('getVoorzieningenConfig')->willReturn($config);
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use ($saveFails) {
				if ($saveFails === true) {
					throw new RuntimeException('database down');
				}

				$this->saved[] = [$object, $register, $schema, $uuid];
				return $this->createMock(originalClassName: ObjectEntity::class);
			}
		);

		return new GebruikSyncService($logger, $settings, objectService: $objects);
	}//end service()

	/**
	 * A gebruik with that data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function gebruik(array $data): ObjectEntity {
		$gebruik = $this->createMock(originalClassName: ObjectEntity::class);
		$gebruik->method('getObject')->willReturn($data);
		$gebruik->method('getUuid')->willReturn('g-1');

		return $gebruik;
	}//end gebruik()

	/**
	 * A date that many days from now, as Y-m-d.
	 *
	 * @param int $days Days from now; negative is past.
	 *
	 * @return string
	 */
	private static function day(int $days): string {
		return (new DateTimeImmutable())->modify(sprintf('%+d days', $days))->format('Y-m-d');
	}//end day()

	/**
	 * The latest passed phase wins over earlier passed phases and over future ones, and is saved under the gebruik's uuid.
	 *
	 * @return void
	 */
	public function testTheLatestPassedPhaseBecomesTheStatus(): void {
		$data = [
			'status'                => 'Planned',
			'startDatePlanned'      => self::day(-30),
			'startDateInProduction' => self::day(-2),
			'startDateOutPhasing'   => self::day(10),
		];

		$stats = $this->service()->processSpecificGebruik(gebruikObject: $this->gebruik(data: $data));

		$this->assertTrue(condition: $stats['statusUpdated']);
		$this->assertSame(expected: [], actual: $stats['errors']);
		$this->assertSame(expected: 'g-1', actual: $stats['gebruikId']);
		$this->assertSame(expected: [[['status' => 'In production'] + $data, 3, 9, 'g-1']], actual: $this->saved);
	}//end testTheLatestPassedPhaseBecomesTheStatus()

	/**
	 * A gebruik already in its latest passed phase is not saved again.
	 *
	 * @return void
	 */
	public function testAnUnchangedStatusIsNotSaved(): void {
		$stats = $this->service()->processSpecificGebruik(
			gebruikObject: $this->gebruik(data: ['status' => 'Acquisition', 'startDateAcquisition' => self::day(-1)])
		);

		$this->assertFalse(condition: $stats['statusUpdated']);
		$this->assertSame(expected: [], actual: $this->saved);
	}//end testAnUnchangedStatusIsNotSaved()

	/**
	 * Only future, empty or unreadable dates leave the status alone; an unreadable one is logged.
	 *
	 * @return void
	 */
	public function testNoPassedDateLeavesTheStatus(): void {
		$stats = $this->service()->processSpecificGebruik(
			gebruikObject: $this->gebruik(data: ['status' => 'Planned', 'startDateInProduction' => self::day(5), 'startDateOutPhasing' => '', 'startDateOutPhased' => 'not a date'])
		);

		$this->assertFalse(condition: $stats['statusUpdated']);
		$this->assertSame(expected: [], actual: $this->saved);
		$this->assertContains(needle: ['warning', 'Invalid date format'], haystack: $this->records);
	}//end testNoPassedDateLeavesTheStatus()

	/**
	 * Phased out, the last phase, wins once its date has passed even when a later-listed date is missing.
	 *
	 * @return void
	 */
	public function testPhasedOutWinsOnceItsDateHasPassed(): void {
		$this->service()->processSpecificGebruik(
			gebruikObject: $this->gebruik(data: ['startDateInProduction' => self::day(-100), 'startDateOutPhasing' => self::day(-20), 'startDateOutPhased' => self::day(-3)])
		);

		$this->assertSame(expected: 'Phased out', actual: $this->saved[0][0]['status']);
	}//end testPhasedOutWinsOnceItsDateHasPassed()

	/**
	 * Without a configured register or schema the status is not saved, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterSavesNothing(): void {
		$stats = $this->service(config: ['register' => '3'])->processSpecificGebruik(
			gebruikObject: $this->gebruik(data: ['status' => 'Planned', 'startDateInProduction' => self::day(-1)])
		);

		$this->assertSame(expected: [], actual: $this->saved);
		$this->assertContains(needle: ['error', 'Failed to update gebruik object'], haystack: $this->records);
		$this->assertNotSame(expected: [], actual: $stats['errors']);
	}//end testAnUnconfiguredRegisterSavesNothing()

	/**
	 * A failing save is logged and reported, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsReported(): void {
		$stats = $this->service(saveFails: true)->processSpecificGebruik(
			gebruikObject: $this->gebruik(data: ['status' => 'Planned', 'startDateInProduction' => self::day(-1)])
		);

		$this->assertFalse(condition: $stats['statusUpdated']);
		$this->assertContains(needle: ['error', 'Failed to update gebruik object'], haystack: $this->records);
		$this->assertStringContainsString(needle: 'database down', haystack: implode(separator: ' ', array: $stats['errors']));
	}//end testAFailingSaveIsReported()
}//end class
