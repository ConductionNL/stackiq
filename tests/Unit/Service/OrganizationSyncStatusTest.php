<?php

/**
 * Characterisation tests for OrganizationSyncService::getSyncStatus.
 *
 * Pins the status array (keys, order and values), the order of the object
 * searches and the log lines for every branch of the sync status check.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organization-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Cases for the sync status check.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 *
 * @spec openspec/specs/organization-sync/spec.md
 */
class OrganizationSyncStatusTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Per-case behaviour of the doubles.
	 *
	 * @var array<string, mixed>
	 */
	private array $plan = [];

	/**
	 * The default plan, overridden per case.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaultPlan(): array {
		return [
			'config'       => [
				'register'              => '3',
				'organisatie_schema'    => '4',
				'contactpersoon_schema' => '5',
			],
			'configThrows' => false,
			'all'          => 4,
			'incremental'  => 2,
			'contacts'     => 3,
			'entities'     => 6,
			'entityThrows' => false,
			'searchThrows' => false,
			'emailThrows'  => false,
		];

	}//end defaultPlan()

	/**
	 * Organisation object doubles: every other one carries an `id` in its data.
	 *
	 * @param int $count How many.
	 *
	 * @return array<int, object>
	 */
	private function objects(int $count): array {
		$objects = [];
		for ($i = 0; $i < $count; $i++) {
			$data = [];
			if ($i % 2 === 0) {
				$data = ['id' => 'org-' . $i];
			}

			$objects[] = new class($data, 'id-' . $i) {
				/**
				 * Build the double.
				 *
				 * @param array  $data The object data.
				 * @param string $id   The database id.
				 */
				public function __construct(private array $data, private string $id) {
				}

				/**
				 * @return array
				 */
				public function getObject(): array {
					return $this->data;
				}

				/**
				 * @return string
				 */
				public function getId(): string {
					return $this->id;
				}
			};
		}//end for

		return $objects;

	}//end objects()

	/**
	 * The object service double: organisation searches by window, contact searches by organisation.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query) {
				$updated       = isset($query['@self']['updated']);
				$this->trace[] = [
					'searchObjects',
					$query['@self']['register'],
					$query['@self']['schema'],
					($query['organization'] ?? null),
					$query['_limit'],
					$updated,
				];
				if ($this->plan['searchThrows'] === true) {
					throw new \RuntimeException('search down');
				}

				if (isset($query['organization']) === true) {
					return array_fill(0, $this->plan['contacts'], 'contact');
				}

				if ($updated === true) {
					return $this->objects(count: $this->plan['incremental']);
				}

				return $this->objects(count: $this->plan['all']);
			}
		);

		return $objects;

	}//end objectService()

	/**
	 * Build the service with the plan's doubles.
	 *
	 * @return OrganizationSyncService
	 */
	private function service(): OrganizationSyncService {
		$trace  = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record a log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->trace[] = ['log', $level, (string) $message, array_keys($context)];
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturnCallback(
			function () {
				$this->trace[] = ['getVoorzieningenConfig'];
				if ($this->plan['configThrows'] === true) {
					throw new \RuntimeException('config down');
				}

				return $this->plan['config'];
			}
		);

		// The OpenRegister stub of the mapper does not declare the count query.
		$mapper = $this->getMockBuilder(OrganisationMapper::class)
			->disableOriginalConstructor()
			->addMethods(['findAllWithUserCount'])
			->getMockForAbstractClass();
		if (method_exists(OrganisationMapper::class, 'findAllWithUserCount') === true) {
			$mapper = $this->createMock(originalClassName: OrganisationMapper::class);
		}

		$mapper->method('findAllWithUserCount')->willReturnCallback(
			function () {
				$this->trace[] = ['findAllWithUserCount'];
				if ($this->plan['entityThrows'] === true) {
					throw new \RuntimeException('mapper down');
				}

				return array_fill(0, $this->plan['entities'], 'entity');
			}
		);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				$this->trace[] = ['getValueString', $app, $key, $default];
				return '2026-01-02 03:04:05';
			}
		);

		$email = $this->createMock(originalClassName: SymfonyEmailService::class);
		$email->method('isEmailSystemConfigured')->willReturnCallback(
			function () {
				$this->trace[] = ['isEmailSystemConfigured'];
				if ($this->plan['emailThrows'] === true) {
					throw new \RuntimeException('mail down');
				}

				return ['configured' => true];
			}
		);

		$reflection = new \ReflectionClass(OrganizationSyncService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		$properties = [
			'logger'             => $logger,
			'settingsService'    => $settings,
			'objectService'      => $this->objectService(),
			'organisationMapper' => $mapper,
			'config'             => $config,
			'emailService'       => $email,
		];
		foreach ($properties as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		return $service;

	}//end service()

	/**
	 * Run one case.
	 *
	 * @param array $plan        Overrides of the default plan.
	 * @param int   $minutesBack The window.
	 *
	 * @return array{0: array, 1: array}
	 */
	private function runCase(array $plan, int $minutesBack): array {
		$this->trace = [];
		$this->plan  = array_merge(self::defaultPlan(), $plan);
		$status      = $this->service()->getSyncStatus(minutesBack: $minutesBack);

		return [$status, $this->trace];

	}//end runCase()

	/**
	 * The search trace entries for an organisation window.
	 *
	 * @param bool $updated Whether the window filters on updated.
	 *
	 * @return array
	 */
	private static function windowSearch(bool $updated): array {
		return ['searchObjects', 3, 4, null, 5000, $updated];

	}//end windowSearch()

	/**
	 * The debug lines one organisation window search logs.
	 *
	 * @param bool $updated Whether the window filters on updated.
	 *
	 * @return array
	 */
	private static function windowLogs(bool $updated): array {
		$first = ['log', 'debug', 'OrganizationSyncService: Using searchObjects for all objects', ['register', 'schema', 'query']];
		if ($updated === true) {
			$first = [
				'log',
				'debug',
				'OrganizationSyncService: Using searchObjects with time-based filtering',
				['register', 'schema', 'minutesBack', 'cutoffTime', 'currentTime', 'timeWindowStart', 'query'],
			];
		}

		return [
			$first,
			self::windowSearch(updated: $updated),
			['log', 'debug', 'OrganizationSyncService: Retrieved organisatie objects with searchObjects', ['register', 'schema', 'minutesBack', 'count']],
		];

	}//end windowLogs()

	/**
	 * The trace one contact search makes.
	 *
	 * @param string $organisationId The organisation searched for.
	 *
	 * @return array
	 */
	private static function contactSearch(string $organisationId): array {
		return [
			['searchObjects', 3, 5, $organisationId, 500, false],
			['log', 'debug', 'OrganizationSyncService: Retrieved contact persons on-demand', ['organisatieId', 'register', 'schema', 'count']],
		];

	}//end contactSearch()

	/**
	 * Missing register or organisation schema answers not configured without searching.
	 *
	 * @return void
	 */
	public function testMissingConfigurationIsReportedWithoutSearching(): void {
		$expected = [
			'configured' => false,
			'message'    => 'Sync not configured: missing register or organization schema',
		];
		foreach ([['organisatie_schema' => '4'], ['register' => '3'], ['register' => '', 'organisatie_schema' => '4']] as $config) {
			[$status, $trace] = $this->runCase(plan: ['config' => $config], minutesBack: 10);
			$this->assertSame($expected, $status);
			$this->assertSame([['getVoorzieningenConfig']], $trace);
		}

	}//end testMissingConfigurationIsReportedWithoutSearching()

	/**
	 * An incremental window with contacts predicts contacts per organisation, in order.
	 *
	 * @return void
	 */
	public function testIncrementalWindowPredictsContactsPerOrganisation(): void {
		[$status, $trace] = $this->runCase(plan: [], minutesBack: 10);

		$this->assertSame(
			[
				'configured'                => true,
				'syncMode'                  => 'incremental',
				'timeWindow'                => 10,
				'totalOrganizationObjects'  => 4,
				'totalOrganizationEntities' => 6,
				'organizationsToProcess'    => 2,
				'contactPersonsToProcess'   => 6,
				'efficiencyImprovement'     => '50%',
				'processingReduction'       => 2,
				'contactSchemaConfigured'   => true,
				'lastSyncTime'              => '2026-01-02 03:04:05',
				'emailStatus'               => ['configured' => true],
				'message'                   => 'Ready to process 2 organizations and 6 contact persons',
				'nextScheduledSync'         => 'Will process organizations modified in the last 10 minutes (incremental sync)',
			],
			$status
		);
		$this->assertSame(
			array_merge(
				[['getVoorzieningenConfig']],
				self::windowLogs(updated: false),
				self::windowLogs(updated: true),
				[['findAllWithUserCount']],
				self::contactSearch(organisationId: 'org-0'),
				self::contactSearch(organisationId: 'id-1'),
				[['getValueString', 'stackiq', 'last_sync_time', 'Never'], ['isEmailSystemConfigured']]
			),
			$trace
		);

	}//end testIncrementalWindowPredictsContactsPerOrganisation()

	/**
	 * A full window without a contact schema, no objects and a failing mapper.
	 *
	 * @return void
	 */
	public function testFullWindowWithoutContactSchemaOrObjects(): void {
		[$status, $trace] = $this->runCase(
			plan: [
				'config'       => ['register' => '3', 'organisatie_schema' => '4'],
				'all'          => 0,
				'incremental'  => 0,
				'entityThrows' => true,
			],
			minutesBack: 0
		);

		$this->assertSame(
			[
				'configured'                => true,
				'syncMode'                  => 'full',
				'timeWindow'                => 0,
				'totalOrganizationObjects'  => 0,
				'totalOrganizationEntities' => 0,
				'organizationsToProcess'    => 0,
				'contactPersonsToProcess'   => 0,
				'efficiencyImprovement'     => '0%',
				'processingReduction'       => 0,
				'contactSchemaConfigured'   => false,
				'lastSyncTime'              => '2026-01-02 03:04:05',
				'emailStatus'               => ['configured' => true],
				'message'                   => 'No organizations to process in the current time window',
				'nextScheduledSync'         => 'Will process all organizations (full sync)',
			],
			$status
		);
		$this->assertSame(
			array_merge(
				[['getVoorzieningenConfig']],
				self::windowLogs(updated: false),
				self::windowLogs(updated: false),
				[['findAllWithUserCount']],
				[['getValueString', 'stackiq', 'last_sync_time', 'Never'], ['isEmailSystemConfigured']]
			),
			$trace
		);

	}//end testFullWindowWithoutContactSchemaOrObjects()

	/**
	 * Large counts are abbreviated, a negative window reads as incremental mode but a full sync.
	 *
	 * @return void
	 */
	public function testLargeCountsAndNegativeWindow(): void {
		[$status] = $this->runCase(
			plan: ['all' => 3000, 'incremental' => 1500, 'contacts' => 1],
			minutesBack: -5
		);

		$this->assertSame('incremental', $status['syncMode']);
		$this->assertSame(-5, $status['timeWindow']);
		$this->assertSame(3000, $status['organizationsToProcess']);
		$this->assertSame(3000, $status['contactPersonsToProcess']);
		$this->assertSame('0%', $status['efficiencyImprovement']);
		$this->assertSame(0, $status['processingReduction']);
		$this->assertSame('Ready to process 3.0k organizations and 3.0k contact persons', $status['message']);
		$this->assertSame('Will process all organizations (full sync)', $status['nextScheduledSync']);

		[$status] = $this->runCase(
			plan: ['all' => 3000, 'incremental' => 1500, 'contacts' => 1],
			minutesBack: 30
		);
		$this->assertSame('Ready to process 1.5k organizations and 1.5k contact persons', $status['message']);
		$this->assertSame(50.0, (float) rtrim($status['efficiencyImprovement'], '%'));
		$this->assertSame('50%', $status['efficiencyImprovement']);

		[$status] = $this->runCase(plan: ['all' => 3, 'incremental' => 1, 'contacts' => 0], minutesBack: 30);
		$this->assertSame('66.7%', $status['efficiencyImprovement']);
		$this->assertSame('Ready to process 1 organizations and 0 contact persons', $status['message']);

	}//end testLargeCountsAndNegativeWindow()

	/**
	 * Failing searches and e-mail checks degrade to empty results and an e-mail error status.
	 *
	 * @return void
	 */
	public function testFailingSearchAndEmailDegrade(): void {
		[$status, $trace] = $this->runCase(plan: ['searchThrows' => true, 'emailThrows' => true], minutesBack: 10);

		$this->assertSame(0, $status['totalOrganizationObjects']);
		$this->assertSame(0, $status['organizationsToProcess']);
		$this->assertSame(
			[
				'configured'     => false,
				'reason'         => 'Error checking email configuration: mail down',
				'hasCredentials' => false,
				'hasTemplates'   => false,
			],
			$status['emailStatus']
		);
		$this->assertSame(['log', 'warning', 'OrganizationSyncService: Failed to check email configuration status', ['error']], end($trace));

	}//end testFailingSearchAndEmailDegrade()

	/**
	 * An exception from the configuration is reported as a status error.
	 *
	 * @return void
	 */
	public function testExceptionIsReportedAsStatusError(): void {
		[$status, $trace] = $this->runCase(plan: ['configThrows' => true], minutesBack: 10);

		$this->assertSame(
			[
				'configured' => false,
				'message'    => 'Error checking sync status: config down',
			],
			$status
		);
		$this->assertSame([['getVoorzieningenConfig']], $trace);

	}//end testExceptionIsReportedAsStatusError()
}//end class
