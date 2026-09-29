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
 * @spec openspec/changes/lifecycle-maintenance-and-supplier-roadmap/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Stackiq\BackgroundJob\MaintenanceRecipientsJob;
use OCA\Stackiq\EventListener\MaintenanceRecipientsListener;
use OCA\Stackiq\Service\MaintenanceRecipientService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
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

	private const SCHEMAS = ['maintenanceWindow' => 40, 'usage' => 41, 'contactPerson' => 42];

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
	 * @var array<string, ObjectEntity>
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
	 * @param string               $uuid   The id.
	 * @param int                  $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectEntity The double.
	 */
	private function entity(string $uuid, int $schema, array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getSchema')->willReturn((string) $schema);
		$entity->method('getRegister')->willReturn((string) self::REGISTER);
		$entity->method('getObject')->willReturn($data);
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
				if ($query['schema'] === self::SCHEMAS['usage']) {
					$this->assertSame('x', $query['module']);
					return $usages;
				}

				return array_values(array_intersect_key($people, array_flip($ids ?? [])));
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
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

		$this->objectService->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => $this->windows[$id] ?? null);

		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument): void {
				$this->queued[] = [$job, $argument];
			}
		);

		$logger        = $this->createMock(LoggerInterface::class);
		$this->service = new MaintenanceRecipientService($settings, $contacts, $users, $container, $logger);
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
			$job = new MaintenanceRecipientsJob($this->createMock(ITimeFactory::class), $this->service);
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
		$window   = $this->entity('w1', self::SCHEMAS['maintenanceWindow'], ['module' => ['id' => 'x'], 'title' => 'Database upgrade', 'status' => 'planned']);

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
		$window   = $this->entity('w2', self::SCHEMAS['maintenanceWindow'], ['module' => 'x', 'recipientsResolvedAt' => '2026-09-29T10:00:00+00:00']);

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
