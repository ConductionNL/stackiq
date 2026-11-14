<?php

/**
 * SyncStatusControllerTest
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SyncStatusController;
use OCA\Stackiq\Kernel\Contracts\CronjobSettingsInterface;
use OCA\Stackiq\Kernel\Contracts\ProgressTrackerInterface;
use OCA\Stackiq\Service\SyncAccessPolicy;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/sync/status for admins and functional administrators, 403 for others.
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
 */
#[CoversClass(SyncStatusController::class)]
#[UsesClass(SyncAccessPolicy::class)]
final class SyncStatusControllerTest extends TestCase {

	/**
	 * The controller for a caller.
	 *
	 * @param string|null          $uid     The caller, or null when signed out.
	 * @param array<string, mixed> $config  App config values by key.
	 * @param array|null           $current The running sync.
	 * @param bool                 $enabled Whether the sync is switched on.
	 *
	 * @return SyncStatusController
	 */
	private function controller(?string $uid, array $config = [], ?array $current = null, bool $enabled = true): SyncStatusController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $u): bool => $u === 'admin');
		$groups->method('isInGroup')->willReturnCallback(static fn (string $u, string $g): bool => $u === 'fb' && $g === 'functioneel-beheerder');
		$cron = $this->createMock(CronjobSettingsInterface::class);
		$cron->method('isCronjobEnabled')->with('organization_contact_sync')->willReturn($enabled);
		$progress = $this->createMock(ProgressTrackerInterface::class);
		$progress->method('getCurrentOperation')->with('organisation_sync')->willReturn($current);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueArray')->willReturnCallback(static fn (string $app, string $key, array $default) => $config[$key] ?? $default);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default) => $config[$key] ?? $default);

		return new SyncStatusController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			policy: new SyncAccessPolicy(groupManager: $groups),
			cronjobSettings: $cron,
			progress: $progress,
			appConfig: $appConfig,
		);
	}//end controller()

	/**
	 * A functional administrator gets the interval, the switch, the last run and the running run.
	 *
	 * @return void
	 */
	public function testAFunctionalAdministratorGetsTheStatus(): void {
		$last    = ['startedAt' => '2026-11-14 08:00:00', 'finishedAt' => '2026-11-14 08:00:41', 'trigger' => 'scheduled', 'errorCount' => 0];
		$running = ['operation_id' => 'organisation_sync_1', 'phase' => 'processing_contacts', 'percentage' => 40];
		$answer  = $this->controller('fb', ['last_sync_result' => $last], $running, false)->status();

		$this->assertSame(200, $answer->getStatus());
		$this->assertSame(['interval' => 300, 'enabled' => false, 'lastRun' => $last, 'current' => $running], $answer->getData());
	}//end testAFunctionalAdministratorGetsTheStatus()

	/**
	 * Before records were kept the last run is its time; never run is null.
	 *
	 * @return void
	 */
	public function testAnOlderLastRunIsItsTimeAndNeverIsNull(): void {
		$this->assertSame(['finishedAt' => '2026-10-01 07:00:00'], $this->controller('admin', ['last_sync_time' => '2026-10-01 07:00:00'])->status()->getData()['lastRun']);
		$this->assertNull($this->controller('admin', ['last_sync_time' => 'Never'])->status()->getData()['lastRun']);
		$this->assertNull($this->controller('admin')->status()->getData()['current']);
	}//end testAnOlderLastRunIsItsTimeAndNeverIsNull()

	/**
	 * Any other signed-in user, and a signed-out caller, get 403.
	 *
	 * @return void
	 */
	public function testOthersGetForbidden(): void {
		$this->assertSame(403, $this->controller('bob')->status()->getStatus());
		$this->assertSame(403, $this->controller(null)->status()->getStatus());
	}//end testOthersGetForbidden()
}//end class
