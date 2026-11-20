<?php

/**
 * A module's publication copied onto its versions, and only when it differs.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\BackgroundJob\ModuleVersionPublicationJob;
use OCA\Stackiq\EventListener\ModuleVersionPublicationListener;
use OCA\Stackiq\Service\ModuleVersionPublicationService;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Stackiq\Tests\Support\LegacyObjectArgs;

/**
 * Asserts what the mirror writes, and that the listener reaches it.
 */
class ModuleVersionPublicationServiceTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger double of the current service.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The jobs the service queued.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * The retries the job scheduled: argument and run-after time.
	 *
	 * @var array<int, array{0: mixed, 1: int}>
	 */
	private array $retries = [];

	/**
	 * The job list double, shared by the service and the job.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobList;

	/**
	 * The service of the current test.
	 *
	 * @var ModuleVersionPublicationService
	 */
	private ModuleVersionPublicationService $publication;

	/**
	 * The settings double of the current test.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The container double of the current test.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * An object with the six accessors of OpenRegister's entity contract.
	 *
	 * @param string               $uuid   The id.
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The data.
	 *
	 * @return ObjectEntityInterface
	 */
	private static function entity(string $uuid, string $schema, array $data): ObjectEntityInterface {
		return new class ($uuid, $schema, $data) implements ObjectEntityInterface {

			/**
			 * Constructor.
			 *
			 * @param string               $uuid   The id.
			 * @param string               $schema The schema id.
			 * @param array<string, mixed> $data   The data.
			 */
			public function __construct(private string $uuid, private string $schema, private array $data) {
			}//end __construct()

			/**
			 * @return string|null
			 */
			public function getUuid(): ?string {
				return $this->uuid;
			}//end getUuid()

			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}//end getObject()

			/**
			 * @return string|null
			 */
			public function getRegister(): ?string {
				return '20';
			}//end getRegister()

			/**
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return $this->schema;
			}//end getSchema()

			/**
			 * @return string|null
			 */
			public function getOrganisation(): ?string {
				return null;
			}//end getOrganisation()

			/**
			 * @return string|null
			 */
			public function getOwner(): ?string {
				return null;
			}//end getOwner()

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}//end jsonSerialize()
		};
	}//end entity()

	/**
	 * The service, with module schema 43 and moduleVersion schema 46.
	 *
	 * @return ModuleVersionPublicationService
	 */
	private function service(): ModuleVersionPublicationService {
		$settings = $this->getMockBuilder(className: SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchemaIdForObjectType', 'getRegisterIdForObjectType'])
			->getMock();
		$settings->method('getSchemaIdForObjectType')->willReturnMap([['module', 43], ['moduleVersion', 46]]);
		$settings->method('getRegisterIdForObjectType')->willReturn(20);

		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$container     = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument): void {
				$this->queued[] = [$job, $argument];
			}
		);
		$jobList->method('scheduleAfter')->willReturnCallback(
			function (string $job, int $runAfter, mixed $argument): void {
				$this->assertSame(expected: ModuleVersionPublicationJob::class, actual: $job);
				$this->retries[] = [$argument, $runAfter];
			}
		);
		$this->jobList = $jobList;

		$this->settings    = $settings;
		$this->container   = $container;
		$this->publication = new ModuleVersionPublicationService(
			settingsService: $settings,
			container: $container,
			logger: $this->logger,
			jobList: $jobList
		);
		return $this->publication;
	}//end service()

	/**
	 * Run the jobs the service queued, the way cron runs them.
	 *
	 * @return void
	 */
	private function runQueuedJobs(): void {
		foreach ($this->queued as [$class, $argument]) {
			$this->assertSame(expected: ModuleVersionPublicationJob::class, actual: $class);
			$time = $this->createMock(originalClassName: ITimeFactory::class);
			$time->method('getTime')->willReturn(1000);
			$job = new ModuleVersionPublicationJob(
				time: $time,
				publication: $this->publication,
				settingsService: $this->settings,
				container: $this->container,
				logger: $this->logger,
				jobList: $this->jobList
			);
			$run = new \ReflectionMethod($job, 'run');
			$run->invoke($job, $argument);
		}

		$this->queued = [];
	}//end runQueuedJobs()

	/**
	 * Publishing a module writes its date onto the versions that differ, and leaves the one already in step.
	 *
	 * @return void
	 */
	public function testAPublishedModuleReachesTheVersionsThatDiffer(): void {
		$service = $this->service();
		$stale   = self::entity(uuid: 'v-1', schema: '46', data: ['module' => 'm-1', 'version' => '1.0']);
		$current = self::entity(
			uuid: 'v-2',
			schema: '46',
			data: [
				'module' => 'm-1',
				'version' => '2.0',
				'modulePublicationDate' => '2026-09-01T00:00:00+00:00',
				'moduleRegisteredBy' => 'Municipality',
			]
		);
		$this->objects->method('searchObjects')->willReturn([$stale, $current]);

		$written = [];
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function (...$args) use (&$written, $stale) {
				$args = LegacyObjectArgs::positional(method: 'saveObject', args: $args);
				$written[] = $args;
				return $stale;
			}
		);

		$module = self::entity(
			uuid: 'm-1',
			schema: '43',
			data: ['name' => 'Zaaksysteem', 'publicationDate' => '2026-09-01T00:00:00+00:00', 'registeredBy' => 'Municipality']
		);
		$this->objects->method('find')->willReturn($module);
		$this->assertSame(
			expected: 0,
			actual: $service->objectSaved(object: $module),
			message: 'the request that saved the module writes no version'
		);
		$this->assertSame(expected: [], actual: $written);
		$this->assertSame(expected: [[ModuleVersionPublicationJob::class, ['module' => 'm-1', 'deleted' => false]]], actual: $this->queued);

		$this->runQueuedJobs();

		$this->assertSame(expected: 'v-1', actual: $written[0][4], message: 'only the stale version is written');
		$this->assertSame(expected: '2026-09-01T00:00:00+00:00', actual: $written[0][0]['modulePublicationDate']);
		$this->assertSame(expected: 'Municipality', actual: $written[0][0]['moduleRegisteredBy']);
		$this->assertSame(expected: '1.0', actual: $written[0][0]['version'], message: 'the version keeps its own data');
		$this->assertFalse(condition: $written[0][8], message: 'saved without validation, so older version data never blocks the mirror');
	}//end testAPublishedModuleReachesTheVersionsThatDiffer()

	/**
	 * Depublishing a module clears the date on its versions, so they stop being public.
	 *
	 * @return void
	 */
	public function testADepublishedModuleClearsItsVersions(): void {
		$service = $this->service();
		$this->objects->method('searchObjects')->willReturn(
			[
				self::entity(
					uuid: 'v-1',
					schema: '46',
					data: ['module' => 'm-1', 'modulePublicationDate' => '2026-09-01T00:00:00+00:00', 'moduleRegisteredBy' => 'Municipality']
				),
			]
		);
		$this->objects->expects($this->once())->method('saveObject')->with(...LegacyObjectArgs::with(method: 'saveObject', expected: [
				'object' => $this->callback(callback: static fn (array $data): bool => $data['modulePublicationDate'] === null),
			]));
		$module = self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Municipality']);
		$this->objects->method('find')->willReturn($module);

		$service->objectSaved(object: $module);
		$this->runQueuedJobs();
	}//end testADepublishedModuleClearsItsVersions()

	/**
	 * A new version reads its module; a version already in step is not written, which ends the event chain.
	 *
	 * @return void
	 */
	public function testAVersionReadsItsModuleAndStopsWhenInStep(): void {
		$service = $this->service();
		$this->objects->method('find')->willReturn(self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Supplier']));
		$this->objects->expects($this->once())->method('saveObject')->with(...LegacyObjectArgs::with(method: 'saveObject', expected: [
				'object' => $this->callback(callback: static fn (array $data): bool => $data['moduleRegisteredBy'] === 'Supplier'),
			]));

		$this->assertSame(expected: 1, actual: $service->objectSaved(object: self::entity(uuid: 'v-9', schema: '46', data: ['module' => 'm-1'])));
		$this->assertSame(
			expected: 0,
			actual: $service->objectSaved(
				object: self::entity(
					uuid: 'v-9',
					schema: '46',
					data: ['module' => 'm-1', 'modulePublicationDate' => null, 'moduleRegisteredBy' => 'Supplier']
				)
			)
		);
		$this->assertSame(
			expected: 0,
			actual: $service->objectSaved(object: self::entity(uuid: 'x-1', schema: '99', data: ['module' => 'm-1'])),
			message: 'other schemas are ignored'
		);
	}//end testAVersionReadsItsModuleAndStopsWhenInStep()

	/**
	 * A module with more versions than one search returns is read page by page, so every version follows it.
	 *
	 * @return void
	 */
	public function testAModuleWithManyVersionsIsReadPageByPage(): void {
		$service = $this->service();
		$page    = [];
		for ($i = 0; $i < ModuleVersionPublicationService::VERSION_LIMIT; $i++) {
			$page[] = self::entity(uuid: 'v-' . $i, schema: '46', data: ['module' => 'm-1']);
		}

		$offsets = [];
		$this->objects->method('searchObjects')->willReturnCallback(
			static function (array $query) use (&$offsets, $page): array {
				$offsets[] = $query['_offset'];
				return match ($query['_offset']) {
					0 => $page,
					ModuleVersionPublicationService::VERSION_LIMIT => [self::entity(uuid: 'v-last', schema: '46', data: ['module' => 'm-1'])],
					default => [],
				};
			}
		);
		$this->objects->expects(
			$this->exactly(count: ModuleVersionPublicationService::VERSION_LIMIT + 1)
		)->method('saveObject')->willReturn($page[0]);

		$written = $service->backfillModule(module: self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Supplier']))['written'];

		$this->assertSame(expected: ModuleVersionPublicationService::VERSION_LIMIT + 1, actual: $written);
		$this->assertSame(expected: [0, ModuleVersionPublicationService::VERSION_LIMIT], actual: $offsets);
	}//end testAModuleWithManyVersionsIsReadPageByPage()

	/**
	 * A module update that leaves its publication as it was does not search its versions.
	 *
	 * @return void
	 */
	public function testAModuleUpdateWithoutAPublicationChangeLeavesTheVersions(): void {
		$service = $this->service();
		$this->objects->expects($this->never())->method('searchObjects');

		$before = self::entity(
			uuid: 'm-1',
			schema: '43',
			data: ['name' => 'Zaaksysteem', 'publicationDate' => '2026-09-01T00:00:00+00:00', 'registeredBy' => 'Municipality']
		);
		$after  = self::entity(
			uuid: 'm-1',
			schema: '43',
			data: ['name' => 'Zaaksysteem 2', 'publicationDate' => '2026-09-01T00:00:00+00:00', 'registeredBy' => 'Municipality']
		);

		$this->assertSame(expected: 0, actual: $service->objectSaved(object: $after, previous: $before));
		$this->assertSame(expected: [], actual: $this->queued, message: 'nothing is queued');
	}//end testAModuleUpdateWithoutAPublicationChangeLeavesTheVersions()

	/**
	 * Deleting a module clears its versions, so they stop being public.
	 *
	 * @return void
	 */
	public function testADeletedModuleClearsItsVersions(): void {
		$service = $this->service();
		$this->objects->method('searchObjects')->willReturn(
			[self::entity(uuid: 'v-1', schema: '46', data: ['module' => 'm-1', 'moduleRegisteredBy' => 'Supplier'])]
		);
		$this->objects->expects($this->once())->method('saveObject')->with(...LegacyObjectArgs::with(method: 'saveObject', expected: [
				'object' => $this->callback(
					callback: static fn (array $data): bool => $data['modulePublicationDate'] === null && $data['moduleRegisteredBy'] === null
				),
			]));

		$this->objects->expects($this->never())->method('find');

		$this->assertSame(
			expected: 0,
			actual: $service->objectDeleted(object: self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Supplier']))
		);
		$this->assertSame(
			expected: 0,
			actual: $service->objectDeleted(object: self::entity(uuid: 'v-1', schema: '46', data: ['module' => 'm-1'])),
			message: 'only a module clears versions'
		);
		$this->assertSame(expected: [[ModuleVersionPublicationJob::class, ['module' => 'm-1', 'deleted' => true]]], actual: $this->queued);

		$this->runQueuedJobs();
	}//end testADeletedModuleClearsItsVersions()

	/**
	 * A module that no longer exists when the job runs takes its versions out of public view.
	 *
	 * @return void
	 */
	public function testAModuleGoneByTheTimeTheJobRunsClearsItsVersions(): void {
		$service = $this->service();
		// OpenRegister's find() throws for a missing object; it does not return null.
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->objects->method('searchObjects')->willReturn(
			[self::entity(uuid: 'v-1', schema: '46', data: ['module' => 'm-1', 'moduleRegisteredBy' => 'Supplier'])]
		);
		$this->objects->expects($this->once())->method('saveObject')->with(...LegacyObjectArgs::with(method: 'saveObject', expected: [
				'object' => $this->callback(
					callback: static fn (array $data): bool => $data['modulePublicationDate'] === null && $data['moduleRegisteredBy'] === null
				),
			]));

		$service->objectSaved(object: self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Supplier']));
		$this->runQueuedJobs();

		$this->assertSame(expected: [], actual: $this->retries, message: 'a module that is gone is not a failure');
	}//end testAModuleGoneByTheTimeTheJobRunsClearsItsVersions()

	/**
	 * A module that cannot be read is tried again a few minutes later, and given up after the last try.
	 *
	 * @return void
	 */
	public function testAModuleThatCannotBeReadIsTriedAgain(): void {
		$service = $this->service();
		$this->objects->method('find')->willThrowException(new \RuntimeException('database went away'));
		$this->objects->expects($this->never())->method('searchObjects');
		$this->objects->expects($this->never())->method('saveObject');
		$this->logger->expects(
			$this->exactly(count: ModuleVersionPublicationJob::MAX_ATTEMPTS)
		)->method('error')->with($this->stringContains(string: 'tried again later'));
		$this->logger->expects($this->once())->method('critical')->with($this->stringContains(string: 'gave up'));

		$service->objectSaved(object: self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Supplier']));
		$this->runQueuedJobs();

		$this->assertSame(
			expected: [[['module' => 'm-1', 'deleted' => false, 'attempt' => 2], 1000 + ModuleVersionPublicationJob::RETRY_DELAY]],
			actual: $this->retries
		);

		for ($attempt = 2; $attempt <= ModuleVersionPublicationJob::MAX_ATTEMPTS; $attempt++) {
			$this->queued  = [[ModuleVersionPublicationJob::class, $this->retries[array_key_last($this->retries)][0]]];
			$this->runQueuedJobs();
		}

		$this->assertCount(
			expectedCount: ModuleVersionPublicationJob::MAX_ATTEMPTS - 1,
			haystack: $this->retries,
			message: 'no retry after the last try'
		);
	}//end testAModuleThatCannotBeReadIsTriedAgain()

	/**
	 * A version that cannot be written during the job's copy is tried again.
	 *
	 * @return void
	 */
	public function testAFailedVersionWriteInTheJobIsTriedAgain(): void {
		$service = $this->service();
		$this->objects->method('find')->willReturn(self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Municipality']));
		$this->objects->method('searchObjects')->willReturn(
			[self::entity(uuid: 'v-1', schema: '46', data: ['module' => 'm-1', 'moduleRegisteredBy' => 'Supplier'])]
		);
		$this->objects->method('saveObject')->willThrowException(new \RuntimeException('lock wait timeout'));

		$service->objectDeleted(object: self::entity(uuid: 'm-1', schema: '43', data: []));
		$this->runQueuedJobs();

		$this->assertSame(expected: [['module' => 'm-1', 'deleted' => true, 'attempt' => 2]], actual: array_column($this->retries, 0));
	}//end testAFailedVersionWriteInTheJobIsTriedAgain()

	/**
	 * A version that cannot be written while a published module is copied onto it is tried again too.
	 *
	 * @return void
	 */
	public function testAFailedBackfillInTheJobIsTriedAgain(): void {
		$service = $this->service();
		$this->objects->method('find')->willReturn(self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Supplier']));
		$this->objects->method('searchObjects')->willReturn([self::entity(uuid: 'v-1', schema: '46', data: ['module' => 'm-1'])]);
		$this->objects->method('saveObject')->willThrowException(new \RuntimeException('lock wait timeout'));

		$service->objectSaved(object: self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Supplier']));
		$this->runQueuedJobs();

		$this->assertSame(expected: [['module' => 'm-1', 'deleted' => false, 'attempt' => 2]], actual: array_column($this->retries, 0));
	}//end testAFailedBackfillInTheJobIsTriedAgain()

	/**
	 * A depublication that cannot be written is logged as critical: the version stays public.
	 *
	 * @return void
	 */
	public function testAFailedDepublicationIsCritical(): void {
		$service = $this->service();
		$this->objects->method('searchObjects')->willReturn(
			[
				self::entity(
					uuid: 'v-1',
					schema: '46',
					data: ['module' => 'm-1', 'modulePublicationDate' => '2026-09-01T00:00:00+00:00', 'moduleRegisteredBy' => 'Municipality']
				),
			]
		);
		$this->objects->method('saveObject')->willThrowException(new \RuntimeException('database went away'));
		$this->logger->expects($this->once())->method('critical')->with($this->stringContains(string: 'stays public'));
		$this->logger->expects($this->never())->method('error');
		$this->assertSame(
			expected: ['written' => 0, 'failed' => 1],
			actual: $service->backfillModule(module: self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Municipality']))
		);
	}//end testAFailedDepublicationIsCritical()

	/**
	 * The backfill hears how many versions or searches failed, so it can tell a full pass from a partial one.
	 *
	 * @return void
	 */
	public function testTheBackfillCountsFailures(): void {
		$service = $this->service();
		$this->objects->method('searchObjects')->willThrowException(new \RuntimeException('index offline'));

		$this->assertSame(
			expected: ['written' => 0, 'failed' => 1],
			actual: $service->backfillModule(module: self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Supplier']))
		);
	}//end testTheBackfillCountsFailures()

	/**
	 * A version search that fails on a later page keeps the earlier writes, counts one failure and stops.
	 *
	 * @return void
	 */
	public function testAFailedLaterPageKeepsTheEarlierWrites(): void {
		$service = $this->service();
		$page    = [];
		for ($i = 0; $i < ModuleVersionPublicationService::VERSION_LIMIT; $i++) {
			$page[] = self::entity(uuid: 'v-' . $i, schema: '46', data: ['module' => 'm-1']);
		}

		$calls = [];
		$this->objects->method('searchObjects')->willReturnCallback(
			static function (array $query, bool $_rbac = true, bool $_multitenancy = true) use (&$calls, $page): array {
				$calls[] = [$query, $_rbac, $_multitenancy];
				if ($query['_offset'] > 0) {
					throw new \RuntimeException('index offline');
				}

				return $page;
			}
		);
		$this->objects->method('saveObject')->willReturn($page[0]);
		$this->logger->expects($this->never())->method('critical');
		$this->logger->expects($this->once())->method('error')->with(
			'ModuleVersionPublicationService: could not read the versions',
			['module' => 'm-1', 'error' => 'index offline']
		);

		$result = $service->backfillModule(
			module: self::entity(uuid: 'm-1', schema: '43', data: ['registeredBy' => 'Supplier', 'publicationDate' => '2026-01-01T00:00:00+00:00'])
		);

		$this->assertSame(expected: ['written' => ModuleVersionPublicationService::VERSION_LIMIT, 'failed' => 1], actual: $result);
		$this->assertSame(
			expected: [
				[['register' => 20, 'schema' => 46, 'module' => 'm-1', '_limit' => 500, '_offset' => 0], false, false],
				[['register' => 20, 'schema' => 46, 'module' => 'm-1', '_limit' => 500, '_offset' => 500], false, false],
			],
			actual: $calls
		);
	}//end testAFailedLaterPageKeepsTheEarlierWrites()

	/**
	 * A version search that fails while clearing a module is critical: its versions stay public.
	 *
	 * @return void
	 */
	public function testAFailedSearchWhileClearingIsCritical(): void {
		$service = $this->service();
		$this->objects->method('searchObjects')->willThrowException(new \RuntimeException('index offline'));
		$this->logger->expects($this->never())->method('error');
		$this->logger->expects($this->once())->method('critical')->with(
			'ModuleVersionPublicationService: could not read the versions; the version stays public until a later copy onto it succeeds',
			['module' => 'm-1', 'error' => 'index offline']
		);

		$this->assertSame(expected: ['written' => 0, 'failed' => 1], actual: $service->clearVersions(moduleUuid: 'm-1'));
	}//end testAFailedSearchWhileClearingIsCritical()

	/**
	 * Without the version register or schema nothing is searched.
	 *
	 * @return void
	 */
	public function testWithoutTheVersionSchemaNothingIsSearched(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturn(null);
		$settings->method('getRegisterIdForObjectType')->willReturn(20);
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->never())->method('searchObjects');
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$service = new ModuleVersionPublicationService(
			settingsService: $settings,
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			jobList: $this->createMock(originalClassName: IJobList::class)
		);

		$this->assertSame(expected: ['written' => 0, 'failed' => 0], actual: $service->clearVersions(moduleUuid: 'm-1'));
	}//end testWithoutTheVersionSchemaNothingIsSearched()

	/**
	 * The listener hands an update with the object before it, and a delete, to the service.
	 *
	 * @return void
	 */
	public function testTheListenerPassesUpdatesAndDeletes(): void {
		$service = $this->getMockBuilder(className: ModuleVersionPublicationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['objectSaved', 'objectDeleted'])
			->getMock();
		$new = $this->getMockBuilder(className: \OCA\OpenRegister\Db\ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$old = $this->getMockBuilder(className: \OCA\OpenRegister\Db\ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$service->expects($this->once())->method('objectSaved')->with($new, $old)->willReturn(0);
		$service->expects($this->once())->method('objectDeleted')->with($old)->willReturn(0);

		$listener = new ModuleVersionPublicationListener(publication: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$listener->handle(new ObjectUpdatedEvent($new, $old));
		$listener->handle(new ObjectDeletedEvent($old));

		$this->assertStringContainsString(
			needle: 'registerEventListener(ObjectDeletedEvent::class, ModuleVersionPublicationListener::class)',
			haystack: (string) file_get_contents(__DIR__ . '/../../../lib/EventListener/ListenerRegistrar.php')
		);
	}//end testTheListenerPassesUpdatesAndDeletes()

	/**
	 * The listener hands a created object to the service: the wiring from the caller.
	 *
	 * @return void
	 */
	public function testTheListenerReachesTheService(): void {
		$service = $this->getMockBuilder(className: ModuleVersionPublicationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['objectSaved'])
			->getMock();
		$object = $this->getMockBuilder(className: \OCA\OpenRegister\Db\ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$service->expects($this->once())->method('objectSaved')->with($object)->willReturn(0);

		(new ModuleVersionPublicationListener(
			publication: $service,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		))->handle(new ObjectCreatedEvent($object));

		$app = (string) file_get_contents(__DIR__ . '/../../../lib/EventListener/ListenerRegistrar.php');
		$this->assertStringContainsString(
			needle: 'registerEventListener(ObjectCreatedEvent::class, ModuleVersionPublicationListener::class)',
			haystack: $app
		);
		$this->assertStringContainsString(
			needle: 'registerEventListener(ObjectUpdatedEvent::class, ModuleVersionPublicationListener::class)',
			haystack: $app
		);
		$this->assertStringContainsString(
			needle: 'OCA\\Stackiq\\Repair\\BackfillModuleVersionPublication',
			haystack: (string) file_get_contents(__DIR__ . '/../../../appinfo/info.xml')
		);
	}//end testTheListenerReachesTheService()
}//end class
