<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

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
use Psr\Log\LoggerInterface;

/**
 * The user of each contact person, keyed by contact person, for a confirmation round.
 *
 * @covers \OCA\Stackiq\Service\MaintenanceRecipientService
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 */
final class MaintenanceRecipientContactUserIdsTest extends TestCase {

	/**
	 * A contact person entity.
	 *
	 * @param string $uuid        The contact person id.
	 * @param string $contactsUid The Contacts UID it points at.
	 *
	 * @return ObjectEntityInterface
	 */
	private function person(string $uuid, string $contactsUid): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn(['contactsUid' => $contactsUid]);
		return $entity;
	}//end person()

	/**
	 * The service with a contact book and users.
	 *
	 * @param bool $configured Whether the contactPerson schema is configured.
	 *
	 * @return MaintenanceRecipientService
	 */
	private function service(bool $configured = true): MaintenanceRecipientService {
		$locator = $this->createMock(ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->willReturn($configured ? 5 : null);
		$locator->method('getSchemaIdForObjectType')->willReturn($configured ? 31 : null);

		$contacts = $this->createMock(StackiqContactSyncService::class);
		$contacts->method('findContactByUid')->willReturnCallback(
			static fn (string $uid): ?array => match ($uid) {
				'book-anna' => ['UID' => 'anna', 'isLocalSystemBook' => true],
				'card-bob' => ['UID' => 'x', 'EMAIL' => [['value' => 'bob@example.org']]],
				default => null,
			}
		);

		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'anna');
		$users->method('getByEmail')->willReturnCallback(static fn (string $email): array => ($email === 'bob@example.org' ? [$bob] : []));

		return new MaintenanceRecipientService(
			$locator,
			$contacts,
			$users,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(MaintenanceAnnouncerCheck::class)
		);
	}//end service()

	/**
	 * Each contact with a user maps to it; one without is left out; the lookup reads each id once.
	 *
	 * @return void
	 */
	public function testEachContactMapsToItsUser(): void {
		$asked = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true, ?array $ids = null) use (&$asked): array {
				$asked[] = [$query, $_rbac, $ids];
				return [$this->person('c-anna', 'book-anna'), $this->person('c-bob', 'card-bob'), $this->person('c-nobody', 'gone')];
			}
		);

		$map = $this->service()->contactUserIds($objects, ['c-anna', 'c-bob', 'c-anna', 'c-nobody']);

		self::assertSame(['c-anna' => 'anna', 'c-bob' => 'bob'], $map);
		self::assertSame([[['register' => 5, 'schema' => 31, '_limit' => 3], false, ['c-anna', 'c-bob', 'c-nobody']]], $asked);
	}//end testEachContactMapsToItsUser()

	/**
	 * No ids, or no contactPerson schema, reads nothing.
	 *
	 * @return void
	 */
	public function testNothingToResolveReadsNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('searchObjects');

		self::assertSame([], $this->service()->contactUserIds($objects, []));
		self::assertSame([], $this->service(false)->contactUserIds($objects, ['c-anna']));
	}//end testNothingToResolveReadsNothing()
}//end class
