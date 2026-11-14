<?php

/**
 * LogSwitchedOffSyncTest
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\BackgroundJob\OrganizationContactSyncJob;
use OCA\Stackiq\Kernel\Contracts\CronjobSettingsInterface;
use OCA\Stackiq\Repair\LogSwitchedOffSync;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The upgrade says once when the scheduled sync is switched off.
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-003-the-scheduled-sync-must-not-run-while-its-switch-is-off
 */
#[CoversClass(LogSwitchedOffSync::class)]
final class LogSwitchedOffSyncTest extends TestCase {

	/**
	 * A switched-off sync is a warning in the output and the log; a switched-on one is silent.
	 *
	 * @return void
	 */
	public function testASwitchedOffSyncIsReported(): void {
		foreach ([false => 1, true => 0] as $enabled => $warnings) {
			$cron = $this->createMock(CronjobSettingsInterface::class);
			$cron->method('isCronjobEnabled')->with(OrganizationContactSyncJob::JOB_ID)->willReturn((bool) $enabled);
			$output = $this->createMock(IOutput::class);
			$output->expects($this->exactly($warnings))->method('warning')->with(LogSwitchedOffSync::MESSAGE);
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->exactly($warnings))->method('warning')->with(LogSwitchedOffSync::MESSAGE, ['app' => 'stackiq']);

			(new LogSwitchedOffSync(cronjobSettings: $cron, logger: $logger))->run($output);
		}
	}//end testASwitchedOffSyncIsReported()

	/**
	 * Unreadable settings never break the upgrade.
	 *
	 * @return void
	 */
	public function testUnreadableSettingsNeverThrow(): void {
		$cron = $this->createMock(CronjobSettingsInterface::class);
		$cron->method('isCronjobEnabled')->willThrowException(new RuntimeException('db'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('LogSwitchedOffSync: could not read the cronjob settings', ['error' => 'db']);

		$step = new LogSwitchedOffSync(cronjobSettings: $cron, logger: $logger);
		$step->run($this->createMock(IOutput::class));
		$this->assertStringContainsString('organisation sync', $step->getName());
	}//end testUnreadableSettingsNeverThrow()
}//end class
