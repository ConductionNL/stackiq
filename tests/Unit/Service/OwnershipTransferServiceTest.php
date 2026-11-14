<?php

/**
 * Unit tests for OwnershipTransferService and the shared OwnershipMap.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Organisation\OwnershipMap;
use OCA\Stackiq\Service\OwnershipTransferService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Moving keeps the uuid and re-points only ownership; the dry run names what stays.
 *
 * @covers \OCA\Stackiq\Service\OwnershipTransferService
 * @covers \OCA\Stackiq\Service\Organisation\OwnershipMap
 *
 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
 */
class OwnershipTransferServiceTest extends TestCase {

	/**
	 * Stored objects: schema to uuid to [organisation, data].
	 *
	 * @var array<string, array<string, array{0: string, 1: array<string, mixed>}>>
	 */
	private array $store = [];

	/**
	 * Saves made: [schema id, uuid, data].
	 *
	 * @var list<array{0: int, 1: string, 2: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * Two applications and a usage of A, a connection of A linked to the usage, and organisations A and B.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [
			'organization' => ['org-a' => ['org-a', ['name' => 'A']], 'org-b' => ['org-b', ['name' => 'B']]],
			'module' => [
				'app-1' => ['org-a', ['name' => 'Zaaksysteem', 'provider' => 'org-a', 'summary' => 'kept']],
				'app-2' => ['org-a', ['name' => 'Archief', 'provider' => ['id' => 'org-a']]],
				'app-9' => ['org-c', ['name' => 'Elsewhere', 'provider' => 'org-c']],
			],
			'usage' => ['use-1' => ['org-a', ['name' => 'Gebruik', 'consumer' => 'org-a', 'koppelingen' => ['con-1', ['id' => 'con-2'], 'con-3']]]],
			'connection' => [
				'con-1' => ['org-a', ['name' => 'Koppeling 1', 'provider' => 'org-a']],
				'con-2' => ['org-c', ['name' => 'Koppeling 2']],
			],
		];
	}//end setUp()

	/**
	 * Two applications of A move to B with the same uuids; only ownership changes.
	 *
	 * @return void
	 */
	public function testApplicationsMoveKeepingTheirIdentity(): void {
		$result = $this->service()->execute(objects: [['schema' => 'module', 'id' => 'app-1'], ['schema' => 'module', 'id' => 'app-2']], source: 'org-a', target: 'org-b');

		$this->assertSame(2, $result['moved']);
		$this->assertSame(['app-1', 'app-2'], array_column($this->saves, 1));
		$this->assertSame(['name' => 'Zaaksysteem', 'provider' => 'org-b', 'summary' => 'kept', '@self' => ['organisation' => 'org-b']], $this->saves[0][2]);
		$this->assertSame('org-b', $this->saves[1][2]['provider']);
		$this->assertSame('org-b', $this->saves[1][2]['@self']['organisation']);
	}//end testApplicationsMoveKeepingTheirIdentity()

	/**
	 * The dry run moves the usage and names the connection of A that stays behind; it writes nothing.
	 *
	 * @return void
	 */
	public function testThePlanNamesTheConnectionThatStaysBehind(): void {
		$plan = $this->service()->plan(objects: [['schema' => 'usage', 'id' => 'use-1']], source: 'org-a', target: 'org-b');

		$this->assertSame([['schema' => 'usage', 'id' => 'use-1', 'name' => 'Gebruik', 'action' => 'move', 'reason' => null]], $plan['items']);
		$this->assertSame(
			[['schema' => 'connection', 'id' => 'con-1', 'name' => 'Koppeling 1', 'organisation' => 'org-a', 'linkedTo' => 'use-1']],
			$plan['staysBehind']
		);
		$this->assertSame([], $this->saves);
	}//end testThePlanNamesTheConnectionThatStaysBehind()

	/**
	 * A connection that moves too is not listed as staying.
	 *
	 * @return void
	 */
	public function testALinkedEntryThatMovesDoesNotStay(): void {
		$plan = $this->service()->plan(objects: [['schema' => 'usage', 'id' => 'use-1'], ['schema' => 'connection', 'id' => 'con-1']], source: 'org-a', target: 'org-b');

		$this->assertSame([], $plan['staysBehind']);
		$this->assertSame(['move', 'move'], array_column($plan['items'], 'action'));
	}//end testALinkedEntryThatMovesDoesNotStay()

