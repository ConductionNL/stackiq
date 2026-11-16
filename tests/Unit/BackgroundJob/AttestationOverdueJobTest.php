<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\BackgroundJob;

use OCA\Stackiq\BackgroundJob\AttestationOverdueJob;
use OCA\Stackiq\Service\AttestationAnswerService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * REQ-OAT-003: the daily job runs the overdue pass, skips without OpenRegister and survives a failure.
 *
 * @covers \OCA\Stackiq\BackgroundJob\AttestationOverdueJob
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 */
final class AttestationOverdueJobTest extends TestCase {

	/**
	 * Run the job's protected run().
	 *
	 * @param AttestationAnswerService $answers The answer service.
	 * @param array<int, string>       $apps    The installed apps.
	 * @param LoggerInterface          $logger  The logger.
	 *
	 * @return AttestationOverdueJob
	 */
	private function runJob(AttestationAnswerService $answers, array $apps, LoggerInterface $logger): AttestationOverdueJob {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($apps);
		$job = new AttestationOverdueJob($this->createMock(ITimeFactory::class), $answers, $appManager, $logger);

		$method = new ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);

		return $job;
	}//end runJob()

	/**
	 * The pass runs once a day and its count is logged.
	 *
	 * @return void
	 */
	public function testTheOverduePassRunsDaily(): void {
		$answers = $this->createMock(AttestationAnswerService::class);
		$answers->expects(self::once())->method('markOverdue')->willReturn(3);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with('[AttestationOverdueJob] Overdue pass complete', ['overdue' => 3]);

		$job = $this->runJob($answers, ['openregister', 'stackiq'], $logger);

		self::assertSame(86400, $job->getInterval());
	}//end testTheOverduePassRunsDaily()

	/**
	 * Without OpenRegister nothing runs; a failure is logged, not thrown.
	 *
	 * @return void
	 */
	public function testSkipAndFailure(): void {
		$never = $this->createMock(AttestationAnswerService::class);
		$never->expects(self::never())->method('markOverdue');
		$this->runJob($never, ['stackiq'], $this->createMock(LoggerInterface::class));

		$failing = $this->createMock(AttestationAnswerService::class);
		$failing->method('markOverdue')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('[AttestationOverdueJob] Overdue pass failed', ['error' => 'down']);
		$this->runJob($failing, ['openregister'], $logger);
	}//end testSkipAndFailure()
}//end class
