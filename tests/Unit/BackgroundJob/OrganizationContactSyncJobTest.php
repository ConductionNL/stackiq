<?php

/**
 * The organisation contact sync job honours its enabled switch (#1139).
 *
 * An admin switches the job off in the cronjob settings, which stores
 * `enabled: false` under `cronjob_config`. The job read nothing of it and
 * kept syncing every five minutes. These tests save the switch through the
 * real SettingsService (the method the settings screen calls) and run the
 * real job.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/operations-sync-status-and-progress/tasks.md#task-3
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\BackgroundJob;

use OCA\Stackiq\BackgroundJob\OrganizationContactSyncJob;
use OCA\Stackiq\Service\Contactpersoon\SystemContactWrites;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the job with the switch on, off and never saved.
 */
class OrganizationContactSyncJobTest extends TestCase {

	/**
	 * Switched off in the settings: no sync and no contactsUid refresh.
	 *
	 * @return void
	 */
	public function testASwitchedOffJobDoesNotSync(): void {
		$store = [];
		$settings = $this->settings($store);
		$settings->updateCronjobConfig(['jobId' => 'organization_contact_sync', 'enabled' => false]);

		$sync = $this->createMock(OrganizationSyncService::class);
		$sync->expects($this->never())->method('performScheduledSync');
		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->expects($this->never())->method('isAvailable');

		$this->runJob($sync, $contacts, $settings);
	}//end testASwitchedOffJobDoesNotSync()

	/**
	 * Switched on, or never saved at all (the default is on): the job syncs.
	 *
	 * @return void
	 */
	public function testAnEnabledOrUnsavedJobSyncs(): void {
		foreach ([true, null] as $enabled) {
			$store = [];
			$settings = $this->settings($store);
			if ($enabled !== null) {
				$settings->updateCronjobConfig(['jobId' => 'organization_contact_sync', 'enabled' => $enabled]);
			}

			$sync = $this->createMock(OrganizationSyncService::class);
			$sync->expects($this->once())->method('performScheduledSync');
			$contacts = $this->createMock(StackiqContactSyncService::class);
			$contacts->method('isAvailable')->willReturn(false);

			$this->runJob($sync, $contacts, $settings);
		}
	}//end testAnEnabledOrUnsavedJobSyncs()

	/**
	 * The sync writes as stackiq itself, so the contact person check lets them through,
	 * and the mark is gone once the job returns.
	 *
	 * @return void
	 */
	public function testTheSyncRunsAsASystemWrite(): void {
		$store = [];
		$active = null;
		$sync = $this->createMock(OrganizationSyncService::class);
		$sync->method('performScheduledSync')->willReturnCallback(
			function () use (&$active): void {
				$active = (new SystemContactWrites())->isActive();
			}
		);
		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->method('isAvailable')->willReturn(false);

		$this->runJob($sync, $contacts, $this->settings($store));

		$this->assertTrue($active);
		$this->assertFalse((new SystemContactWrites())->isActive());
	}//end testTheSyncRunsAsASystemWrite()

	/**
	 * Run the job's protected run() once.
	 *
	 * @param OrganizationSyncService $sync The sync service double.
	 * @param StackiqContactSyncService $contacts The contacts bridge double.
	 * @param CronjobSettings $settings The real cronjob settings.
	 *
	 * @return void
	 */
	private function runJob(OrganizationSyncService $sync, StackiqContactSyncService $contacts, CronjobSettings $settings): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$job = new OrganizationContactSyncJob(
			$this->createMock(ITimeFactory::class),
			$sync,
			$contacts,
			$this->createMock(SettingsService::class),
			$settings,
			$appManager,
			$this->createMock(LoggerInterface::class)
		);

		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * Real cronjob settings over an in-memory app config.
	 *
	 * @param array $store Reference to the backing key/value store.
	 *
	 * @return CronjobSettings
	 */
	private function settings(array &$store): CronjobSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		return new CronjobSettings(
			config: $config,
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end settings()
}//end class
