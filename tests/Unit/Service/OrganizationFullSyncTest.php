<?php

/**
 * Characterisation tests for OrganizationSyncService::performFullSync.
 *
 * Pins the statistics and log lines of a full or incremental organisation
 * sync: a missing configuration, objects that are processed and objects
 * whose processing fails, and a failure of the sync itself. Times are
 * checked by format, not by value.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Cases for the full organisation sync.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
class OrganizationFullSyncTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * An organisatie object double; `broken` ones fail as soon as they are read.
	 *
	 * @param int  $id     The object id.
	 * @param bool $broken Whether reading it fails.
	 *
	 * @return object
	 */
	private static function organisatie(int $id, bool $broken): object {
		return new class($id, $broken) {
			/**
			 * Build the double.
			 *
			 * @param int  $id     The object id.
			 * @param bool $broken Whether reading it fails.
			 */
			public function __construct(private int $id, private bool $broken) {
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				if ($this->broken === true) {
					throw new \RuntimeException('unreadable ' . $this->id);
				}

				return ['id' => 'org-' . $this->id, 'name' => 'Org ' . $this->id];
			}

			/**
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return 'org-' . $this->id;
			}

			/**
			 * @return string
			 */
			public function getRegister(): string {
				return '3';
			}

			/**
			 * @return string
			 */
			public function getSchema(): string {
				return '4';
			}
		};

	}//end organisatie()

	/**
	 * Run the full sync.
	 *
	 * @param array $config      The voorzieningen configuration (or an exception to throw).
	 * @param array $objects     The organisatie objects the search answers.
	 * @param int   $minutesBack The window.
	 *
	 * @return array The statistics.
	 */
	private function sync(array|\Throwable $config, array $objects, int $minutesBack): array {
		$this->trace = [];
		$trace       = &$this->trace;
		$logger      = new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record a log line: level, message and context keys.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->trace[] = [$level, (string) $message, array_keys($context)];
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturnCallback(
			function () use ($config) {
				if ($config instanceof \Throwable) {
					throw $config;
				}

				return $config;
			}
		);

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use ($objects) {
				if (isset($query['organization']) === true) {
					return [];
				}

				return $objects;
			}
		);
		$objectService->method('find')->willThrowException(new \RuntimeException('find not under test'));

		$mapper = $this->createMock(originalClassName: OrganisationMapper::class);
		$mapper->method('findByUuid')->willThrowException(new \RuntimeException('mapper not under test'));

		$reflection = new \ReflectionClass(OrganizationSyncService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		foreach (['logger' => $logger, 'settingsService' => $settings, 'objectService' => $objectService, 'organisationMapper' => $mapper] as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		return $service->performFullSync($minutesBack);

	}//end sync()

	/**
	 * Assert the statistics, with the times checked by format.
	 *
	 * @param array $expected The statistics without times.
	 * @param array $stats    The statistics answered.
	 * @param bool  $finished Whether the sync got to the end (and so has a duration).
	 *
	 * @return void
	 */
	private function assertStats(array $expected, array $stats, bool $finished): void {
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stats['startTime']);
		if ($finished === true) {
			$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $stats['endTime']);
			$this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', (string) $stats['duration']);
		}

		$this->assertSame(
			['organizationsProcessed', 'entitiesCreated', 'entitiesUpdated', 'contactPersonsProcessed', 'usersCreated', 'usersUpdated', 'errors', 'startTime', 'endTime', 'duration'],
			array_keys($stats)
		);
		unset($stats['startTime'], $stats['endTime'], $stats['duration']);
		$this->assertSame($expected, $stats);

	}//end assertStats()

	/**
	 * The counters of a sync that did nothing yet.
	 *
	 * @param int   $processed The organisatie objects processed.
	 * @param array $errors    The errors.
	 *
	 * @return array
	 */
	private static function counters(int $processed, array $errors): array {
		return [
			'organizationsProcessed'  => $processed,
			'entitiesCreated'         => 0,
			'entitiesUpdated'         => 0,
			'contactPersonsProcessed' => 0,
			'usersCreated'            => 0,
			'usersUpdated'            => 0,
			'errors'                  => $errors,
		];

	}//end counters()

	/**
	 * A sync without register or organisation schema stops with an error.
	 *
	 * @return void
	 */
	public function testMissingConfigurationStopsTheSync(): void {
		foreach ([['register' => '3'], ['organisatie_schema' => '4'], ['register' => '', 'organisatie_schema' => '4']] as $config) {
			$stats = $this->sync(config: $config, objects: [], minutesBack: 10);
			$error = 'Missing configuration: register or organization schema not configured';
			$this->assertStats(expected: self::counters(processed: 0, errors: [$error]), stats: $stats, finished: false);
			$this->assertNull($stats['endTime']);
			$this->assertNull($stats['duration']);
			$this->assertSame(
				[
					['info', 'OrganizationSyncService: Starting comprehensive organization synchronization', ['minutesBack', 'syncMode']],
					['error', 'OrganizationSyncService: ' . $error, []],
				],
				$this->trace
			);
		}

	}//end testMissingConfigurationStopsTheSync()

	/**
	 * Every object is processed; one that fails is recorded and the rest continue.
	 *
	 * @return void
	 */
	public function testEveryObjectIsProcessedAndFailuresAreRecorded(): void {
		$stats = $this->sync(
			config: ['register' => '3', 'organisatie_schema' => '4', 'contactpersoon_schema' => '5'],
			objects: [self::organisatie(id: 1, broken: false), self::organisatie(id: 2, broken: true), self::organisatie(id: 3, broken: false)],
			minutesBack: 0
		);

		$failure = 'Failed to process organisatie object 2: unreadable 2';
		$this->assertStats(expected: self::counters(processed: 2, errors: [$failure]), stats: $stats, finished: true);

		$messages = array_map(fn (array $line) => $line[0] . ' ' . $line[1], $this->trace);
		$this->assertSame('info OrganizationSyncService: Starting comprehensive organization synchronization', $messages[0]);
		$this->assertSame(['info', 'OrganizationSyncService: Found organisatie objects', ['count', 'minutesBack', 'syncMode']], $this->trace[3]);
		$this->assertContains(['error', 'OrganizationSyncService: ' . $failure, ['exception', 'objectId']], $this->trace);
		$this->assertSame(
			['info', 'OrganizationSyncService: Completed comprehensive synchronization', ['organizationsProcessed', 'entitiesCreated', 'entitiesUpdated', 'contactPersonsProcessed', 'usersCreated', 'usersUpdated', 'errors', 'startTime', 'endTime', 'duration']],
			end($this->trace)
		);
		$this->assertSame(2, count(array_keys($messages, 'warning OrganizationSyncService: Could not ensure organisation entity', true)));

	}//end testEveryObjectIsProcessedAndFailuresAreRecorded()

	/**
	 * The sync mode is full for a window of 0, incremental otherwise, in both log lines.
	 *
	 * @return void
	 */
	public function testTheSyncModeFollowsTheWindow(): void {
		foreach ([0 => 'full', 10 => 'incremental', -1 => 'incremental'] as $minutesBack => $mode) {
			$this->trace = [];
			$logged      = [];
			$logger      = $this->createMock(originalClassName: \Psr\Log\LoggerInterface::class);
			$logger->method('info')->willReturnCallback(
				function (string $message, array $context=[]) use (&$logged) {
					$logged[] = [$message, ($context['syncMode'] ?? null), ($context['minutesBack'] ?? null)];
				}
			);

			$settings = $this->createMock(originalClassName: SettingsService::class);
			$settings->method('getVoorzieningenConfig')->willReturn(['register' => '3', 'organisatie_schema' => '4']);
			$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
			$objectService->method('searchObjects')->willReturn([]);

			$reflection = new \ReflectionClass(OrganizationSyncService::class);
			$service    = $reflection->newInstanceWithoutConstructor();
			foreach (['logger' => $logger, 'settingsService' => $settings, 'objectService' => $objectService] as $name => $value) {
				$reflection->getProperty($name)->setValue($service, $value);
			}

			$stats = $service->performFullSync($minutesBack);
			$this->assertSame([], $stats['errors']);
			$this->assertSame(
				[
					['OrganizationSyncService: Starting comprehensive organization synchronization', $mode, $minutesBack],
					['OrganizationSyncService: Found organisatie objects', $mode, $minutesBack],
					['OrganizationSyncService: Completed comprehensive synchronization', null, null],
				],
				$logged
			);
		}//end foreach

	}//end testTheSyncModeFollowsTheWindow()

	/**
	 * A failure of the sync itself is recorded with its end time.
	 *
	 * @return void
	 */
	public function testAFailingSyncIsRecorded(): void {
		$stats = $this->sync(config: new \RuntimeException('settings down'), objects: [], minutesBack: 10);

		$this->assertStats(expected: self::counters(processed: 0, errors: ['Synchronization failed: settings down']), stats: $stats, finished: false);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $stats['endTime']);
		$this->assertNull($stats['duration']);
		$this->assertSame(
			[
				['info', 'OrganizationSyncService: Starting comprehensive organization synchronization', ['minutesBack', 'syncMode']],
				['error', 'OrganizationSyncService: Synchronization failed: settings down', ['exception']],
			],
			$this->trace
		);

	}//end testAFailingSyncIsRecorded()
}//end class
