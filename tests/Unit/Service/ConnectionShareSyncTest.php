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
 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
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
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
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
				$this->assertSame(expected: [3, 14], actual: [$options->register, $options->schema]);
				if (isset($connections[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($connections[$id]);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac) {
				$this->saves[] = [
					'object' => $object,
					'register' => $options->target->register,
					'schema' => $options->target->schema,
					'uuid' => $options->uuid,
					'rbac' => $_rbac,
				];
				return $this->createMock(originalClassName: ObjectEntityInterface::class);
			}
		);

		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$orContainer = $this->createMock(originalClassName: ContainerInterface::class);
		$orContainer->method('get')->willReturn($objects);
		$lookup = new OpenRegisterLookup(
			appManager: $apps,
			container: $orContainer,
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$types = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$types->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $type) => ['usage' => 12, 'connection' => 14][$type] ?? null);
		$types->method('getRegisterIdForObjectType')->willReturn(3);

		return new ConnectionShareSync(lookup: $lookup, types: $types, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end sync()

	/**
	 * Only ausage with participants affects its connections.
	 *
	 * @return void
	 */
	public function testOnlyAUsageWithParticipantsAffectsItsConnections(): void {
		$this->assertSame(expected: [], actual: ConnectionShareSync::affectedConnections(usage: ['koppelingen' => ['c1']], previous: []));
		$this->assertSame(
			expected: ['c1', 'c2'],
			actual: ConnectionShareSync::affectedConnections(usage: ['koppelingen' => ['c1', 'c2'], 'participants' => ['org-b']], previous: [])
		);
	}

	/**
	 * Aconnection dropped from ausage or ausage no longer shared is affected too.
	 *
	 * @return void
	 */
	public function testAConnectionDroppedFromAUsageOrAUsageNoLongerSharedIsAffectedToo(): void {
		$this->assertSame(
			expected: ['c2', 'c1'],
			actual: ConnectionShareSync::affectedConnections(
				usage: ['koppelingen' => ['c2'], 'participants' => ['org-b']],
				previous: ['koppelingen' => ['c1', 'c2'], 'participants' => ['org-b']]
			)
		);
		$this->assertSame(
			expected: ['c1'],
			actual: ConnectionShareSync::affectedConnections(
				usage: ['koppelingen' => ['c1']],
				previous: ['koppelingen' => ['c1'], 'participants' => [['id' => 'org-b']]]
			)
		);
	}

	/**
	 * Participants are collected once from the usages that list the connection.
	 *
	 * @return void
	 */
	public function testParticipantsAreCollectedOnceFromTheUsagesThatListTheConnection(): void {
		$usages = [
			['koppelingen' => ['c1'], 'participants' => ['org-c', 'org-b']],
			['koppelingen' => [['id' => 'c1']], 'participants' => [['uuid' => 'org-b']]],
			['koppelingen' => ['c2'], 'participants' => ['org-x']],
			'not a record',
		];

		$this->assertSame(expected: ['org-b', 'org-c'], actual: ConnectionShareSync::participantsFor(usages: $usages, connection: 'c1'));
		$this->assertSame(expected: [], actual: ConnectionShareSync::participantsFor(usages: $usages, connection: 'c9'));
	}

	/**
	 * The patch is empty when the connection already holds those organisations.
	 *
	 * @return void
	 */
	public function testThePatchIsEmptyWhenTheConnectionAlreadyHoldsThoseOrganisations(): void {
		$this->assertSame(
			expected: [],
			actual: ConnectionShareSync::patch(connection: ['sharedWithOrganisations' => ['org-c', 'org-b']], organisations: ['org-b', 'org-c'])
		);
		$this->assertSame(expected: [], actual: ConnectionShareSync::patch(connection: ['name' => 'x'], organisations: []));
		$this->assertSame(
			expected: ['sharedWithOrganisations' => ['org-b']],
			actual: ConnectionShareSync::patch(connection: ['sharedWithOrganisations' => 'bad'], organisations: ['org-b'])
		);
		$this->assertSame(
			expected: ['sharedWithOrganisations' => []],
			actual: ConnectionShareSync::patch(connection: ['sharedWithOrganisations' => ['org-b']], organisations: [])
		);
	}

	/**
	 * Sync writes only the field with rbac off and searches the usages that list the connection.
	 *
	 * @return void
	 */
	public function testSyncWritesOnlyTheFieldWithRbacOffAndSearchesTheUsagesThatListTheConnection(): void {
		$sync = $this->sync(
			usages: [['id' => 'u1', 'koppelingen' => ['c1'], 'participants' => ['org-b']]],
			connections: ['c1' => ['name' => 'Mozard naar iBurgerzaken']]
		);

		$this->assertSame(expected: 1, actual: $sync->sync(connections: ['c1']));
		$this->assertSame(
			expected: [
				[
					'object' => ['name' => 'Mozard naar iBurgerzaken', 'sharedWithOrganisations' => ['org-b']],
					'register' => 3,
					'schema' => 14,
					'uuid' => 'c1',
					'rbac' => false,
				],
			],
			actual: $this->saves
		);
		$this->assertSame(expected: ['register' => 3, 'schema' => 12], actual: $this->queries[0]['@self']);
		$this->assertSame(expected: 'c1', actual: $this->queries[0]['koppelingen']);
		$this->assertSame(expected: 10000, actual: $this->queries[0]['_limit']);
		$this->assertFalse(condition: $this->queries[0]['rbac']);
	}

	/**
	 * Adeleted usage no longer shares the connection.
	 *
	 * @return void
	 */
	public function testADeletedUsageNoLongerSharesTheConnection(): void {
		$sync = $this->sync(
			usages: [['id' => 'u1', 'koppelingen' => ['c1'], 'participants' => ['org-b']]],
			connections: ['c1' => ['sharedWithOrganisations' => ['org-b']]]
		);

		$this->assertSame(expected: 1, actual: $sync->sync(connections: ['c1'], deletedUsage: 'u1'));
		$this->assertSame(expected: [], actual: $this->saves[0]['object']['sharedWithOrganisations']);
	}

	/**
	 * An unchanged or missing connection and afailing search write nothing.
	 *
	 * @return void
	 */
	public function testAnUnchangedOrMissingConnectionAndAFailingSearchWriteNothing(): void {
		$this->assertSame(
			expected: 0,
			actual: $this->sync(
				usages: [['koppelingen' => ['c1'], 'participants' => ['org-b']]],
				connections: ['c1' => ['sharedWithOrganisations' => ['org-b']]]
			)->sync(connections: ['c1', 'gone'])
		);
		$this->assertSame(expected: 0, actual: $this->sync(usages: [], connections: [], searchFails: true)->sync(connections: ['c1']));
		$this->assertSame(expected: 0, actual: $this->sync(usages: [], connections: [], searchFails: true)->backfill());
		$this->assertSame(expected: [], actual: $this->saves);
	}

	/**
	 * Backfill writes every connection of ashared usage.
	 *
	 * @return void
	 */
	public function testBackfillWritesEveryConnectionOfASharedUsage(): void {
		$sync = $this->sync(
			usages: [
				['koppelingen' => ['c1', 'c2'], 'participants' => ['org-b']],
				['koppelingen' => ['c2'], 'participants' => ['org-c']],
				['koppelingen' => ['c3']],
			],
			connections: ['c1' => [], 'c2' => [], 'c3' => []]
		);

		$this->assertSame(expected: 2, actual: $sync->backfill());
		$this->assertSame(
			expected: ['c1' => ['org-b'], 'c2' => ['org-b', 'org-c']],
			actual: array_column(
				array_map(fn ($s) => ['uuid' => $s['uuid'], 'orgs' => $s['object']['sharedWithOrganisations']], $this->saves),
				'orgs',
				'uuid'
			)
		);
		$this->assertArrayNotHasKey(key: 'koppelingen', array: $this->queries[0]);
	}
}
