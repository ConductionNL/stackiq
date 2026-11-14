<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\AttestationService;
use OCA\Stackiq\Service\MaintenanceRecipientService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-OAT-001: a round creates one request per entry and owner and reports the entries without one.
 *
 * @covers \OCA\Stackiq\Service\AttestationService
 * @uses   \OCA\Stackiq\Service\MaintenanceRecipientService
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 */
final class AttestationServiceTest extends TestCase {

	/** @var array<int, array<string, mixed>> Every search, in order. */
	private array $searches = [];

	/** @var array<int, array<string, mixed>> Every save, in order. */
	private array $saves = [];

	/** @var array<int, ObjectEntityInterface> What the entry search answers. */
	private array $entries = [];

	/** @var array<int, array<int, string>> What the owner lookup was asked. */
	private array $askedContacts = [];

	/** @var array<string, string> User id per contact id. */
	private array $users = [];

	/** @var array<string, array{0: int, 1: int}> Register and schema per object type. */
	private array $locations = [
		'usage'              => [5, 21],
		'module'             => [5, 22],
		'attestationRound'   => [5, 41],
		'attestationRequest' => [5, 42],
	];

	private bool $openRegister = true;

	/**
	 * An object entity double.
	 *
	 * @param string               $uuid The id.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(string $uuid, array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The service under test.
	 *
	 * @return AttestationService
	 */
	private function service(): AttestationService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $this->entries;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntityInterface {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $this->entity('saved-' . count($this->saves), $object);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objects): object {
				if ($this->openRegister === false) {
					throw new RuntimeException('not registered');
				}

				return $objects;
			}
		);

		$locator = $this->createMock(ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->willReturnCallback(fn (string $type): ?int => ($this->locations[$type][0] ?? null));
		$locator->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $type): ?int => ($this->locations[$type][1] ?? null));

		$owners = $this->createMock(MaintenanceRecipientService::class);
		$owners->method('contactUserIds')->willReturnCallback(
			function (ObjectServiceInterface $objectService, array $contactIds): array {
				$this->askedContacts[] = $contactIds;
				return array_intersect_key($this->users, array_flip($contactIds));
			}
		);

		return new AttestationService($locator, $owners, $container, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Four usages with owners and one without: four requests, one unassigned (the spec scenario).
	 *
	 * @return void
	 */
	public function testARoundOverTheApplicationsInUse(): void {
		$this->entries = [
			$this->entity('u1', ['name' => 'Zaaksysteem', 'businessOwner' => 'c-anna']),
			$this->entity('u2', ['module' => ['name' => 'Burgerzaken'], 'technicalOwner' => ['id' => 'c-bob']]),
			$this->entity('u3', ['name' => 'DMS', 'businessOwner' => 'c-anna', 'technicalOwner' => 'c-carl']),
			$this->entity('u4', ['name' => 'Zonder eigenaar']),
		];
		$this->users = ['c-anna' => 'anna', 'c-bob' => 'bob', 'c-carl' => 'carl'];

		$result = $this->service()->start('usage', 'org-1', '2026-12-01', 'Q4', 'ivo');

		self::assertSame(['register' => 5, 'schema' => 21, '_limit' => 1000, 'consumer' => 'org-1'], $this->searches[0]['query']);
		self::assertFalse($this->searches[0]['rbac']);
		self::assertSame([['c-anna', 'c-bob', 'c-carl']], $this->askedContacts);
		self::assertSame(4, $result['requestCount']);
		self::assertSame([['entryId' => 'u4', 'entryName' => 'Zonder eigenaar']], $result['unassigned']);

		$round = $this->saves[0];
		self::assertSame([5, 41, false, false], [$round['register'], $round['schema'], $round['rbac'], $round['multitenancy']]);
		self::assertSame(
			['name' => 'Q4', 'deadline' => '2026-12-01', 'scopeSchema' => 'usage', 'scopeOrganisation' => 'org-1', 'startedBy' => 'ivo', 'status' => 'open', 'requestCount' => 4, 'answeredCount' => 0],
			array_diff_key($round['object'], ['unassigned' => 1])
		);
		self::assertSame('saved-1', $result['round']['id']);

		$requests = array_map(static fn (array $save): array => [$save['object']['entryId'], $save['object']['assigneeUserId'], $save['object']['entryName']], array_slice($this->saves, 1));
		self::assertSame(
			[['u1', 'anna', 'Zaaksysteem'], ['u2', 'bob', 'Burgerzaken'], ['u3', 'anna', 'DMS'], ['u3', 'carl', 'DMS']],
			$requests
		);
		self::assertSame(
			['round' => 'saved-1', 'scopeOrganisation' => 'org-1', 'entrySchema' => 'usage', 'entryId' => 'u1', 'entryName' => 'Zaaksysteem', 'assigneeUserId' => 'anna', 'assigneeContact' => 'c-anna', 'deadline' => '2026-12-01', 'status' => 'pending'],
			$this->saves[1]['object']
		);
		self::assertSame([5, 42, false], [$this->saves[1]['register'], $this->saves[1]['schema'], $this->saves[1]['rbac']]);
	}//end testARoundOverTheApplicationsInUse()

	/**
	 * One owner named as both business and technical owner gets one request, not two.
	 *
	 * @return void
	 */
	public function testOneOwnerInBothRolesGetsOneRequest(): void {
		$this->entries = [$this->entity('u1', ['name' => 'Zaaksysteem', 'businessOwner' => 'c-anna', 'technicalOwner' => 'c-anna'])];
		$this->users = ['c-anna' => 'anna'];

		self::assertSame(1, $this->service()->start('usage', 'org-1', '2026-12-01', 'Q4', 'ivo')['requestCount']);
	}//end testOneOwnerInBothRolesGetsOneRequest()

	/**
	 * A product round reads the supplier's products and asks their contact person; an owner without an account is unassigned.
	 *
	 * @return void
	 */
	public function testAProductRoundAsksTheContactPerson(): void {
		$this->entries = [
			$this->entity('m1', ['name' => 'Suite', 'contactPerson' => 'c-dina', 'businessOwner' => 'c-anna']),
			$this->entity('m2', ['name' => 'Zonder account', 'contactPerson' => 'c-nobody']),
		];
		$this->users = ['c-dina' => 'dina', 'c-anna' => 'anna'];

		$result = $this->service()->start('module', 'org-2', '2026-12-01', '', 'ivo');

		self::assertSame('org-2', $this->searches[0]['query']['provider']);
		self::assertSame(22, $this->searches[0]['query']['schema']);
		self::assertSame(1, $result['requestCount']);
		self::assertSame('dina', $this->saves[1]['object']['assigneeUserId']);
		self::assertSame([['entryId' => 'm2', 'entryName' => 'Zonder account']], $result['unassigned']);
		self::assertSame('2026-12-01', $this->saves[0]['object']['name'], 'a round without a name is named after its deadline');
	}//end testAProductRoundAsksTheContactPerson()

	/**
	 * An invalid scope, organisation or deadline is refused before anything is read.
	 *
	 * @return void
	 */
	public function testInvalidInputIsRefusedBeforeAnyRead(): void {
		$service = $this->service();
		foreach ([['contract', 'org-1', '2026-12-01'], ['usage', '', '2026-12-01'], ['usage', 'org-1', '01-12-2026'], ['usage', 'org-1', '2026-02-30']] as [$scope, $organisation, $deadline]) {
			try {
				$service->start($scope, $organisation, $deadline, 'Q4', 'ivo');
				self::fail('accepted ' . $scope . ' ' . $organisation . ' ' . $deadline);
			} catch (InvalidArgumentException) {
				self::assertSame([], $this->searches);
			}
		}

		self::assertSame([], $this->saves);
	}//end testInvalidInputIsRefusedBeforeAnyRead()

	/**
	 * Without OpenRegister, or with an unconfigured schema, nothing is written.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWhenOpenRegisterOrASchemaIsMissing(): void {
		$this->openRegister = false;
		try {
			$this->service()->start('usage', 'org-1', '2026-12-01', 'Q4', 'ivo');
			self::fail('started without OpenRegister');
		} catch (RuntimeException $e) {
			self::assertSame('OpenRegister is not available', $e->getMessage());
		}

		$this->openRegister = true;
		unset($this->locations['attestationRound']);
		$this->entries = [$this->entity('u1', ['name' => 'Zaaksysteem', 'businessOwner' => 'c-anna'])];
		$this->users = ['c-anna' => 'anna'];
		try {
			$this->service()->start('usage', 'org-1', '2026-12-01', 'Q4', 'ivo');
			self::fail('started without the round schema');
		} catch (RuntimeException $e) {
			self::assertSame('attestationRound is not configured', $e->getMessage());
		}

		self::assertSame([], $this->saves);
	}//end testNothingIsWrittenWhenOpenRegisterOrASchemaIsMissing()
}//end class
