<?php

/**
 * The owners of every usage of a product are recorded on a newly announced
 * maintenance window: the real ObjectCreatedEvent queues the job, and the job
 * writes the owners.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Stackiq\BackgroundJob\MaintenanceRecipientsJob;
use OCA\Stackiq\EventListener\MaintenanceRecipientsListener;
use OCA\Stackiq\Service\MaintenanceAnnouncerCheck;
use OCA\Stackiq\Service\MaintenanceRecipientService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Two municipalities use product X; both usages name owners.
 */
class MaintenanceRecipientsListenerTest extends TestCase {

	private const REGISTER = 7;

	private const SCHEMAS = ['maintenanceWindow' => 40, 'usage' => 41, 'contactPerson' => 42, 'module' => 43];

	/**
	 * The organisation that supplies product X.
	 */
	private const SUPPLIER = 'org-supplier';

	/**
	 * The users in the catalogue's administrator group.
	 *
	 * @var array<int, string>
	 */
	private array $catalogAdmins = ['beheer'];

	/**
	 * The warnings the service logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The retries the job scheduled.
	 *
	 * @var array<int, mixed>
	 */
	private array $retries = [];

	/**
	 * Whether the object service fails every save.
	 *
	 * @var bool
	 */
	private bool $failSave = false;

	/**
	 * Whether the object service fails the usage search.
	 *
	 * @var bool
	 */
	private bool $failUsages = false;

	/**
	 * The logger double of the current test.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * The object service double, with every save it received.
	 *
	 * @var ObjectServiceInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * The saves the object service received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The jobs the listener queued.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * The windows the object service can find, by id.
	 *
	 * @var array<string, ObjectEntity|\Throwable>
	 */
	private array $windows = [];

	/**
	 * The job list double.
	 *
	 * @var IJobList&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $jobList;

	/**
	 * The real owner resolution.
	 *
	 * @var MaintenanceRecipientService
	 */
	private MaintenanceRecipientService $service;

