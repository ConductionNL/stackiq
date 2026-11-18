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
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\BackfillUsageProvider;
use OCA\Stackiq\Service\UsageProviderSync;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers BackfillUsageProvider.
 *
 * @covers \OCA\Stackiq\Repair\BackfillUsageProvider
 */
final class BackfillUsageProviderTest extends TestCase {

	public function testItReportsHowManyUsagesItWrote(): void {
		$sync = $this->createMock(UsageProviderSync::class);
		$sync->expects($this->once())->method('backfill')->willReturn(4);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with("BackfillUsageProvider: 4 usage(s) now carry their application's supplier.");

		(new BackfillUsageProvider(sync: $sync, logger: $this->createMock(LoggerInterface::class)))->run($output);
	}

	public function testAFailureIsLoggedAndDoesNotEscape(): void {
		$sync = $this->createMock(UsageProviderSync::class);
		$sync->method('backfill')->willThrowException(new \RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new BackfillUsageProvider(sync: $sync, logger: $logger))->run($this->createMock(IOutput::class));
	}

	public function testItRunsAfterTheRegisterImportOnUpgrade(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');

		$this->assertGreaterThan(strpos($info, '<step>OCA\Stackiq\Repair\InitializeSettings</step>'), strpos($info, '<step>OCA\Stackiq\Repair\BackfillUsageProvider</step>'));
	}
}
