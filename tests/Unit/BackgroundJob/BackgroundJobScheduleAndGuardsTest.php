<?php

/**
 * Unit tests for the background jobs' schedules and the organisation sync
 * job's guards: the EOL interval floor and default, the federation interval,
 * the five-minute organisation sync, its skip without OpenRegister, and the
 * protected contactsUid link refresh.
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
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\BackgroundJob\EolSyncJob;
use OCA\Stackiq\BackgroundJob\FederationSyncJob;
use OCA\Stackiq\BackgroundJob\OrganizationContactSyncJob;
use OCA\Stackiq\Service\Contactpersoon\SystemContactWrites;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\Federation\FederationConfig;
use OCA\Stackiq\Service\Federation\FederationService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Schedules of the sync jobs and the guards of the organisation sync job.
 */
#[CoversClass(EolSyncJob::class)]
#[CoversClass(FederationSyncJob::class)]
#[CoversClass(OrganizationContactSyncJob::class)]
#[UsesClass(SystemContactWrites::class)]
final class BackgroundJobScheduleAndGuardsTest extends TestCase {

	/**
	 * Every log line, as level, message and context.
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $logs = [];

	/**
	 * A logger that records info and error lines.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * Configured EOL intervals and the interval the job runs at.
	 *
	 * @return array<string, array{0:array<string,mixed>,1:int}>
	 */
	public static function eolIntervals(): array {
		return [
			'unset is daily'         => [[], 86400],
			'hourly'                 => [['intervalSeconds' => 3600], 3600],
			'numeric string'         => [['intervalSeconds' => '7200'], 7200],
			'too tight is floored'   => [['intervalSeconds' => 60], 300],
			'zero is floored'        => [['intervalSeconds' => 0], 300],
			'negative is floored'    => [['intervalSeconds' => -100], 300],
			'exactly the floor'      => [['intervalSeconds' => 300], 300],
			'null is daily'          => [['intervalSeconds' => null], 86400],
		];
	}//end eolIntervals()

	/**
	 * The EOL job runs at the configured interval, daily by default and never tighter than five minutes.
	 *
	 * @param array<string,mixed> $config   The EOL sync config.
	 * @param int                 $interval The interval the job must run at.
	 *
	 * @return void
	 */
	#[DataProvider('eolIntervals')]
	public function testTheEolIntervalIsFlooredAtFiveMinutes(array $config, int $interval): void {
		$service = $this->createMock(EolSyncService::class);
		$service->expects($this->once())->method('getConfig')->willReturn($config);

		$job = new EolSyncJob($this->createMock(ITimeFactory::class), $service, $this->logger());

		$this->assertSame($interval, $job->getInterval());
	}//end testTheEolIntervalIsFlooredAtFiveMinutes()

	/**
	 * The federation job runs at the interval its config names.
	 *
	 * @return void
	 */
	public function testTheFederationIntervalComesFromItsConfig(): void {
		$config = $this->createMock(FederationConfig::class);
		$config->expects($this->once())->method('getSyncInterval')->willReturn(1800);

		$job = new FederationSyncJob($this->createMock(ITimeFactory::class), $this->createMock(FederationService::class), $config, $this->logger());

		$this->assertSame(1800, $job->getInterval());
	}//end testTheFederationIntervalComesFromItsConfig()

	/**
	 * The organisation sync job under test.
	 *
	 * @param OrganizationSyncService&MockObject      $sync      The organisation sync.
	 * @param StackiqContactSyncService&MockObject    $contacts  The contacts sync.
	 * @param RegisterConfigReaderInterface&MockObject $settings The register config.
	 * @param CronjobSettings&MockObject              $cronjobs  The cronjob settings.
	 * @param list<string>                            $installed The installed apps.
	 *
	 * @return OrganizationContactSyncJob
	 */
	private function organisationJob(
		OrganizationSyncService&MockObject $sync,
		StackiqContactSyncService&MockObject $contacts,
		RegisterConfigReaderInterface&MockObject $settings,
		CronjobSettings&MockObject $cronjobs,
		array $installed = ['openregister', 'stackiq'],
	): OrganizationContactSyncJob {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed);

