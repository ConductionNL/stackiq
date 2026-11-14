<?php

/**
 * Unit tests for the OrganizationSyncService sync entry points.
 *
 * Covers the scheduled, manual and optimized manual syncs and the user sync
 * guards: what they return, whether they record the sync time, and what they
 * log, when the voorzieningen configuration is missing, invalid or unreadable.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organization-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\OrganisatieService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Cases for the sync entry points.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 *
 * @spec openspec/specs/organization-sync/spec.md
 */
class OrganizationSyncOrchestrationTest extends TestCase {

	/**
	 * The voorzieningen config reader.
	 *
	 * @var RegisterConfigReaderInterface&MockObject
	 */
	private RegisterConfigReaderInterface&MockObject $settings;

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * The database connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Every log call as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * The service under test.
	 *
	 * @var OrganizationSyncService
	 */
	private OrganizationSyncService $service;

	/**
	 * Build the service with boundary doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->settings = $this->createMock(RegisterConfigReaderInterface::class);
		$this->config   = $this->createMock(IAppConfig::class);
		$this->db       = $this->createMock(IDBConnection::class);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, (string) $message, $context];
				}
			);
		}

		$this->service = new OrganizationSyncService(
			$this->createMock(OrganisatieService::class),
			$this->createMock(ContactpersoonService::class),
			$this->createMock(SymfonyEmailService::class),
			$this->config,
			$logger,
			$this->settings,
			$this->db,
			$this->createMock(ContactPersonHandler::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(ObjectServiceInterface::class),
			$this->createMock(OrganisationMapper::class),
		);

	}//end setUp()

	/**
	 * The messages logged at a level, in order.
	 *
	 * @param string $level The level.
	 *
	 * @return list<string>
	 */
	private function messages(string $level): array {
		$out = [];
		foreach ($this->logs as [$logLevel, $message]) {
			if ($logLevel === $level) {
				$out[] = $message;
			}
		}

		return $out;

	}//end messages()

