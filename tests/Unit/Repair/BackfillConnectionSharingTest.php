<?php

/**
 * The backfill reports what it wrote and never lets a failure escape.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Repair
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\BackfillConnectionSharing;
use OCA\Stackiq\Service\ConnectionShareSync;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers BackfillConnectionSharing.
 *
 * @covers \OCA\Stackiq\Repair\BackfillConnectionSharing
 */
final class BackfillConnectionSharingTest extends TestCase {

	/**
	 * It reports how many connections it wrote.
	 *
	 * @return void
	 */
	public function testItReportsHowManyConnectionsItWrote(): void {
		$sync = $this->createMock(originalClassName: ConnectionShareSync::class);
		$sync->expects($this->once())->method('backfill')->willReturn(3);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with(
			'BackfillConnectionSharing: 3 connection(s) now readable to the participants of their usages.'
		);

		(new BackfillConnectionSharing(sync: $sync, logger: $this->createMock(originalClassName: LoggerInterface::class)))->run($output);
	}

	/**
	 * Afailure is logged and does not escape.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndDoesNotEscape(): void {
		$sync = $this->createMock(originalClassName: ConnectionShareSync::class);
		$sync->method('backfill')->willThrowException(new \RuntimeException('down'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with('BackfillConnectionSharing: could not read the shared usages; nothing done.');

		(new BackfillConnectionSharing(sync: $sync, logger: $logger))->run($output);
	}

	/**
	 * It runs after the register import on upgrade.
	 *
	 * @return void
	 */
	public function testItRunsAfterTheRegisterImportOnUpgrade(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		$import = strpos($info, '<step>OCA\Stackiq\Repair\InitializeSettings</step>');
		$backfill = strpos($info, '<step>OCA\Stackiq\Repair\BackfillConnectionSharing</step>');

		$this->assertNotFalse(condition: $backfill);
		$this->assertGreaterThan(expected: $import, actual: $backfill);
	}
}