		return new OrganizationContactSyncJob(
			$this->createMock(ITimeFactory::class),
			$sync,
			$contacts,
			$settings,
			$cronjobs,
			$apps,
			$this->logger(),
		);
	}//end organisationJob()

	/**
	 * Run a job's protected run().
	 *
	 * @param object $job The job.
	 *
	 * @return void
	 */
	private function runJob(object $job): void {
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The organisation sync runs every five minutes.
	 *
	 * @return void
	 */
	public function testTheOrganisationSyncRunsEveryFiveMinutes(): void {
		$job = $this->organisationJob(
			$this->createMock(OrganizationSyncService::class),
			$this->createMock(StackiqContactSyncService::class),
			$this->createMock(RegisterConfigReaderInterface::class),
			$this->createMock(CronjobSettings::class),
		);

		$this->assertSame(300, $job->getInterval());
	}//end testTheOrganisationSyncRunsEveryFiveMinutes()

	/**
	 * Without OpenRegister the organisation sync skips before reading its cronjob setting.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheOrganisationSyncSkips(): void {
		$sync = $this->createMock(OrganizationSyncService::class);
		$sync->expects($this->never())->method('performScheduledSync');
		$cronjobs = $this->createMock(CronjobSettings::class);
		$cronjobs->expects($this->never())->method('isCronjobEnabled');
		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->expects($this->never())->method('isAvailable');

		$this->runJob(
			$this->organisationJob($sync, $contacts, $this->createMock(RegisterConfigReaderInterface::class), $cronjobs, ['stackiq'])
		);

		$this->assertSame([['info', '[OrganizationContactSyncJob] OpenRegister not installed, skipping sync', []]], $this->logs);
	}//end testWithoutOpenRegisterTheOrganisationSyncSkips()

	/**
	 * A link refresh that throws is logged with its message, file and line, and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailingLinkRefreshIsProtected(): void {
		$cronjobs = $this->createMock(CronjobSettings::class);
		$cronjobs->method('isCronjobEnabled')->with('organization_contact_sync')->willReturn(true);
		$sync = $this->createMock(OrganizationSyncService::class);
		$sync->expects($this->once())->method('performScheduledSync');
		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->method('isAvailable')->willThrowException(new RuntimeException('contacts backend down'));

		$this->runJob($this->organisationJob($sync, $contacts, $this->createMock(RegisterConfigReaderInterface::class), $cronjobs));

		$this->assertSame(
			[
				['info', '[OrganizationContactSyncJob] Starting scheduled organization sync'],
				['error', '[OrganizationContactSyncJob] Fatal error during contactsUid link refresh — cron pass protected'],
			],
			array_map(static fn (array $log): array => [$log[0], $log[1]], $this->logs)
		);
		$this->assertSame('contacts backend down', $this->logs[1][2]['error']);
		$this->assertSame(['error', 'file', 'line'], array_keys($this->logs[1][2]));
		$this->assertIsInt($this->logs[1][2]['line']);
	}//end testAFailingLinkRefreshIsProtected()

	/**
	 * Without an object service, or without schemas for the contact and organisation types, nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutObjectServiceOrSchemasNoLinkIsRead(): void {
		$cronjobs = $this->createMock(CronjobSettings::class);
		$cronjobs->method('isCronjobEnabled')->willReturn(true);
		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->method('isAvailable')->willReturn(true);

		$withoutService = $this->createMock(RegisterConfigReaderInterface::class);
		$withoutService->method('getVoorzieningenRegisterId')->willReturn(4);
		$withoutService->method('getObjectService')->willReturn(null);
		$withoutService->expects($this->never())->method('getSchemaIdForObjectType');
		$this->runJob($this->organisationJob($this->createMock(OrganizationSyncService::class), $contacts, $withoutService, $cronjobs));

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('setRegister');
		$withoutSchemas = $this->createMock(RegisterConfigReaderInterface::class);
		$withoutSchemas->method('getVoorzieningenRegisterId')->willReturn(4);
		$withoutSchemas->method('getObjectService')->willReturn($objects);
		$asked = [];
		$withoutSchemas->method('getSchemaIdForObjectType')->willReturnCallback(
			static function (string $type) use (&$asked): ?int {
				$asked[] = $type;
				return null;
			}
		);
		$this->runJob($this->organisationJob($this->createMock(OrganizationSyncService::class), $contacts, $withoutSchemas, $cronjobs));

		$this->assertSame(['contactPerson', 'organization'], $asked);
		$this->assertSame([], array_filter($this->logs, static fn (array $log): bool => $log[0] === 'error'));
	}//end testWithoutObjectServiceOrSchemasNoLinkIsRead()
}//end class