	/**
	 * Without a configured register the scheduled sync returns empty stats
	 * with the organisation batch settings and still records the sync time.
	 *
	 * @return void
	 */
	public function testScheduledSyncWithoutConfigReturnsEmptyStatsAndRecordsTime(): void {
		$this->settings->method('getVoorzieningenConfig')->willReturn([]);
		$this->config->expects($this->once())
			->method('setValueString')
			->with('stackiq', 'last_sync_time', $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'));
		$this->db->expects($this->never())->method('getQueryBuilder');

		$result = $this->service->performScheduledSync();

		$this->assertSame(0, $result['organizationsProcessed']);
		$this->assertSame(0, $result['contactPersonsProcessed']);
		$this->assertSame(0, $result['entitiesCreated']);
		$this->assertSame([], $result['errors']);
		// The organisation stats win the merge: its batch of 25 in 30 seconds.
		$this->assertSame(25, $result['batchSize']);
		$this->assertSame(30, $result['maxExecutionSeconds']);
		$this->assertContains('[CRONJOB] OrganizationSyncService: Starting scheduled synchronization', $this->messages('info'));
		$this->assertContains('[CRONJOB] OrganizationSyncService: Scheduled synchronization completed', $this->messages('info'));
		$this->assertSame(
			[
				'OrganizationSync: voorzieningen config missing register or organisatie_schema',
				'ContactSync: voorzieningen config missing register or contactpersoon_schema',
				'UserSync: voorzieningen config missing register or contactpersoon_schema',
			],
			$this->messages('warning')
		);

	}//end testScheduledSyncWithoutConfigReturnsEmptyStatsAndRecordsTime()

	/**
	 * The scheduled sync logs its mode: full for 0 minutes, else incremental.
	 *
	 * @return void
	 */
	public function testScheduledSyncLogsTheSyncMode(): void {
		$this->settings->method('getVoorzieningenConfig')->willReturn([]);

		$this->service->performScheduledSync(minutesBack: 15);

		$this->assertSame(
			['minutesBack' => 15, 'syncMode' => 'incremental', 'trigger' => 'cronjob'],
			$this->logs[0][2]
		);

	}//end testScheduledSyncLogsTheSyncMode()

	/**
	 * A failure while reading the config is answered with an error result,
	 * logged, and the sync time is not recorded.
	 *
	 * @return void
	 */
	public function testScheduledSyncFailureReturnsTheErrorResult(): void {
		$this->settings->method('getVoorzieningenConfig')->willThrowException(new RuntimeException('register down'));
		$this->config->expects($this->never())->method('setValueString');

		$result = $this->service->performScheduledSync();

		$this->assertSame(['Scheduled synchronization failed: register down'], $result['errors']);
		$this->assertSame(0, $result['organizationsProcessed']);
		$this->assertSame(0, $result['usersCreated']);
		$this->assertSame('00:00:00', $result['duration']);
		$this->assertSame(['[CRONJOB] OrganizationSyncService: Scheduled synchronization failed'], $this->messages('error'));
		$this->assertSame('full', $this->logs[0][2]['syncMode']);

	}//end testScheduledSyncFailureReturnsTheErrorResult()

	/**
	 * A failing manual sync answers success false with the reason.
	 *
	 * @return void
	 */
	public function testManualSyncFailureAnswersUnsuccessful(): void {
		$this->settings->method('getVoorzieningenConfig')->willThrowException(new RuntimeException('no config'));
		$this->config->expects($this->never())->method('setValueString');

		$result = $this->service->performManualSync(minutesBack: 5);

		$this->assertFalse($result['success']);
		$this->assertSame('Synchronization failed: no config', $result['message']);
		$this->assertArrayHasKey('trace', $result);
		$this->assertSame(['minutesBack' => 5, 'syncMode' => 'incremental'], $this->logs[0][2]);
		$this->assertSame(['OrganizationSyncService: Manual organization synchronization failed via API'], $this->messages('error'));

	}//end testManualSyncFailureAnswersUnsuccessful()

	/**
	 * The optimized sync stops after the first round that processes nothing,
	 * then runs the user sync and records the sync time.
	 *
	 * @return void
	 */
	public function testOptimizedManualSyncStopsWhenARoundProcessesNothing(): void {
		$this->settings->expects($this->exactly(3))->method('getVoorzieningenConfig')->willReturn([]);
		$this->config->expects($this->once())->method('setValueString');

		$result = $this->service->performOptimizedManualSync(maxRounds: 4, batchSize: 10);

		$this->assertSame(0, $result['totalRounds']);
		$this->assertSame(0, $result['organizationsProcessed']);
		$this->assertSame(0, $result['usersCreated']);
		$this->assertFalse($result['timeoutReached']);
		$this->assertCount(1, $result['roundsCompleted']);
		$this->assertSame(1, $result['roundsCompleted'][0]['round']);
		$this->assertSame(0, $result['roundsCompleted'][0]['contactPersonsProcessed']);
		$this->assertFalse($result['roundsCompleted'][0]['orgTimeoutReached']);
		$this->assertArrayHasKey('endTime', $result);
		$this->assertContains('[MANUAL] OrganizationSyncService: No more items to process, stopping', $this->messages('info'));
		$this->assertContains('[MANUAL] OrganizationSyncService: Optimized manual sync completed', $this->messages('info'));

	}//end testOptimizedManualSyncStopsWhenARoundProcessesNothing()

	/**
	 * The user sync refuses ids that are not positive integers, without touching the database.
	 *
	 * @return void
	 */
	public function testUserSyncRefusesNonNumericIds(): void {
		$this->settings->method('getVoorzieningenConfig')->willReturn(
			['register' => 'abc', 'contactpersoon_schema' => '7']
		);
		$this->db->expects($this->never())->method('getQueryBuilder');
		$this->db->expects($this->never())->method('getDatabasePlatform');

		$this->assertSame([], $this->service->performUserSync());
		$this->assertSame(
			[['warning', 'UserSync: register or contactpersoon_schema is not a valid positive integer', ['register' => 'abc', 'contactSchema' => '7']]],
			$this->logs
		);

	}//end testUserSyncRefusesNonNumericIds()

	/**
	 * The organisation sync refuses ids that are not positive integers, without touching the database.
	 *
	 * @return void
	 */
	public function testOrganizationsSyncRefusesNonPositiveIds(): void {
		$this->settings->method('getVoorzieningenConfig')->willReturn(
			['register' => '-2', 'organisatie_schema' => '4']
		);
		$this->db->expects($this->never())->method('getQueryBuilder');

		$result = $this->service->performOrganizationsSync(batchSize: 12, maxExecutionSeconds: 9);

		$this->assertSame(12, $result['batchSize']);
		$this->assertSame(9, $result['maxExecutionSeconds']);
		$this->assertSame(0, $result['totalRemaining']);
		$this->assertSame(
			[['warning', 'OrganizationSync: register or organisatie_schema is not a valid positive integer', ['register' => '-2', 'organizationSchema' => '4']]],
			$this->logs
		);

	}//end testOrganizationsSyncRefusesNonPositiveIds()

	/**
	 * The recorded sync time is written under the app's last_sync_time key.
	 *
	 * @return void
	 */
	public function testRecordSyncTimeWritesTheLastSyncTime(): void {
		$this->config->expects($this->once())
			->method('setValueString')
			->with('stackiq', 'last_sync_time', $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'))
			->willReturn(true);

		$this->service->recordSyncTime();

	}//end testRecordSyncTimeWritesTheLastSyncTime()
	/**
	 * A scheduled run keeps its record in last_sync_result next to last_sync_time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-002-the-organisation-sync-shall-report-its-progress-and-keep-the-result-of-its-last-run
	 */
	public function testAScheduledRunKeepsItsLastResult(): void {
		$this->settings->method('getVoorzieningenConfig')->willReturn([]);
		$kept = null;
		$this->config->expects($this->once())->method('setValueArray')->with('stackiq', 'last_sync_result', $this->isType('array'))
			->willReturnCallback(function (string $app, string $key, array $value) use (&$kept): bool {
				$kept = $value;
				return true;
			});

		$this->service->performScheduledSync();

		$this->assertSame('scheduled', $kept['trigger']);
		$this->assertSame(0, $kept['organizationsProcessed']);
		$this->assertSame(0, $kept['contactPersonsProcessed']);
		$this->assertArrayHasKey('errorCount', $kept);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $kept['finishedAt']);
	}//end testAScheduledRunKeepsItsLastResult()

	/**
	 * recordSyncTime() with a record also keeps it; without one it writes only the time.
	 *
	 * @return void
	 */
	public function testRecordSyncTimeKeepsAGivenRecord(): void {
		$this->config->expects($this->once())->method('setValueArray')->with('stackiq', 'last_sync_result', ['trigger' => 'manual', 'errorCount' => 0]);

		$this->service->recordSyncTime(result: ['trigger' => 'manual', 'errorCount' => 0]);
	}//end testRecordSyncTimeKeepsAGivenRecord()

}//end class
