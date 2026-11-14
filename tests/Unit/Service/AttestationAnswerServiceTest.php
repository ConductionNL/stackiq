<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use DomainException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\AttestationAnswerService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * REQ-OAT-003: an owner confirms or corrects each entry; a pending request past its deadline is overdue.
 *
 * @covers \OCA\Stackiq\Service\AttestationAnswerService
 * @uses   \OCA\Stackiq\Service\AttestationService
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 */
final class AttestationAnswerServiceTest extends TestCase {

	/** @var array<int, int> Schema id per object type. */
	private const SCHEMAS = ['usage' => 21, 'module' => 22, 'attestationRound' => 41, 'attestationRequest' => 42];

	/** @var array<string, array{schema: int, data: array<string, mixed>}> The store, by uuid. */
	private array $store = [];

	/** @var array<int, array{uuid: string, schema: int, rbac: bool}> Every save, in order. */
	private array $saves = [];

	/** @var array<int, string> Uuids the caller may not read. */
	private array $hidden = [];

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
	 * The service over the in-memory store, with today 2026-11-14 10:00 UTC.
	 *
	 * @return AttestationAnswerService
	 */
	private function service(): AttestationAnswerService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true): ?ObjectEntityInterface {
				$row = ($this->store[(string) $id] ?? null);
				if ($row === null || $row['schema'] !== $schema || ($_rbac === true && in_array((string) $id, $this->hidden, true) === true)) {
					return null;
				}

				return $this->entity((string) $id, $row['data']);
			}
		);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query = []): array {
				$found = [];
				foreach ($this->store as $uuid => $row) {
					if ($row['schema'] !== $query['schema']) {
						continue;
					}

					foreach ($query as $key => $value) {
						if (in_array($key, ['register', 'schema', '_limit'], true) === false && ($row['data'][$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$found[] = $this->entity($uuid, $row['data']);
				}

				return $found;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true): ObjectEntityInterface {
				$this->saves[] = ['uuid' => (string) $uuid, 'schema' => $schema, 'rbac' => $_rbac];
				$this->store[(string) $uuid] = ['schema' => $schema, 'data' => $object];
				return $this->entity((string) $uuid, $object);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$locator = $this->createMock(ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->willReturn(5);
		$locator->method('getSchemaIdForObjectType')->willReturnCallback(static fn (string $type): ?int => (self::SCHEMAS[$type] ?? null));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-14T10:00:00+00:00'));

		return new AttestationAnswerService($locator, $container, $time, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A round with one usage and the given requests for it.
	 *
	 * @param array<string, array<string, mixed>> $requests Request data by uuid.
	 *
	 * @return void
	 */
	private function seed(array $requests): void {
		$this->store['round-1'] = ['schema' => 41, 'data' => ['name' => 'Q4', 'deadline' => '2026-12-01', 'answeredCount' => 0]];
		$this->store['u1']      = ['schema' => 21, 'data' => ['name' => 'Zaaksysteem', 'version' => '1.0', 'lastConfirmedAt' => '2025-11-01T00:00:00Z']];
		foreach ($requests as $uuid => $data) {
			$this->store[$uuid] = ['schema' => 42, 'data' => $data + ['round' => 'round-1', 'entrySchema' => 'usage', 'entryId' => 'u1', 'deadline' => '2026-12-01']];
		}
	}//end seed()

	/**
	 * Confirming sets the entry's lastConfirmedAt under the owner's rights and the request reads confirmed (spec scenario).
	 *
	 * @return void
	 */
	public function testTheOwnerConfirmsFromTheList(): void {
		$this->seed(['req-1' => ['assigneeUserId' => 'anna', 'status' => 'pending']]);

		$result = $this->service()->confirm('req-1', 'anna');

		self::assertSame('2026-11-14T10:00:00Z', $this->store['u1']['data']['lastConfirmedAt']);
		self::assertSame('1.0', $this->store['u1']['data']['version']);
		self::assertSame(['uuid' => 'u1', 'schema' => 21, 'rbac' => true], $this->saves[0]);
		self::assertSame(['confirmed', '2026-11-14T10:00:00Z'], [$result['status'], $result['respondedAt']]);
		self::assertSame('req-1', $result['id']);
		self::assertSame(1, $this->store['round-1']['data']['answeredCount']);
	}//end testTheOwnerConfirmsFromTheList()

	/**
	 * Someone else's request, an answered one, a missing one and an entry the owner may not read are refused; nothing is written.
	 *
	 * @return void
	 */
	public function testTheConfirmRefusals(): void {
		$this->seed([
			'req-1' => ['assigneeUserId' => 'anna', 'status' => 'pending'],
			'req-2' => ['assigneeUserId' => 'anna', 'status' => 'corrected'],
		]);
		$service = $this->service();

		$refusals = [];
		foreach ([['req-1', 'bob'], ['req-2', 'anna'], ['req-9', 'anna'], ['req-1', '']] as [$id, $uid]) {
			try {
				$service->confirm($id, $uid);
				$refusals[] = 'none';
			} catch (DomainException | UnexpectedValueException | OutOfBoundsException $e) {
				$refusals[] = get_class($e);
			}
		}

		$this->hidden = ['u1'];
		try {
			$service->confirm('req-1', 'anna');
			$refusals[] = 'none';
		} catch (OutOfBoundsException $e) {
			$refusals[] = $e->getMessage();
		}

		self::assertSame(
			[DomainException::class, UnexpectedValueException::class, OutOfBoundsException::class, DomainException::class, 'The entry of this request was not found'],
			$refusals
		);
		self::assertSame([], $this->saves);
		self::assertSame('pending', $this->store['req-1']['data']['status']);
	}//end testTheConfirmRefusals()

	/**
	 * An overdue request can still be confirmed late.
	 *
	 * @return void
	 */
	public function testAnOverdueRequestCanStillBeConfirmed(): void {
		$this->seed(['req-1' => ['assigneeUserId' => 'anna', 'status' => 'overdue']]);

		self::assertSame('confirmed', $this->service()->confirm('req-1', 'anna')['status']);
	}//end testAnOverdueRequestCanStillBeConfirmed()

	/**
	 * The saving owner's open requests for the entry read corrected; a colleague's request and other entries stay.
	 *
	 * @return void
	 */
	public function testTheOwnersEditMarksOnlyTheirRequestCorrected(): void {
		$this->seed([
			'req-anna'  => ['assigneeUserId' => 'anna', 'status' => 'pending'],
			'req-bob'   => ['assigneeUserId' => 'bob', 'status' => 'pending'],
			'req-other' => ['assigneeUserId' => 'anna', 'status' => 'pending', 'entryId' => 'u2'],
			'req-done'  => ['assigneeUserId' => 'anna', 'status' => 'confirmed'],
		]);

		$marked = $this->service()->markCorrected('usage', 'u1', 'anna');

		self::assertSame(1, $marked);
		self::assertSame(['corrected', '2026-11-14T10:00:00Z'], [$this->store['req-anna']['data']['status'], $this->store['req-anna']['data']['respondedAt']]);
		self::assertSame(['pending', 'pending', 'confirmed'], [$this->store['req-bob']['data']['status'], $this->store['req-other']['data']['status'], $this->store['req-done']['data']['status']]);
		self::assertSame('2026-11-14T10:00:00Z', $this->store['u1']['data']['lastConfirmedAt']);
		self::assertSame(2, $this->store['round-1']['data']['answeredCount']);
	}//end testTheOwnersEditMarksOnlyTheirRequestCorrected()

	/**
	 * A colleague's edit answers nothing and writes nothing; neither does an unknown entry type.
	 *
	 * @return void
	 */
	public function testAColleaguesEditAnswersNothing(): void {
		$this->seed(['req-anna' => ['assigneeUserId' => 'anna', 'status' => 'pending']]);
		$service = $this->service();

		self::assertSame([0, 0, 0], [$service->markCorrected('usage', 'u1', 'carl'), $service->markCorrected('contract', 'u1', 'anna'), $service->markCorrected('usage', 'u1', '')]);
		self::assertSame([], $this->saves);
	}//end testAColleaguesEditAnswersNothing()

	/**
	 * Only pending requests past their deadline become overdue; a second run changes nothing.
	 *
	 * @return void
	 */
	public function testPendingRequestsPastTheDeadlineBecomeOverdue(): void {
		$this->seed([
			'req-late'    => ['assigneeUserId' => 'anna', 'status' => 'pending', 'deadline' => '2026-11-13'],
			'req-today'   => ['assigneeUserId' => 'bob', 'status' => 'pending', 'deadline' => '2026-11-14'],
			'req-answer'  => ['assigneeUserId' => 'carl', 'status' => 'confirmed', 'deadline' => '2026-11-01'],
			'req-nodate'  => ['assigneeUserId' => 'dirk', 'status' => 'pending', 'deadline' => ''],
		]);
		$service = $this->service();

		self::assertSame(1, $service->markOverdue());
		self::assertSame(
			['overdue', 'pending', 'confirmed', 'pending'],
			[$this->store['req-late']['data']['status'], $this->store['req-today']['data']['status'], $this->store['req-answer']['data']['status'], $this->store['req-nodate']['data']['status']]
		);

		$saves = count($this->saves);
		self::assertSame(0, $service->markOverdue());
		self::assertSame($saves, count($this->saves));
	}//end testPendingRequestsPastTheDeadlineBecomeOverdue()

	/**
	 * Only a field a person typed counts as a change; the confirmation and the derived fields do not.
	 *
	 * @return void
	 */
	public function testChangedByHandIgnoresTheDerivedFields(): void {
		$before = ['name' => 'X', 'version' => '1.0', 'lastConfirmedAt' => '2025-01-01T00:00:00Z', 'qualityScore' => 0.4, '@self' => ['updated' => 'a']];

		self::assertFalse(AttestationAnswerService::changedByHand($before, ['lastConfirmedAt' => '2026-11-14T10:00:00Z', 'qualityScore' => 0.9, '@self' => ['updated' => 'b']] + $before));
		self::assertTrue(AttestationAnswerService::changedByHand($before, ['version' => '2.0'] + $before));
		self::assertTrue(AttestationAnswerService::changedByHand($before, $before + ['businessOwner' => 'c-anna']));
	}//end testChangedByHandIgnoresTheDerivedFields()
}//end class
