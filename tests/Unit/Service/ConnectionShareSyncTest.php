<?php

/**
 * ConnectionShareSync derives a connection's participant organisations from the usages that list it.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Service\ConnectionShareSync;
use OCA\Stackiq\Service\OpenRegisterLookup;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\App\IAppManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers ConnectionShareSync over a mocked OpenRegister object service.
 *
 * @covers \OCA\Stackiq\Service\ConnectionShareSync
 * @uses   \OCA\Stackiq\Service\RelationRefs
 * @uses   \OCA\Stackiq\Service\SearchBounds
 * @uses   \OCA\Stackiq\Service\OpenRegisterLookup
 */
final class ConnectionShareSyncTest extends TestCase {

	/**
	 * The saves made, as object, register, schema, uuid and the RBAC flag.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The search queries made.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * A sync over usage records and connection records (keyed by uuid); usage schema 12, connection schema 14, register 3.
	 *
	 * @param list<array<string, mixed>>          $usages      What every usage search returns.
	 * @param array<string, array<string, mixed>> $connections The stored connections.
	 * @param bool                                $searchFails Whether the search throws.
	 *
	 * @return ConnectionShareSync
	 */
	private function sync(array $usages, array $connections, bool $searchFails=false): ConnectionShareSync {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query, bool $_rbac, bool $_multitenancy) use ($usages, $searchFails) {
				if ($searchFails === true) {
					throw new \RuntimeException('down');
				}

				$this->queries[] = $query + ['rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $usages;
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options) use ($connections) {
				$this->assertSame([3, 14], [$options->register, $options->schema]);
				if (isset($connections[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($connections[$id]);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac) {
				$this->saves[] = ['object' => $object, 'register' => $options->target->register, 'schema' => $options->target->schema, 'uuid' => $options->uuid, 'rbac' => $_rbac];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$orContainer = $this->createMock(ContainerInterface::class);
		$orContainer->method('get')->willReturn($objects);
		$lookup = new OpenRegisterLookup(appManager: $apps, container: $orContainer, userSession: $this->createMock(IUserSession::class), logger: $this->createMock(LoggerInterface::class));

		$types = $this->createMock(ObjectTypeLocatorInterface::class);
		$types->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $type) => ['usage' => 12, 'connection' => 14][$type] ?? null);
		$types->method('getRegisterIdForObjectType')->willReturn(3);

		return new ConnectionShareSync(lookup: $lookup, types: $types, logger: $this->createMock(LoggerInterface::class));
	}//end sync()

	public function testOnlyAUsageWithParticipantsAffectsItsConnections(): void {
		$this->assertSame([], ConnectionShareSync::affectedConnections(usage: ['koppelingen' => ['c1']], previous: []));
		$this->assertSame(['c1', 'c2'], ConnectionShareSync::affectedConnections(usage: ['koppelingen' => ['c1', 'c2'], 'participants' => ['org-b']], previous: []));
	}

	public function testAConnectionDroppedFromAUsageOrAUsageNoLongerSharedIsAffectedToo(): void {
		$this->assertSame(
			['c2', 'c1'],
			ConnectionShareSync::affectedConnections(usage: ['koppelingen' => ['c2'], 'participants' => ['org-b']], previous: ['koppelingen' => ['c1', 'c2'], 'participants' => ['org-b']])
		);
		$this->assertSame(['c1'], ConnectionShareSync::affectedConnections(usage: ['koppelingen' => ['c1']], previous: ['koppelingen' => ['c1'], 'participants' => [['id' => 'org-b']]]));
	}

	public function testParticipantsAreCollectedOnceFromTheUsagesThatListTheConnection(): void {
		$usages = [
			['koppelingen' => ['c1'], 'participants' => ['org-c', 'org-b']],
			['koppelingen' => [['id' => 'c1']], 'participants' => [['uuid' => 'org-b']]],
			['koppelingen' => ['c2'], 'participants' => ['org-x']],
			'not a record',
		];

		$this->assertSame(['org-b', 'org-c'], ConnectionShareSync::participantsFor(usages: $usages, connection: 'c1'));
		$this->assertSame([], ConnectionShareSync::participantsFor(usages: $usages, connection: 'c9'));
	}

	public function testThePatchIsEmptyWhenTheConnectionAlreadyHoldsThoseOrganisations(): void {
		$this->assertSame([], ConnectionShareSync::patch(connection: ['sharedWithOrganisations' => ['org-c', 'org-b']], organisations: ['org-b', 'org-c']));
		$this->assertSame([], ConnectionShareSync::patch(connection: ['name' => 'x'], organisations: []));
		$this->assertSame(['sharedWithOrganisations' => ['org-b']], ConnectionShareSync::patch(connection: ['sharedWithOrganisations' => 'bad'], organisations: ['org-b']));
		$this->assertSame(['sharedWithOrganisations' => []], ConnectionShareSync::patch(connection: ['sharedWithOrganisations' => ['org-b']], organisations: []));
	}

	public function testSyncWritesOnlyTheFieldWithRbacOffAndSearchesTheUsagesThatListTheConnection(): void {
		$sync = $this->sync(
			usages: [['id' => 'u1', 'koppelingen' => ['c1'], 'participants' => ['org-b']]],
			connections: ['c1' => ['name' => 'Mozard naar iBurgerzaken']]
		);

		$this->assertSame(1, $sync->sync(connections: ['c1']));
		$this->assertSame([['object' => ['name' => 'Mozard naar iBurgerzaken', 'sharedWithOrganisations' => ['org-b']], 'register' => 3, 'schema' => 14, 'uuid' => 'c1', 'rbac' => false]], $this->saves);
		$this->assertSame(['register' => 3, 'schema' => 12], $this->queries[0]['@self']);
		$this->assertSame('c1', $this->queries[0]['koppelingen']);
		$this->assertSame(10000, $this->queries[0]['_limit']);
		$this->assertFalse($this->queries[0]['rbac']);
	}

	public function testADeletedUsageNoLongerSharesTheConnection(): void {
		$sync = $this->sync(
			usages: [['id' => 'u1', 'koppelingen' => ['c1'], 'participants' => ['org-b']]],
			connections: ['c1' => ['sharedWithOrganisations' => ['org-b']]]
		);

		$this->assertSame(1, $sync->sync(connections: ['c1'], deletedUsage: 'u1'));
		$this->assertSame([], $this->saves[0]['object']['sharedWithOrganisations']);
	}

	public function testAnUnchangedOrMissingConnectionAndAFailingSearchWriteNothing(): void {
		$this->assertSame(0, $this->sync(usages: [['koppelingen' => ['c1'], 'participants' => ['org-b']]], connections: ['c1' => ['sharedWithOrganisations' => ['org-b']]])->sync(connections: ['c1', 'gone']));
		$this->assertSame(0, $this->sync(usages: [], connections: [], searchFails: true)->sync(connections: ['c1']));
		$this->assertSame(0, $this->sync(usages: [], connections: [], searchFails: true)->backfill());
		$this->assertSame([], $this->saves);
	}

	public function testBackfillWritesEveryConnectionOfASharedUsage(): void {
		$sync = $this->sync(
			usages: [
				['koppelingen' => ['c1', 'c2'], 'participants' => ['org-b']],
				['koppelingen' => ['c2'], 'participants' => ['org-c']],
				['koppelingen' => ['c3']],
			],
			connections: ['c1' => [], 'c2' => [], 'c3' => []]
		);

		$this->assertSame(2, $sync->backfill());
		$this->assertSame(['c1' => ['org-b'], 'c2' => ['org-b', 'org-c']], array_column(array_map(fn ($s) => ['uuid' => $s['uuid'], 'orgs' => $s['object']['sharedWithOrganisations']], $this->saves), 'orgs', 'uuid'));
		$this->assertArrayNotHasKey('koppelingen', $this->queries[0]);
	}
}
