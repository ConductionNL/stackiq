<?php

/**
 * SyncProgressTest
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SyncAccessPolicy;
use OCA\Stackiq\Service\SyncProgress;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A sync run as an organisation_sync operation, its last-run record, and who may see it.
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-002-the-organisation-sync-shall-report-its-progress-and-keep-the-result-of-its-last-run
 */
#[CoversClass(SyncProgress::class)]
#[CoversClass(SyncAccessPolicy::class)]
final class SyncProgressTest extends TestCase {

	/**
	 * A container holding a tracker.
	 *
	 * @param mixed $tracker What the container answers for ProgressTracker.
	 *
	 * @return ContainerInterface
	 */
	private function container(mixed $tracker): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ProgressTracker::class)->willReturnCallback(
			static fn () => $tracker instanceof \Throwable ? throw $tracker : $tracker
		);
		return $container;
	}//end container()

	/**
	 * A run starts an organisation_sync operation, moves through the phases with its counts, and completes.
	 *
	 * @return void
	 */
	public function testARunIsReportedPhaseByPhase(): void {
		$calls   = [];
		$tracker = $this->createMock(ProgressTracker::class);
		$tracker->expects($this->once())->method('startOperation')->with('organisation_sync', ['statistics' => ['trigger' => 'scheduled']])->willReturn('organisation_sync_1');
		$tracker->method('setPhase')->willReturnCallback(function (string $phase) use (&$calls): void {
			$calls[] = $phase;
		});
		$tracker->expects($this->once())->method('updateStatistics')->with(
			['organizationsProcessed' => 3, 'entitiesCreated' => 1, 'entitiesUpdated' => 0, 'contactPersonsProcessed' => 0, 'usersCreated' => 0]
		);
		$tracker->expects($this->once())->method('completeOperation')->with(
			['organizationsProcessed' => 3, 'entitiesCreated' => 1, 'entitiesUpdated' => 2, 'contactPersonsProcessed' => 4, 'usersCreated' => 1]
		);

		$run = SyncProgress::start(container: $this->container($tracker), trigger: 'scheduled');
		$run->phase(phase: 'processing_organizations');
		$run->phase(phase: 'processing_contacts', statistics: ['organizationsProcessed' => 3, 'entitiesCreated' => 1]);
		$run->complete(results: ['organizationsProcessed' => 3, 'entitiesCreated' => 1, 'entitiesUpdated' => 2, 'contactPersonsProcessed' => 4, 'usersCreated' => 1]);

		$this->assertSame(['processing_organizations', 'processing_contacts'], $calls);
	}//end testARunIsReportedPhaseByPhase()

	/**
	 * The last-run record holds start, end, trigger, counts and the error count.
	 *
	 * @return void
	 */
	public function testTheLastRunRecord(): void {
		$record = SyncProgress::start(container: $this->container(null), trigger: 'manual')->lastResult(
			results: ['organizationsProcessed' => 5, 'contactPersonsProcessed' => '2', 'errors' => ['a', 'b']]
		);

		$this->assertSame(
			['startedAt', 'finishedAt', 'trigger', 'organizationsProcessed', 'entitiesCreated', 'entitiesUpdated', 'contactPersonsProcessed', 'usersCreated', 'errorCount'],
			array_keys($record)
		);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['startedAt']);
		$this->assertSame('manual', $record['trigger']);
		$this->assertSame(5, $record['organizationsProcessed']);
		$this->assertSame(2, $record['contactPersonsProcessed']);
		$this->assertSame(0, $record['usersCreated']);
		$this->assertSame(2, $record['errorCount']);
	}//end testTheLastRunRecord()

	/**
	 * Without a tracker, or with one that throws, the run reports nothing and never throws.
	 *
	 * @return void
	 */
	public function testNoTrackerOrAThrowingOneNeverReachesTheSync(): void {
		$none = SyncProgress::start(container: $this->container(new RuntimeException('no tracker')), trigger: 'scheduled');
		$none->phase(phase: 'processing_users', statistics: ['organizationsProcessed' => 1]);
		$none->fail(message: 'x');

		$tracker = $this->createMock(ProgressTracker::class);
		$tracker->method('startOperation')->willThrowException(new RuntimeException('store down'));
		$tracker->method('failOperation')->willThrowException(new RuntimeException('store down'));
		$broken = SyncProgress::start(container: $this->container($tracker), trigger: 'scheduled');
		$broken->fail(message: 'database gone');

		$this->assertSame(0, $broken->lastResult(results: [])['errorCount']);
	}//end testNoTrackerOrAThrowingOneNeverReachesTheSync()

	/**
	 * An admin or a functional administrator may see the sync; nobody else, and nobody signed out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
	 */
	public function testWhoMaySeeTheSync(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'fb' && $group === 'functioneel-beheerder');
		$policy = new SyncAccessPolicy(groupManager: $groups);

		$this->assertTrue($policy->mayViewSync(uid: 'admin'));
		$this->assertTrue($policy->mayViewSync(uid: 'fb'));
		$this->assertFalse($policy->mayViewSync(uid: 'bob'));
		$this->assertFalse($policy->mayViewSync(uid: null));
		$this->assertFalse($policy->mayViewSync(uid: ''));
	}//end testWhoMaySeeTheSync()
}//end class