	/**
	 * Each skip says why, and nothing skipped is saved.
	 *
	 * @return void
	 */
	public function testSkipsGiveTheirReason(): void {
		$result = $this->service()->execute(
			objects: [['schema' => 'module', 'id' => 'app-9'], ['schema' => 'module', 'id' => 'missing'], ['schema' => 'organization', 'id' => 'org-a'], 'junk'],
			source: 'org-a',
			target: 'org-b'
		);

		$this->assertSame(
			['Not owned by the source organisation', 'Not found', 'This kind of entry cannot move between organisations', 'This kind of entry cannot move between organisations'],
			array_column($result['items'], 'reason')
		);
		$this->assertSame(0, $result['moved']);
		$this->assertSame([], $this->saves);
	}//end testSkipsGiveTheirReason()

	/**
	 * An unknown target, or the source itself, moves nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownOrSameTargetMovesNothing(): void {
		foreach (['org-x', 'org-a'] as $target) {
			$result = $this->service()->execute(objects: [['schema' => 'module', 'id' => 'app-1']], source: 'org-a', target: $target);
			$this->assertSame('The target organisation is unknown or the same as the source', $result['items'][0]['reason']);
		}

		$this->assertSame([], $this->saves);
	}//end testAnUnknownOrSameTargetMovesNothing()

	/**
	 * An owning field that names another organisation is left alone; `@self` is always re-pointed.
	 *
	 * @return void
	 */
	public function testMovedToLeavesAFieldNamingAThirdOrganisation(): void {
		$this->assertSame(
			['consumer' => 'org-c', '@self' => ['id' => 'u', 'organisation' => 'org-b']],
			OwnershipTransferService::movedTo(data: ['consumer' => 'org-c', '@self' => ['id' => 'u', 'organisation' => 'org-a']], schema: 'usage', source: 'org-a', target: 'org-b')
		);
		$this->assertSame(
			['@self' => ['organisation' => 'org-b']],
			OwnershipTransferService::movedTo(data: ['@self' => 'broken'], schema: 'catalogContract', source: 'org-a', target: 'org-b')
		);
	}//end testMovedToLeavesAFieldNamingAThirdOrganisation()

	/**
	 * The shared map names each movable schema and its owning field.
	 *
	 * @return void
	 */
	public function testTheOwnershipMap(): void {
		$this->assertSame(['usage', 'contactPerson', 'connection', 'module', 'catalogService', 'catalogContract', 'compliancy'], OwnershipMap::movableSchemas());
		$this->assertSame('consumer', OwnershipMap::owningFieldOf(schema: 'usage'));
		$this->assertSame('provider', OwnershipMap::owningFieldOf(schema: 'connection'));
		$this->assertSame('organization', OwnershipMap::owningFieldOf(schema: 'contactPerson'));
		$this->assertNull(OwnershipMap::owningFieldOf(schema: 'catalogContract'));
		$this->assertFalse(OwnershipMap::hasOrganisationAccessor(entity: new \stdClass()));
	}//end testTheOwnershipMap()

	/**
	 * The service over the in-memory store.
	 *
	 * @return OwnershipTransferService
	 */
	private function service(): OwnershipTransferService {
		$schemaIds = ['organization' => 1, 'module' => 2, 'usage' => 3, 'connection' => 4, 'catalogContract' => 5];
		$schemaSlugs = array_flip($schemaIds);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($schemaSlugs): ?ObjectEntityInterface {
				$row = $this->store[$schemaSlugs[$schema] ?? ''][(string) $id] ?? null;
				return $row === null ? null : $this->entity(organisation: $row[0], data: $row[1]);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->assertSame(7, $register);
				$this->saves[] = [(int) $schema, (string) $uuid, $object];
				return $this->entity(organisation: 'x', data: $object);
			}
		);

		$registerConfig = $this->createMock(RegisterConfigReaderInterface::class);
		$registerConfig->method('getVoorzieningenRegisterId')->willReturn(7);
		$registerConfig->method('getObjectService')->willReturn($objectService);
		$objectTypes = $this->createMock(ObjectTypeLocatorInterface::class);
		$objectTypes->method('getSchemaIdForObjectType')->willReturnCallback(static fn (string $type): ?int => $schemaIds[$type] ?? null);

		return new OwnershipTransferService($registerConfig, $objectTypes, new NullLogger());
	}//end service()

	/**
	 * An object entity owned by an organisation.
	 *
	 * @param string               $organisation The owner
	 * @param array<string, mixed> $data         The data
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(string $organisation, array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getOrganisation')->willReturn($organisation);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()
}//end class
