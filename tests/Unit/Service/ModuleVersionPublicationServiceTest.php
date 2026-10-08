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
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchemaIdForObjectType', 'getRegisterIdForObjectType'])
			->getMock();
		$settings->method('getSchemaIdForObjectType')->willReturnMap([['module', 43], ['moduleVersion', 46]]);
		$settings->method('getRegisterIdForObjectType')->willReturn(20);

		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$container     = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		$this->logger = $this->createMock(LoggerInterface::class);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument): void {
				$this->queued[] = [$job, $argument];
			}
		);
		$jobList->method('scheduleAfter')->willReturnCallback(
			function (string $job, int $runAfter, mixed $argument): void {
				$this->assertSame(ModuleVersionPublicationJob::class, $job);
				$this->retries[] = [$argument, $runAfter];
			}
		);
		$this->jobList = $jobList;

		$this->settings    = $settings;
		$this->container   = $container;
		$this->publication = new ModuleVersionPublicationService(settingsService: $settings, container: $container, logger: $this->logger, jobList: $jobList);
		return $this->publication;
	}//end service()

	/**
	 * Run the jobs the service queued, the way cron runs them.
	 *
	 * @return void
	 */
	private function runQueuedJobs(): void {
		foreach ($this->queued as [$class, $argument]) {
			$this->assertSame(ModuleVersionPublicationJob::class, $class);
			$time = $this->createMock(ITimeFactory::class);
			$time->method('getTime')->willReturn(1000);
			$job = new ModuleVersionPublicationJob($time, $this->publication, $this->settings, $this->container, $this->logger, $this->jobList);
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
		$stale   = self::entity('v-1', '46', ['module' => 'm-1', 'version' => '1.0']);
		$current = self::entity('v-2', '46', ['module' => 'm-1', 'version' => '2.0', 'modulePublicationDate' => '2026-09-01T00:00:00+00:00', 'moduleRegisteredBy' => 'Municipality']);
		$this->objects->method('searchObjects')->willReturn([$stale, $current]);

		$written = [];
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function (...$args) use (&$written, $stale) {
				$written[] = $args;
				return $stale;
			}
		);

		$module = self::entity('m-1', '43', ['name' => 'Zaaksysteem', 'publicationDate' => '2026-09-01T00:00:00+00:00', 'registeredBy' => 'Municipality']);
		$this->objects->method('find')->willReturn($module);
		$this->assertSame(0, $service->objectSaved(object: $module), 'the request that saved the module writes no version');
		$this->assertSame([], $written);
		$this->assertSame([[ModuleVersionPublicationJob::class, ['module' => 'm-1', 'deleted' => false]]], $this->queued);

		$this->runQueuedJobs();

		$this->assertSame('v-1', $written[0][4], 'only the stale version is written');
		$this->assertSame('2026-09-01T00:00:00+00:00', $written[0][0]['modulePublicationDate']);
		$this->assertSame('Municipality', $written[0][0]['moduleRegisteredBy']);
		$this->assertSame('1.0', $written[0][0]['version'], 'the version keeps its own data');
		$this->assertFalse($written[0][8], 'saved without validation, so older version data never blocks the mirror');
	}//end testAPublishedModuleReachesTheVersionsThatDiffer()

	/**
	 * Depublishing a module clears the date on its versions, so they stop being public.
	 *
	 * @return void
	 */
	public function testADepublishedModuleClearsItsVersions(): void {
		$service = $this->service();
		$this->objects->method('searchObjects')->willReturn([self::entity('v-1', '46', ['module' => 'm-1', 'modulePublicationDate' => '2026-09-01T00:00:00+00:00', 'moduleRegisteredBy' => 'Municipality'])]);
		$this->objects->expects($this->once())->method('saveObject')->with($this->callback(static fn (array $data): bool => $data['modulePublicationDate'] === null));
		$module = self::entity('m-1', '43', ['registeredBy' => 'Municipality']);
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
		$this->objects->method('find')->willReturn(self::entity('m-1', '43', ['registeredBy' => 'Supplier']));
		$this->objects->expects($this->once())->method('saveObject')->with($this->callback(static fn (array $data): bool => $data['moduleRegisteredBy'] === 'Supplier'));

		$this->assertSame(1, $service->objectSaved(object: self::entity('v-9', '46', ['module' => 'm-1'])));
		$this->assertSame(0, $service->objectSaved(object: self::entity('v-9', '46', ['module' => 'm-1', 'modulePublicationDate' => null, 'moduleRegisteredBy' => 'Supplier'])));
		$this->assertSame(0, $service->objectSaved(object: self::entity('x-1', '99', ['module' => 'm-1'])), 'other schemas are ignored');
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
			$page[] = self::entity('v-' . $i, '46', ['module' => 'm-1']);
		}

		$offsets = [];
		$this->objects->method('searchObjects')->willReturnCallback(
			static function (array $query) use (&$offsets, $page): array {
				$offsets[] = $query['_offset'];
				return match ($query['_offset']) {
					0 => $page,
					ModuleVersionPublicationService::VERSION_LIMIT => [self::entity('v-last', '46', ['module' => 'm-1'])],
					default => [],
				};
			}
		);
		$this->objects->expects($this->exactly(ModuleVersionPublicationService::VERSION_LIMIT + 1))->method('saveObject')->willReturn($page[0]);

		$written = $service->backfillModule(module: self::entity('m-1', '43', ['registeredBy' => 'Supplier']))['written'];

		$this->assertSame(ModuleVersionPublicationService::VERSION_LIMIT + 1, $written);
		$this->assertSame([0, ModuleVersionPublicationService::VERSION_LIMIT], $offsets);
	}//end testAModuleWithManyVersionsIsReadPageByPage()

	/**
	 * A module update that leaves its publication as it was does not search its versions.
	 *
	 * @return void
	 */
	public function testAModuleUpdateWithoutAPublicationChangeLeavesTheVersions(): void {
		$service = $this->service();
		$this->objects->expects($this->never())->method('searchObjects');

		$before = self::entity('m-1', '43', ['name' => 'Zaaksysteem', 'publicationDate' => '2026-09-01T00:00:00+00:00', 'registeredBy' => 'Municipality']);
		$after  = self::entity('m-1', '43', ['name' => 'Zaaksysteem 2', 'publicationDate' => '2026-09-01T00:00:00+00:00', 'registeredBy' => 'Municipality']);

		$this->assertSame(0, $service->objectSaved(object: $after, previous: $before));
		$this->assertSame([], $this->queued, 'nothing is queued');
	}//end testAModuleUpdateWithoutAPublicationChangeLeavesTheVersions()

	/**
	 * Deleting a module clears its versions, so they stop being public.
	 *
	 * @return void
	 */
	public function testADeletedModuleClearsItsVersions(): void {
		$service = $this->service();
		$this->objects->method('searchObjects')->willReturn([self::entity('v-1', '46', ['module' => 'm-1', 'moduleRegisteredBy' => 'Supplier'])]);
		$this->objects->expects($this->once())->method('saveObject')->with(
			$this->callback(static fn (array $data): bool => $data['modulePublicationDate'] === null && $data['moduleRegisteredBy'] === null)
		);

		$this->objects->expects($this->never())->method('find');

		$this->assertSame(0, $service->objectDeleted(object: self::entity('m-1', '43', ['registeredBy' => 'Supplier'])));
		$this->assertSame(0, $service->objectDeleted(object: self::entity('v-1', '46', ['module' => 'm-1'])), 'only a module clears versions');
		$this->assertSame([[ModuleVersionPublicationJob::class, ['module' => 'm-1', 'deleted' => true]]], $this->queued);

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
		$this->objects->method('searchObjects')->willReturn([self::entity('v-1', '46', ['module' => 'm-1', 'moduleRegisteredBy' => 'Supplier'])]);
		$this->objects->expects($this->once())->method('saveObject')->with(
			$this->callback(static fn (array $data): bool => $data['modulePublicationDate'] === null && $data['moduleRegisteredBy'] === null)
		);

		$service->objectSaved(object: self::entity('m-1', '43', ['registeredBy' => 'Supplier']));
		$this->runQueuedJobs();

		$this->assertSame([], $this->retries, 'a module that is gone is not a failure');
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
		$this->logger->expects($this->exactly(ModuleVersionPublicationJob::MAX_ATTEMPTS))->method('error')->with($this->stringContains('tried again later'));
		$this->logger->expects($this->once())->method('critical')->with($this->stringContains('gave up'));

		$service->objectSaved(object: self::entity('m-1', '43', ['registeredBy' => 'Supplier']));
		$this->runQueuedJobs();

		$this->assertSame([[['module' => 'm-1', 'deleted' => false, 'attempt' => 2], 1000 + ModuleVersionPublicationJob::RETRY_DELAY]], $this->retries);

		for ($attempt = 2; $attempt <= ModuleVersionPublicationJob::MAX_ATTEMPTS; $attempt++) {
			$this->queued  = [[ModuleVersionPublicationJob::class, $this->retries[array_key_last($this->retries)][0]]];
			$this->runQueuedJobs();
		}

		$this->assertCount(ModuleVersionPublicationJob::MAX_ATTEMPTS - 1, $this->retries, 'no retry after the last try');
	}//end testAModuleThatCannotBeReadIsTriedAgain()

	/**
	 * A version that cannot be written during the job's copy is tried again.
	 *
	 * @return void
	 */
	public function testAFailedVersionWriteInTheJobIsTriedAgain(): void {
		$service = $this->service();
		$this->objects->method('find')->willReturn(self::entity('m-1', '43', ['registeredBy' => 'Municipality']));
		$this->objects->method('searchObjects')->willReturn([self::entity('v-1', '46', ['module' => 'm-1', 'moduleRegisteredBy' => 'Supplier'])]);
		$this->objects->method('saveObject')->willThrowException(new \RuntimeException('lock wait timeout'));

		$service->objectDeleted(object: self::entity('m-1', '43', []));
		$this->runQueuedJobs();

		$this->assertSame([['module' => 'm-1', 'deleted' => true, 'attempt' => 2]], array_column($this->retries, 0));
	}//end testAFailedVersionWriteInTheJobIsTriedAgain()

	/**
	 * A version that cannot be written while a published module is copied onto it is tried again too.
	 *
	 * @return void
	 */
	public function testAFailedBackfillInTheJobIsTriedAgain(): void {
		$service = $this->service();
		$this->objects->method('find')->willReturn(self::entity('m-1', '43', ['registeredBy' => 'Supplier']));
		$this->objects->method('searchObjects')->willReturn([self::entity('v-1', '46', ['module' => 'm-1'])]);
		$this->objects->method('saveObject')->willThrowException(new \RuntimeException('lock wait timeout'));

		$service->objectSaved(object: self::entity('m-1', '43', ['registeredBy' => 'Supplier']));
		$this->runQueuedJobs();

		$this->assertSame([['module' => 'm-1', 'deleted' => false, 'attempt' => 2]], array_column($this->retries, 0));
	}//end testAFailedBackfillInTheJobIsTriedAgain()

	/**
	 * A depublication that cannot be written is logged as critical: the version stays public.
	 *
	 * @return void
	 */
	public function testAFailedDepublicationIsCritical(): void {
		$service = $this->service();
		$this->objects->method('searchObjects')->willReturn([self::entity('v-1', '46', ['module' => 'm-1', 'modulePublicationDate' => '2026-09-01T00:00:00+00:00', 'moduleRegisteredBy' => 'Municipality'])]);
		$this->objects->method('saveObject')->willThrowException(new \RuntimeException('database went away'));
		$this->logger->expects($this->once())->method('critical')->with($this->stringContains('stays public'));
		$this->logger->expects($this->never())->method('error');
		$this->assertSame(['written' => 0, 'failed' => 1], $service->backfillModule(module: self::entity('m-1', '43', ['registeredBy' => 'Municipality'])));
	}//end testAFailedDepublicationIsCritical()

	/**
	 * The backfill hears how many versions or searches failed, so it can tell a full pass from a partial one.
	 *
	 * @return void
	 */
	public function testTheBackfillCountsFailures(): void {
		$service = $this->service();
		$this->objects->method('searchObjects')->willThrowException(new \RuntimeException('index offline'));

		$this->assertSame(['written' => 0, 'failed' => 1], $service->backfillModule(module: self::entity('m-1', '43', ['registeredBy' => 'Supplier'])));
	}//end testTheBackfillCountsFailures()

	/**
	 * The listener hands an update with the object before it, and a delete, to the service.
	 *
	 * @return void
	 */
	public function testTheListenerPassesUpdatesAndDeletes(): void {
		$service = $this->getMockBuilder(ModuleVersionPublicationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['objectSaved', 'objectDeleted'])
			->getMock();
		$new = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$old = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$service->expects($this->once())->method('objectSaved')->with($new, $old)->willReturn(0);
		$service->expects($this->once())->method('objectDeleted')->with($old)->willReturn(0);

		$listener = new ModuleVersionPublicationListener(publication: $service, logger: $this->createMock(LoggerInterface::class));
		$listener->handle(new ObjectUpdatedEvent($new, $old));
		$listener->handle(new ObjectDeletedEvent($old));

		$this->assertStringContainsString(
			'registerEventListener(ObjectDeletedEvent::class, ModuleVersionPublicationListener::class)',
			(string) file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php')
		);
	}//end testTheListenerPassesUpdatesAndDeletes()

	/**
	 * The listener hands a created object to the service: the wiring from the caller.
	 *
	 * @return void
	 */
	public function testTheListenerReachesTheService(): void {
		$service = $this->getMockBuilder(ModuleVersionPublicationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['objectSaved'])
			->getMock();
		$object = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$service->expects($this->once())->method('objectSaved')->with($object)->willReturn(0);

		(new ModuleVersionPublicationListener(publication: $service, logger: $this->createMock(LoggerInterface::class)))->handle(new ObjectCreatedEvent($object));

		$app = (string) file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
		$this->assertStringContainsString('registerEventListener(ObjectCreatedEvent::class, ModuleVersionPublicationListener::class)', $app);
		$this->assertStringContainsString('registerEventListener(ObjectUpdatedEvent::class, ModuleVersionPublicationListener::class)', $app);
		$this->assertStringContainsString('OCA\\Stackiq\\Repair\\BackfillModuleVersionPublication', (string) file_get_contents(__DIR__ . '/../../../appinfo/info.xml'));
	}//end testTheListenerReachesTheService()
}//end class
