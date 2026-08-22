<?php

/**
 * Unit tests for MigrateBackgroundJobClasses.
 *
 * `oc_jobs` stores the job's CLASS NAME as a string, so the namespace rename
 * orphans every row this app registered. The job then never runs again and
 * nothing reports it. These tests pin what the step removes — and, just as
 * importantly, what it must NOT remove: the literal list is the whole blast
 * radius, so an arm asserting the new classes survive is what keeps a future
 * "generalisation" of this step from deleting the app's live jobs.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\MigrateBackgroundJobClasses;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Stackiq\Repair\MigrateBackgroundJobClasses
 */
class MigrateBackgroundJobClassesTest extends TestCase {
	/**
	 * The step reports a name that says what it does.
	 *
	 * @return void
	 */
	public function testGetNameDescribesTheCleanup(): void {
		$step = new MigrateBackgroundJobClasses($this->createMock(IJobList::class), new NullLogger());

		$this->assertStringContainsString('SoftwareCatalog', $step->getName());
	}//end testGetNameDescribesTheCleanup()

	/**
	 * The list is exactly the four pre-rename `<background-jobs>` entries.
	 *
	 * @return void
	 */
	public function testLegacyListIsTheFourPreRenameJobs(): void {
		$this->assertSame(
			[
				'OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob',
				'OCA\SoftwareCatalog\BackgroundJob\ContractStatusJob',
				'OCA\SoftwareCatalog\BackgroundJob\FederationSyncJob',
				'OCA\SoftwareCatalog\BackgroundJob\EolSyncJob',
			],
			MigrateBackgroundJobClasses::LEGACY_JOB_CLASSES
		);
	}//end testLegacyListIsTheFourPreRenameJobs()

	/**
	 * No entry in the legacy list names a class that still exists.
	 *
	 * If one did, the step would deregister a live job.
	 *
	 * @return void
	 */
	public function testNoLegacyEntryNamesALiveClass(): void {
		foreach (MigrateBackgroundJobClasses::LEGACY_JOB_CLASSES as $legacyClass) {
			$this->assertStringStartsWith('OCA\SoftwareCatalog\\', $legacyClass);
			$this->assertStringNotContainsString('OCA\Stackiq\\', $legacyClass);
		}
	}//end testNoLegacyEntryNamesALiveClass()

	/**
	 * Every registered legacy job is removed.
	 *
	 * @return void
	 */
	public function testRemovesEveryRegisteredLegacyJob(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(true);

		$removed = [];
		$jobList->method('remove')->willReturnCallback(
			static function (mixed $job, mixed $argument) use (&$removed): void {
				$removed[] = $job;
			}
		);

		(new MigrateBackgroundJobClasses($jobList, new NullLogger()))->run($this->createMock(IOutput::class));

		$this->assertSame(MigrateBackgroundJobClasses::LEGACY_JOB_CLASSES, $removed);
	}//end testRemovesEveryRegisteredLegacyJob()

	/**
	 * A job that is not registered is not removed.
	 *
	 * @return void
	 */
	public function testRemovesNothingWhenNoLegacyJobIsRegistered(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(false);

		$jobList->expects($this->never())->method('remove');

		(new MigrateBackgroundJobClasses($jobList, new NullLogger()))->run($this->createMock(IOutput::class));
	}//end testRemovesNothingWhenNoLegacyJobIsRegistered()

	/**
	 * The step never ADDS a job — registration stays Nextcloud's job.
	 *
	 * @return void
	 */
	public function testNeverRegistersAJobItself(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(true);

		$jobList->expects($this->never())->method('add');

		(new MigrateBackgroundJobClasses($jobList, new NullLogger()))->run($this->createMock(IOutput::class));
	}//end testNeverRegistersAJobItself()

	/**
	 * A throw NEVER escapes into the installer.
	 *
	 * @return void
	 */
	public function testAThrowNeverEscapesTheInstaller(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willThrowException(new RuntimeException('database gone'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		(new MigrateBackgroundJobClasses($jobList, new NullLogger()))->run($output);
	}//end testAThrowNeverEscapesTheInstaller()
}//end class