	/**
	 * An object entity double.
	 *
	 * @param string               $uuid         The id.
	 * @param int                  $schema       The schema id.
	 * @param array<string, mixed> $data         The object data.
	 * @param string|null          $organisation The organisation that owns it.
	 * @param string|null          $owner        The user who created it.
	 *
	 * @return ObjectEntity The double.
	 */
	private function entity(string $uuid, int $schema, array $data, ?string $organisation = null, ?string $owner = null): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getSchema')->willReturn((string) $schema);
		$entity->method('getRegister')->willReturn((string) self::REGISTER);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getOrganisation')->willReturn($organisation);
		$entity->method('getOwner')->willReturn($owner);
		return $entity;
	}//end entity()

	/**
	 * The listener with its real service and doubles at the edges.
	 *
	 * @return MaintenanceRecipientsListener The listener.
	 */
	private function listener(): MaintenanceRecipientsListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $t): ?int => self::SCHEMAS[$t] ?? null);
		$settings->method('getRegisterIdForObjectType')->willReturn(self::REGISTER);

		$usages = [
			$this->entity('u1', self::SCHEMAS['usage'], ['module' => 'x', 'businessOwner' => 'anna', 'technicalOwner' => ['id' => 'bram']]),
			$this->entity('u2', self::SCHEMAS['usage'], ['module' => 'x', 'businessOwner' => 'carla']),
			$this->entity('u3', self::SCHEMAS['usage'], ['module' => 'x']),
		];
		$people = [
			'anna' => $this->entity('anna', self::SCHEMAS['contactPerson'], ['contactsUid' => 'c-anna']),
			'bram' => $this->entity('bram', self::SCHEMAS['contactPerson'], ['contactsUid' => 'c-bram']),
			'carla' => $this->entity('carla', self::SCHEMAS['contactPerson'], ['contactsUid' => 'c-carla']),
		];

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query=[], bool $_rbac=true, bool $_multitenancy=true, ?array $ids=null) use ($usages, $people): array {
				if ($query['schema'] === self::SCHEMAS['usage'] && $this->failUsages === true) {
					throw new \RuntimeException('index offline');
				}

				if ($query['schema'] === self::SCHEMAS['usage']) {
					$this->assertSame('x', $query['module']);
					return $usages;
				}

				return array_values(array_intersect_key($people, array_flip($ids ?? [])));
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				if ($this->failSave === true) {
					throw new \RuntimeException('lock wait timeout');
				}

				$this->saved[] = $object;
				return $this->createMock(ObjectEntity::class);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->method('findContactByUid')->willReturnCallback(
			fn (string $uid): ?array => [
				'c-anna' => ['UID' => 'anna.nc', 'isLocalSystemBook' => true],
				'c-bram' => ['UID' => 'c-bram', 'EMAIL' => ['bram@leiden.nl']],
				'c-carla' => ['UID' => 'c-carla', 'EMAIL' => [['value' => 'carla@delft.nl']]],
			][$uid] ?? null
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(fn (string $uid): bool => $uid === 'anna.nc');
		$users->method('getByEmail')->willReturnCallback(
			function (string $email): array {
				$uid = ['bram@leiden.nl' => 'bram.nc', 'carla@delft.nl' => 'carla.nc'][$email] ?? null;
				if ($uid === null) {
					return [];
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return [$user];
			}
		);

		$this->windows['x'] = $this->entity('x', self::SCHEMAS['module'], ['name' => 'Product X', 'provider' => ['id' => self::SUPPLIER]]);
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntity {
				if (($this->windows[$id] ?? null) instanceof \Throwable) {
					throw $this->windows[$id];
				}

				return $this->windows[$id] ?? null;
			}
		);

		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument): void {
				$this->queued[] = [$job, $argument];
			}
		);
		$this->jobList->method('scheduleAfter')->willReturnCallback(
			function (string $job, int $runAfter, mixed $argument): void {
				$this->assertSame(MaintenanceRecipientsJob::class, $job);
				$this->retries[] = $argument;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $group): bool => $group === 'software-catalog-admins' && in_array($uid, $this->catalogAdmins, true)
		);

		$logger       = $this->createMock(LoggerInterface::class);
		$this->logger = $logger;
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$this->service = new MaintenanceRecipientService($settings, $contacts, $users, $container, $logger, new MaintenanceAnnouncerCheck($settings, $groups));
		return new MaintenanceRecipientsListener($this->service, $this->jobList, $logger);
	}//end listener()

	/**
	 * Run the jobs the listener queued, the way cron runs them.
	 *
	 * @return void
	 */
	private function runQueuedJobs(): void {
		foreach ($this->queued as [$class, $argument]) {
			$this->assertSame(MaintenanceRecipientsJob::class, $class);
			$job = new MaintenanceRecipientsJob($this->createMock(ITimeFactory::class), $this->service, $this->jobList, $this->logger);
			$run = new \ReflectionMethod($job, 'run');
			$run->invoke($job, $argument);
		}
	}//end runQueuedJobs()

	/**
	 * Announcing a window on X records the owners of both usages as users.
	 *
	 * @return void
	 */
	public function testTheOwnersOfEveryUsageAreRecorded(): void {
		$listener = $this->listener();
		$window   = $this->entity('w1', self::SCHEMAS['maintenanceWindow'], ['module' => ['id' => 'x'], 'title' => 'Database upgrade', 'status' => 'planned'], self::SUPPLIER, 'jan');

		$this->windows['w1'] = $window;

		$listener->handle(new ObjectCreatedEvent($window));

		$this->assertSame([], $this->saved, 'the listener only queues; the write runs in the job');
		$this->assertSame([[MaintenanceRecipientsJob::class, ['uuid' => 'w1', 'register' => '7', 'schema' => '40']]], $this->queued);

		$this->runQueuedJobs();

		$this->assertCount(1, $this->saved);
		$this->assertSame(['anna.nc', 'bram.nc', 'carla.nc'], $this->saved[0]['notifyUserIds']);
		$this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $this->saved[0]['recipientsResolvedAt']));
		$this->assertSame('Database upgrade', $this->saved[0]['title']);
	}//end testTheOwnersOfEveryUsageAreRecorded()

	/**
	 * A supplier announcing maintenance on another supplier's product reaches nobody.
	 *
	 * @return void
	 */
	public function testAnotherSuppliersWindowNotifiesNobody(): void {
		$listener = $this->listener();
		$window   = $this->entity('w3', self::SCHEMAS['maintenanceWindow'], ['module' => 'x', 'title' => 'Click here', 'notifyUserIds' => ['victim']], 'org-competitor', 'mallory');

		$this->windows['w3'] = $window;

		$listener->handle(new ObjectCreatedEvent($window));
		$this->runQueuedJobs();

		$this->assertCount(1, $this->saved, 'the window is written back once');
		$this->assertSame([], $this->saved[0]['notifyUserIds'], 'the ids it was created with are cleared');
		$this->assertArrayNotHasKey('recipientsResolvedAt', $this->saved[0], 'no resolved time, so no announcement');
		$this->assertCount(1, $this->warnings);
	}//end testAnotherSuppliersWindowNotifiesNobody()

	/**
	 * A refused window that already carries a resolved time is cleared too, so the
	 * reminder a day before the window reaches nobody it names.
	 *
	 * @return void
	 */
	public function testARefusedWindowWithAResolvedTimeIsClearedForTheReminder(): void {
		$listener = $this->listener();
		$window   = $this->entity('w7', self::SCHEMAS['maintenanceWindow'], ['module' => 'x', 'notifyUserIds' => ['victim'], 'recipientsResolvedAt' => '2026-09-29T10:00:00+00:00'], 'org-competitor', 'mallory');

		$this->windows['w7'] = $window;

		$listener->handle(new ObjectCreatedEvent($window));
		$this->runQueuedJobs();

		$this->assertCount(1, $this->saved);
		$this->assertSame([], $this->saved[0]['notifyUserIds']);
		$this->assertSame('2026-09-29T10:00:00+00:00', $this->saved[0]['recipientsResolvedAt'], 'the resolved time is unchanged, so the announcement does not fire');
	}//end testARefusedWindowWithAResolvedTimeIsClearedForTheReminder()

	/**
	 * A window without an organisation is refused unless an administrator created it.
	 *
	 * @return void
	 */
	public function testAWindowWithoutAnOrganisationIsRefused(): void {
		$listener = $this->listener();
		$window   = $this->entity('w4', self::SCHEMAS['maintenanceWindow'], ['module' => 'x', 'notifyUserIds' => ['victim']], null, 'mallory');

		$this->windows['w4'] = $window;

		$listener->handle(new ObjectCreatedEvent($window));
		$this->runQueuedJobs();

		$this->assertSame([[]], array_column($this->saved, 'notifyUserIds'));
	}//end testAWindowWithoutAnOrganisationIsRefused()

	/**
	 * A product that cannot be read is not a refusal: the supplier's window is left as it is.
	 *
	 * @return void
	 */
	public function testAnUnreadableProductWritesNothing(): void {
		$listener = $this->listener();
		$this->windows['x'] = new \RuntimeException('database went away');
		$window = $this->entity('w8', self::SCHEMAS['maintenanceWindow'], ['module' => 'x'], self::SUPPLIER, 'jan');

		$this->windows['w8'] = $window;

		$listener->handle(new ObjectCreatedEvent($window));
		$this->runQueuedJobs();

		$this->assertSame([], $this->saved);
		$this->assertSame([], $this->warnings, 'not logged as a refusal');
		$this->assertSame([['uuid' => 'w8', 'register' => '7', 'schema' => '40', 'attempt' => 2]], $this->retries, 'tried again later');
	}//end testAnUnreadableProductWritesNothing()

	/**
	 * After the last try a failing resolution is given up and logged as critical.
	 *
	 * @return void
	 */
	public function testAFailingResolutionIsGivenUpAfterTheLastTry(): void {
		$listener = $this->listener();
		$this->windows['x'] = new \RuntimeException('database went away');
		$this->windows['w9'] = $this->entity('w9', self::SCHEMAS['maintenanceWindow'], ['module' => 'x'], self::SUPPLIER, 'jan');
		$this->logger->expects($this->once())->method('critical')->with($this->stringContains('gave up'));

		$this->queued = [[MaintenanceRecipientsJob::class, ['uuid' => 'w9', 'attempt' => MaintenanceRecipientsJob::MAX_ATTEMPTS]]];
		$this->runQueuedJobs();

		$this->assertSame([], $this->retries);
		$this->assertSame([], $this->saved);
	}//end testAFailingResolutionIsGivenUpAfterTheLastTry()

	/**
	 * A window deleted before its job ran is nothing to do: no retry, no write.
	 *
	 * @return void
	 */
	public function testAWindowDeletedBeforeTheJobIsLeftAlone(): void {
		$this->listener();
		$this->windows['w13'] = new DoesNotExistException('gone');

		$this->queued = [[MaintenanceRecipientsJob::class, ['uuid' => 'w13']]];
		$this->runQueuedJobs();

		$this->assertSame([], $this->retries);
		$this->assertSame([], $this->saved);
	}//end testAWindowDeletedBeforeTheJobIsLeftAlone()

	/**
	 * A product that no longer exists refuses the window.
	 *
	 * @return void
	 */
	public function testAProductThatNoLongerExistsRefuses(): void {
		$listener = $this->listener();
		$this->windows['x'] = new DoesNotExistException('gone');
		$window = $this->entity('w10', self::SCHEMAS['maintenanceWindow'], ['module' => 'x', 'notifyUserIds' => ['victim']], self::SUPPLIER, 'jan');

		$this->windows['w10'] = $window;

		$listener->handle(new ObjectCreatedEvent($window));
		$this->runQueuedJobs();

		$this->assertSame([[]], array_column($this->saved, 'notifyUserIds'));
		$this->assertSame([], $this->retries);
	}//end testAProductThatNoLongerExistsRefuses()

	/**
	 * A refused window that names nobody is not written at all.
	 *
	 * @return void
	 */
	public function testARefusedWindowWithoutIdsIsNotRewritten(): void {
		$listener = $this->listener();
		$window   = $this->entity('w11', self::SCHEMAS['maintenanceWindow'], ['module' => 'x'], 'org-competitor', 'mallory');

		$this->windows['w11'] = $window;

		$listener->handle(new ObjectCreatedEvent($window));
		$this->runQueuedJobs();

		$this->assertSame([], $this->saved);
		$this->assertCount(1, $this->warnings, 'still logged as a refusal');
	}//end testARefusedWindowWithoutIdsIsNotRewritten()

	/**
	 * A product that names no provider is not announced by the organisation that owns its record:
	 * that is often the default organisation, which suppliers without one of their own share.
	 *
	 * @return void
	 */
	public function testAProductWithoutAProviderIsAnnouncedByAnAdministratorOnly(): void {
		$listener = $this->listener();
		$this->windows['x'] = $this->entity('x', self::SCHEMAS['module'], ['name' => 'Product X', 'provider' => []], 'org-default');
		$this->windows['w12'] = $this->entity('w12', self::SCHEMAS['maintenanceWindow'], ['module' => 'x', 'notifyUserIds' => ['victim']], 'org-default', 'mallory');
		$this->windows['w14'] = $this->entity('w14', self::SCHEMAS['maintenanceWindow'], ['module' => 'x'], 'org-default', 'beheer');

		$listener->handle(new ObjectCreatedEvent($this->windows['w12']));
		$listener->handle(new ObjectCreatedEvent($this->windows['w14']));
		$this->runQueuedJobs();

		$this->assertSame([[], ['anna.nc', 'bram.nc', 'carla.nc']], array_column($this->saved, 'notifyUserIds'), 'the supplier is refused, the administrator is not');
	}//end testAProductWithoutAProviderIsAnnouncedByAnAdministratorOnly()

	/**
	 * A retry that finds the product readable records the owners.
	 *
	 * @return void
	 */
	public function testARetryThatSucceedsRecordsTheOwners(): void {
		$this->listener();
		$this->windows['w15'] = $this->entity('w15', self::SCHEMAS['maintenanceWindow'], ['module' => 'x'], self::SUPPLIER, 'jan');

		$this->queued = [[MaintenanceRecipientsJob::class, ['uuid' => 'w15', 'attempt' => 2]]];
		$this->runQueuedJobs();

		$this->assertSame([['anna.nc', 'bram.nc', 'carla.nc']], array_column($this->saved, 'notifyUserIds'));
		$this->assertSame([], $this->retries);
	}//end testARetryThatSucceedsRecordsTheOwners()

	/**
	 * A window that cannot be written, or owners that cannot be read, are tried again rather than resolved empty.
	 *
	 * @return void
	 */
	public function testAFailedWriteOrOwnerReadIsTriedAgain(): void {
		$listener = $this->listener();
		$this->failSave  = true;
		$this->windows['w16'] = $this->entity('w16', self::SCHEMAS['maintenanceWindow'], ['module' => 'x'], self::SUPPLIER, 'jan');
		$listener->handle(new ObjectCreatedEvent($this->windows['w16']));
		$this->runQueuedJobs();

		$this->failSave    = false;
		$this->failUsages  = true;
		$this->queued      = [[MaintenanceRecipientsJob::class, ['uuid' => 'w16']]];
		$this->runQueuedJobs();

		$this->assertSame([], $this->saved, 'no empty list is recorded as resolved');
		$this->assertSame([2, 2], array_column($this->retries, 'attempt'));
	}//end testAFailedWriteOrOwnerReadIsTriedAgain()

	/**
	 * A catalogue administrator may announce maintenance on any product.
	 *
	 * @return void
	 */
	public function testACatalogAdministratorMayAnnounceForAnyProduct(): void {
		$listener = $this->listener();
		$window   = $this->entity('w5', self::SCHEMAS['maintenanceWindow'], ['module' => 'x', 'title' => 'Platform move'], 'org-beheer', 'beheer');

		$this->windows['w5'] = $window;

		$listener->handle(new ObjectCreatedEvent($window));
		$this->runQueuedJobs();

		$this->assertCount(1, $this->saved);
		$this->assertSame(['anna.nc', 'bram.nc', 'carla.nc'], $this->saved[0]['notifyUserIds']);
	}//end testACatalogAdministratorMayAnnounceForAnyProduct()

	/**
	 * The organisation that owns the product record, but is not its supplier, is refused:
	 * an admin-entered product carries the importer's (often the default) organisation.
	 *
	 * @return void
	 */
	public function testTheOrganisationThatOnlyOwnsTheProductRecordIsRefused(): void {
		$listener = $this->listener();
		$this->windows['x'] = $this->entity('x', self::SCHEMAS['module'], ['name' => 'Product X', 'provider' => ['id' => self::SUPPLIER]], 'org-default');
		$window = $this->entity('w6', self::SCHEMAS['maintenanceWindow'], ['module' => 'x', 'notifyUserIds' => ['victim']], 'org-default', 'jan');

		$this->windows['w6'] = $window;

		$listener->handle(new ObjectCreatedEvent($window));
		$this->runQueuedJobs();

		$this->assertSame([[]], array_column($this->saved, 'notifyUserIds'));
	}//end testTheOrganisationThatOnlyOwnsTheProductRecordIsRefused()

	/**
	 * An object of another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$listener = $this->listener();
		$listener->handle(new ObjectCreatedEvent($this->entity('m1', 99, ['module' => 'x'])));

		$this->assertSame([], $this->queued);
	}//end testAnotherSchemaIsIgnored()

	/**
	 * A window whose owners were already resolved is not written again.
	 *
	 * @return void
	 */
	public function testAResolvedWindowIsNotWrittenAgain(): void {
		$listener = $this->listener();
		$window   = $this->entity('w2', self::SCHEMAS['maintenanceWindow'], ['module' => 'x', 'recipientsResolvedAt' => '2026-09-29T10:00:00+00:00'], self::SUPPLIER, 'jan');

		$this->windows['w2'] = $window;

		$listener->handle(new ObjectCreatedEvent($window));
		$this->runQueuedJobs();

		$this->assertSame([], $this->saved);
	}//end testAResolvedWindowIsNotWrittenAgain()

	/**
	 * Another event type is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$listener = $this->listener();
		$listener->handle(new Event());

		$this->assertSame([], $this->queued);
	}//end testAnotherEventIsIgnored()

	/**
	 * The listener is registered for the created event in the app.
	 *
	 * @return void
	 */
	public function testTheListenerIsRegistered(): void {
		$source = (string) file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		$this->assertMatchesRegularExpression(
			'/registerEventListener\(\s*ObjectCreatedEvent::class,\s*MaintenanceRecipientsListener::class\s*\)/',
			$source
		);
	}//end testTheListenerIsRegistered()
}//end class
