<?php

/**
 * Golden test for the owner resolution of MaintenanceRecipientService.
 *
 * Pins, per case, which Nextcloud users the owners of a product's usages
 * resolve to (and which are refused), what recordRecipients writes on a
 * maintenance window, and every object service, contacts, user manager,
 * announcer and logger call in order.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\MaintenanceAnnouncerCheck;
use OCA\Stackiq\Service\MaintenanceRecipientService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Golden cases for resolving and recording the owners of a product's usages.
 *
 * @covers \OCA\Stackiq\Service\MaintenanceRecipientService
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */
class MaintenanceRecipientGoldenTest extends TestCase {

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
			'objectService' => true,
			'ids'           => [
				'usage'        => [21, 22],
				'contactPerson' => [31, 32],
			],
			'usages'        => [],
			'people'        => [],
			'contacts'      => [],
			'users'         => [],
			'byEmail'       => [],
			'mayAnnounce'   => true,
		];

	}//end defaultPlan()

	/**
	 * An object double.
	 *
	 * @param array  $data The object data.
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntityInterface
	 */
	private static function entity(array $data, string $uuid='window-1'): ObjectEntityInterface {
		return new class($data, $uuid) implements ObjectEntityInterface {
			/**
			 * Build the double.
			 *
			 * @param array  $data The object data.
			 * @param string $uuid The uuid.
			 */
			public function __construct(private array $data, private string $uuid) {
			}

			/**
			 * @return string|null
			 */
			public function getUuid(): ?string {
				return $this->uuid;
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * @return string|null
			 */
			public function getRegister(): ?string {
				return 'reg-w';
			}

			/**
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return 'schema-w';
			}

			/**
			 * @return string|null
			 */
			public function getOrganisation(): ?string {
				return 'org-w';
			}

			/**
			 * @return string|null
			 */
			public function getOwner(): ?string {
				return null;
			}

			/**
			 * @return array
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};

	}//end entity()

	/**
	 * The object service double.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query, bool $_rbac, bool $_multitenancy, ?array $ids=null) {
				$this->trace[] = ['searchObjects', $query, $_rbac, $_multitenancy, $ids];
				if (isset($query['module']) === true) {
					return array_map(fn (array $data) => self::entity(data: $data, uuid: 'usage'), $this->plan['usages']);
				}

				return array_map(fn (array $data) => self::entity(data: $data, uuid: 'person'), $this->plan['people']);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema, ?string $uuid, bool $_rbac, bool $_multitenancy) {
				$this->trace[] = ['saveObject', $object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy];
				return self::entity(data: $object);
			}
		);

		return $objects;

	}//end objectService()

	/**
	 * Build the service with the plan's doubles.
	 *
	 * @return MaintenanceRecipientService
	 */
	private function service(): MaintenanceRecipientService {
		$locator = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->willReturnCallback(
			function (string $type) {
				$this->trace[] = ['registerId', $type];
				return ($this->plan['ids'][$type][0] ?? null);
			}
		);
		$locator->method('getSchemaIdForObjectType')->willReturnCallback(
			function (string $type) {
				$this->trace[] = ['schemaId', $type];
				return ($this->plan['ids'][$type][1] ?? null);
			}
		);

		$contacts = $this->createMock(originalClassName: StackiqContactSyncService::class);
		$contacts->method('findContactByUid')->willReturnCallback(
			function (string $uid) {
				$this->trace[] = ['findContactByUid', $uid];
				return ($this->plan['contacts'][$uid] ?? null);
			}
		);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('userExists')->willReturnCallback(
			function (string $uid) {
				$this->trace[] = ['userExists', $uid];
				return in_array($uid, $this->plan['users'], true);
			}
		);
		$users->method('getByEmail')->willReturnCallback(
			function (string $email) {
				$this->trace[] = ['getByEmail', $email];
				$found = [];
				foreach (($this->plan['byEmail'][$email] ?? []) as $uid) {
					$user = $this->createMock(originalClassName: IUser::class);
					$user->method('getUID')->willReturn($uid);
					$found[] = $user;
				}

				return $found;
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				$this->trace[] = ['container.get', $id];
				if ($this->plan['objectService'] === false) {
					throw new \RuntimeException('no openregister');
				}

				return $this->objectService();
			}
		);

		$announcers = $this->createMock(originalClassName: MaintenanceAnnouncerCheck::class);
		$announcers->method('mayAnnounce')->willReturnCallback(
			function ($objectService, ObjectEntityInterface $window, string $moduleId) {
				$this->trace[] = ['mayAnnounce', $window->getUuid(), $moduleId];
				return $this->plan['mayAnnounce'];
			}
		);

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
				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		return new MaintenanceRecipientService($locator, $contacts, $users, $container, $logger, $announcers);

	}//end service()

	/**
	 * Resolve the owners of product `mod-1`.
	 *
	 * @param array $plan Overrides of the default plan.
	 *
	 * @return array{0: array, 1: array}
	 */
	private function owners(array $plan): array {
		$this->trace = [];
		$this->plan  = array_merge(self::defaultPlan(), $plan);
		$result      = $this->service()->ownerUserIds($this->objectService(), 'mod-1');

		return [$result, $this->trace];

	}//end owners()

	/**
	 * Record the recipients on a window.
	 *
	 * @param array $plan Overrides of the default plan.
	 * @param array $data The window data.
	 *
	 * @return array{0: array|null, 1: array}
	 */
	private function record(array $plan, array $data): array {
		$this->trace = [];
		$this->plan  = array_merge(self::defaultPlan(), $plan);
		$result      = $this->service()->recordRecipients(self::entity(data: $data), new DateTimeImmutable('2026-03-04T05:06:07+00:00'));

		return [$result, $this->trace];

	}//end record()

	/**
	 * The usage search for product `mod-1`.
	 *
	 * @return array
	 */
	private static function usageSearch(): array {
		return [
			['registerId', 'usage'],
			['schemaId', 'usage'],
			['searchObjects', ['register' => 21, 'schema' => 22, 'module' => 'mod-1', '_limit' => 1000], false, false, null],
		];

	}//end usageSearch()

	/**
	 * The contact person search for a list of ids.
	 *
	 * @param array $ids The contact person ids.
	 *
	 * @return array
	 */
	private static function peopleSearch(array $ids): array {
		return [
			['registerId', 'contactPerson'],
			['schemaId', 'contactPerson'],
			['searchObjects', ['register' => 31, 'schema' => 32, '_limit' => count($ids)], false, false, $ids],
		];

	}//end peopleSearch()

	/**
	 * Owner ids are read from both owner fields, as plain ids or objects, unique and in order.
	 *
	 * @return void
	 */
	public function testOwnerIdsAreCollectedFromBothFields(): void {
		[$result, $trace] = $this->owners(
			plan: [
				'usages' => [
					['businessOwner' => 'p1', 'technicalOwner' => ['id' => 'p2']],
					['businessOwner' => ['uuid' => 'p3'], 'technicalOwner' => 'p1'],
					['businessOwner' => ['id' => '', 'uuid' => 'p4'], 'technicalOwner' => ''],
					['businessOwner' => ['id' => 5], 'technicalOwner' => null, 'otherOwner' => 'p9'],
					['businessOwner' => 7, 'technicalOwner' => ['name' => 'x']],
				],
			]
		);

		$this->assertSame([], $result);
		$this->assertSame(array_merge(self::usageSearch(), self::peopleSearch(ids: ['p1', 'p2', 'p3', 'p4'])), $trace);

	}//end testOwnerIdsAreCollectedFromBothFields()

	/**
	 * Without owners, or without a configured usage or contact schema, nobody is resolved.
	 *
	 * @return void
	 */
	public function testMissingOwnersOrSchemasResolveNobody(): void {
		[$result, $trace] = $this->owners(plan: ['usages' => [['businessOwner' => '']]]);
		$this->assertSame([], $result);
		$this->assertSame(self::usageSearch(), $trace);

		[$result, $trace] = $this->owners(plan: ['ids' => ['usage' => [21, null], 'contactPerson' => [31, 32]]]);
		$this->assertSame([], $result);
		$this->assertSame([['registerId', 'usage'], ['schemaId', 'usage']], $trace);

		[$result, $trace] = $this->owners(plan: ['ids' => ['usage' => [21, 22], 'contactPerson' => [null, 32]], 'usages' => [['businessOwner' => 'p1']]]);
		$this->assertSame([], $result);
		$this->assertSame(array_merge(self::usageSearch(), [['registerId', 'contactPerson'], ['schemaId', 'contactPerson']]), $trace);

	}//end testMissingOwnersOrSchemasResolveNobody()

	/**
	 * A system address book contact of an existing user resolves to that user; any other contact by a unique e-mail.
	 *
	 * @return void
	 */
	public function testContactsResolveToUsers(): void {
		[$result, $trace] = $this->owners(
			plan: [
				'usages'   => [['businessOwner' => 'p1', 'technicalOwner' => 'p2']],
				'people'   => [
					['contactsUid' => 'c-system'],
					['contactsUid' => 'c-system-gone'],
					['contactsUid' => 'c-missing'],
					[],
					['contactsUid' => 'c-mail'],
					['contactsUid' => 'c-system'],
					['contactsUid' => 'c-string-mail'],
				],
				'contacts' => [
					'c-system'      => ['isLocalSystemBook' => true, 'UID' => 'alice'],
					'c-system-gone' => ['isLocalSystemBook' => true, 'UID' => 'gone', 'EMAIL' => [['value' => 'gone@x.nl']]],
					'c-mail'        => [
						'isLocalSystemBook' => 'true',
						'UID'               => 'alice',
						'EMAIL'             => [['value' => ''], 42, ['type' => 'home'], ['value' => 'shared@x.nl'], ['value' => 'bob@x.nl'], 'carol@x.nl'],
					],
					'c-string-mail' => ['EMAIL' => 'carol@x.nl'],
				],
				'users'    => ['alice'],
				'byEmail'  => [
					'gone@x.nl'   => [],
					'shared@x.nl' => ['u1', 'u2'],
					'bob@x.nl'    => ['bob'],
					'carol@x.nl'  => ['carol'],
				],
			]
		);

		$this->assertSame(['alice', 'bob', 'carol'], $result);
		$this->assertSame(
			array_merge(
				self::usageSearch(),
				self::peopleSearch(ids: ['p1', 'p2']),
				[
					['findContactByUid', 'c-system'],
					['userExists', 'alice'],
					['findContactByUid', 'c-system-gone'],
					['userExists', 'gone'],
					['getByEmail', 'gone@x.nl'],
					['findContactByUid', 'c-missing'],
					['findContactByUid', ''],
					['findContactByUid', 'c-mail'],
					['getByEmail', 'shared@x.nl'],
					['getByEmail', 'bob@x.nl'],
					['findContactByUid', 'c-system'],
					['userExists', 'alice'],
					['findContactByUid', 'c-string-mail'],
					['getByEmail', 'carol@x.nl'],
				]
			),
			$trace
		);

	}//end testContactsResolveToUsers()

	/**
	 * A relation value names an id as a non-empty string, or as a non-empty string id or uuid.
	 *
	 * @return void
	 */
	public function testReferenceIdReadsPlainAndObjectRelations(): void {
		$cases = [
			['a', 'a'],
			['', null],
			[null, null],
			[5, null],
			[['id' => 'i', 'uuid' => 'u'], 'i'],
			[['id' => '', 'uuid' => 'u'], 'u'],
			[['id' => 5, 'uuid' => 'u'], 'u'],
			[['uuid' => ''], null],
			[[], null],
			[['name' => 'x'], null],
		];
		foreach ($cases as [$value, $expected]) {
			$this->assertSame($expected, MaintenanceRecipientService::referenceId($value), var_export($value, true));
		}

	}//end testReferenceIdReadsPlainAndObjectRelations()

	/**
	 * A new window from the supplier records its owners and the resolution time.
	 *
	 * @return void
	 */
	public function testAnnouncedWindowRecordsItsOwners(): void {
		$plan = [
			'usages'   => [['businessOwner' => 'p1']],
			'people'   => [['contactsUid' => 'c1']],
			'contacts' => ['c1' => ['isLocalSystemBook' => true, 'UID' => 'alice']],
			'users'    => ['alice'],
		];
		[$result, $trace] = $this->record(plan: $plan, data: ['module' => ['id' => 'mod-1'], 'title' => 'T']);

		$written = ['module' => ['id' => 'mod-1'], 'title' => 'T', 'notifyUserIds' => ['alice'], 'recipientsResolvedAt' => '2026-03-04T05:06:07+00:00'];
		$this->assertSame(['alice'], $result);
		$this->assertSame(
			array_merge(
				[['container.get', ObjectServiceInterface::class], ['mayAnnounce', 'window-1', 'mod-1']],
				self::usageSearch(),
				self::peopleSearch(ids: ['p1']),
				[['findContactByUid', 'c1'], ['userExists', 'alice']],
				[['saveObject', $written, [], 'reg-w', 'schema-w', 'window-1', false, false]]
			),
			$trace
		);

	}//end testAnnouncedWindowRecordsItsOwners()

	/**
	 * A window without a product or without OpenRegister, or already resolved, records nothing.
	 *
	 * @return void
	 */
	public function testUnresolvableOrResolvedWindowRecordsNothing(): void {
		[$result, $trace] = $this->record(plan: [], data: ['module' => '']);
		$this->assertNull($result);
		$this->assertSame([['container.get', ObjectServiceInterface::class]], $trace);

		[$result, $trace] = $this->record(plan: ['objectService' => false], data: ['module' => 'mod-1']);
		$this->assertNull($result);
		$this->assertSame(
			[
				['container.get', ObjectServiceInterface::class],
				['log', 'debug', 'MaintenanceRecipientService: ObjectService not resolvable', ['error' => 'no openregister']],
			],
			$trace
		);

		[$result, $trace] = $this->record(plan: [], data: ['module' => 'mod-1', 'recipientsResolvedAt' => '2026-01-01']);
		$this->assertNull($result);
		$this->assertSame([['container.get', ObjectServiceInterface::class], ['mayAnnounce', 'window-1', 'mod-1']], $trace);

	}//end testUnresolvableOrResolvedWindowRecordsNothing()

	/**
	 * A window from another organisation is refused, and its recorded recipients are cleared.
	 *
	 * @return void
	 */
	public function testWindowFromAnotherOrganisationIsRefused(): void {
		$warning = [
			'log',
			'warning',
			'MaintenanceRecipientService: the window is not from the supplier of the product; no owners are notified',
			['uuid' => 'window-1', 'module' => 'mod-1', 'organisation' => 'org-w'],
		];

		$data = ['module' => 'mod-1', 'notifyUserIds' => ['alice'], 'recipientsResolvedAt' => '2026-01-01'];
		[$result, $trace] = $this->record(plan: ['mayAnnounce' => false], data: $data);
		$this->assertNull($result);
		$cleared = ['module' => 'mod-1', 'notifyUserIds' => [], 'recipientsResolvedAt' => '2026-01-01'];
		$this->assertSame(
			[
				['container.get', ObjectServiceInterface::class],
				['mayAnnounce', 'window-1', 'mod-1'],
				$warning,
				['saveObject', $cleared, [], 'reg-w', 'schema-w', 'window-1', false, false],
			],
			$trace
		);

		[$result, $trace] = $this->record(plan: ['mayAnnounce' => false], data: ['module' => 'mod-1', 'notifyUserIds' => []]);
		$this->assertNull($result);
		$this->assertSame([['container.get', ObjectServiceInterface::class], ['mayAnnounce', 'window-1', 'mod-1'], $warning], $trace);

	}//end testWindowFromAnotherOrganisationIsRefused()
}//end class
