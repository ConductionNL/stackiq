<?php

/**
 * CatalogueMergeRelinkerTest
 *
 * operations-record-reconciliation task 3: on OpenRegister's real
 * ObjectsMergedEvent for an application or a service, every mapped reference
 * to the merged record points at the survivor, a list holds the survivor
 * once, the merged record gets mergedInto, each moved reference is audited
 * with the merge operation id, and any other merge changes nothing.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Stackiq\EventListener\CatalogueMergeRelinker;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for CatalogueMergeRelinker.
 */
class CatalogueMergeRelinkerTest extends TestCase {

	/**
	 * Schema ids per slug; every schema lives in register 100.
	 *
	 * @var array<string, int>
	 */
	private const SCHEMA_IDS = [
		'module' => 7,
		'catalogService' => 8,
		'usage' => 2,
		'connection' => 5,
		'suite' => 11,
		'organization' => 1,
	];

	/**
	 * Stored objects per schema slug.
	 *
	 * @var array<string, array<int, ObjectEntityInterface>>
	 */
	private array $stored = [];

	/**
	 * Captured saves: [schema id, uuid, data].
	 *
	 * @var array<int, array{0: int, 1: string, 2: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Audit entries written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $audited = [];

	/**
	 * An object of a schema.
	 *
	 * @param string               $schema The schema slug.
	 * @param string               $uuid   The uuid.
	 * @param array<string, mixed> $data   The data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function object(string $schema, string $uuid, array $data): ObjectEntityInterface {
		$object = $this->createMock(ObjectEntityInterface::class);
		$object->method('getUuid')->willReturn($uuid);
		$object->method('getObject')->willReturn($data);
		$object->method('getSchema')->willReturn((string)self::SCHEMA_IDS[$schema]);
		$this->stored[$schema][] = $object;
		return $object;
	}//end object()

	/**
	 * The listener over the stored objects.
	 *
	 * @param bool $withObjectService Whether OpenRegister's object service is there.
	 *
	 * @return CatalogueMergeRelinker
	 */
	private function relinker(bool $withObjectService = true): CatalogueMergeRelinker {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				foreach ($this->stored as $list) {
					foreach ($list as $object) {
						if ($object->getUuid() === $id) {
							return $object;
						}
					}
				}

				throw new RuntimeException('not found');
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$slug = array_search($config['_schema'], self::SCHEMA_IDS, true);
				return ($this->stored[$slug] ?? []);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saved[] = [(int)$schema, (string)$uuid, $object];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getObjectService')->willReturn($withObjectService === true ? $objects : null);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(static fn (string $type): ?int => self::SCHEMA_IDS[$type] ?? null);
		$settings->method('getRegisterIdForObjectType')->willReturnCallback(static fn (string $type): ?int => isset(self::SCHEMA_IDS[$type]) === true ? 100 : null);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->audited[] = $context;
			}
		);

		return new CatalogueMergeRelinker(settings: $settings, logger: $logger);
	}//end relinker()

	/**
	 * The saves of one object.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savesOf(string $uuid): array {
		return array_values(array_map(static fn (array $save): array => $save[2], array_filter($this->saved, static fn (array $save): bool => $save[1] === $uuid)));
	}//end savesOf()

	/**
	 * Usages and connections follow the survivor; the merged record names it.
	 *
	 * @return void
	 */
	public function testUsagesAndConnectionsFollowTheSurvivor(): void {
		$this->object(schema: 'module', uuid: 'orig', data: ['name' => 'Voorbeeld Name 1']);
		$this->object(schema: 'module', uuid: 'dup', data: ['name' => 'Voorbeeld name 1', 'recordStatus' => 'Merged']);
		$this->object(schema: 'usage', uuid: 'u1', data: ['module' => 'dup', 'consumer' => 'gemeente']);
		$this->object(schema: 'usage', uuid: 'u2', data: ['module' => 'other', 'plannedReplacement' => 'dup']);
		$this->object(schema: 'usage', uuid: 'u3', data: ['module' => 'orig']);
		$this->object(schema: 'connection', uuid: 'k1', data: ['moduleA' => 'x', 'moduleB' => 'dup', 'name' => 'Koppeling']);

		$this->relinker()->handle(new ObjectsMergedEvent(survivorUuid: 'orig', mergedFromUuids: ['dup'], mergeOperationId: 'op-1'));

		$this->assertSame([['module' => 'orig', 'consumer' => 'gemeente']], $this->savesOf(uuid: 'u1'));
		$this->assertSame([['module' => 'other', 'plannedReplacement' => 'orig']], $this->savesOf(uuid: 'u2'));
		$this->assertSame([], $this->savesOf(uuid: 'u3'));
		$this->assertSame([['moduleA' => 'x', 'moduleB' => 'orig', 'name' => 'Koppeling']], $this->savesOf(uuid: 'k1'));
		$this->assertSame([['name' => 'Voorbeeld name 1', 'recordStatus' => 'Merged', 'mergedInto' => 'orig']], $this->savesOf(uuid: 'dup'));
		$this->assertSame([], $this->savesOf(uuid: 'orig'));
	}//end testUsagesAndConnectionsFollowTheSurvivor()

	/**
	 * A list that held both records holds the survivor once, in place.
	 *
	 * @return void
	 */
	public function testAListHoldsTheSurvivorOnce(): void {
		$this->object(schema: 'module', uuid: 'orig', data: []);
		$this->object(schema: 'module', uuid: 'dup', data: []);
		$this->object(schema: 'catalogService', uuid: 's1', data: ['modules' => ['orig', 'a', 'dup', 'a']]);
		$this->object(schema: 'suite', uuid: 'st1', data: ['applications' => ['dup', 'b']]);

		$this->relinker()->handle(new ObjectsMergedEvent(survivorUuid: 'orig', mergedFromUuids: ['dup'], mergeOperationId: 'op-2'));

		$this->assertSame([['modules' => ['orig', 'a', 'a']]], $this->savesOf(uuid: 's1'));
		$this->assertSame([['applications' => ['orig', 'b']]], $this->savesOf(uuid: 'st1'));
	}//end testAListHoldsTheSurvivorOnce()

	/**
	 * A merge of services walks the service references.
	 *
	 * @return void
	 */
	public function testAServiceMergeWalksTheServiceReferences(): void {
		$this->object(schema: 'catalogService', uuid: 'keep', data: []);
		$this->object(schema: 'catalogService', uuid: 'gone', data: []);
		$this->object(schema: 'usage', uuid: 'u1', data: ['diensten' => ['gone'], 'module' => 'gone']);
		$this->object(schema: 'connection', uuid: 'k1', data: ['service' => 'gone']);

		$this->relinker()->handle(new ObjectsMergedEvent(survivorUuid: 'keep', mergedFromUuids: ['gone', 'keep', ''], mergeOperationId: 'op-3'));

		// `usage.module` is an application reference, not a service one: it stays.
		$this->assertSame([['diensten' => ['keep'], 'module' => 'gone']], $this->savesOf(uuid: 'u1'));
		$this->assertSame([['service' => 'keep']], $this->savesOf(uuid: 'k1'));
		$this->assertSame([[ 'mergedInto' => 'keep']], $this->savesOf(uuid: 'gone'));
	}//end testAServiceMergeWalksTheServiceReferences()

	/**
	 * One audit entry per moved reference, with the merge operation id.
	 *
	 * @return void
	 */
	public function testEachMovedReferenceIsAudited(): void {
		$this->object(schema: 'module', uuid: 'orig', data: []);
		$this->object(schema: 'module', uuid: 'dup', data: []);
		$this->object(schema: 'usage', uuid: 'u1', data: ['module' => 'dup']);
		$this->object(schema: 'connection', uuid: 'k1', data: ['moduleA' => 'dup']);

		$this->relinker()->handle(new ObjectsMergedEvent(survivorUuid: 'orig', mergedFromUuids: ['dup'], mergeOperationId: 'op-4'));

		$this->assertSame(
			[
				['audit' => true, 'mergeOperationId' => 'op-4', 'schema' => 'usage', 'field' => 'module', 'object' => 'u1', 'from' => ['dup'], 'to' => 'orig'],
				['audit' => true, 'mergeOperationId' => 'op-4', 'schema' => 'connection', 'field' => 'moduleA', 'object' => 'k1', 'from' => ['dup'], 'to' => 'orig'],
			],
			$this->audited
		);
	}//end testEachMovedReferenceIsAudited()

	/**
	 * A merge of another schema, a reversal, another event, or no object
	 * service change nothing.
	 *
	 * @return void
	 */
	public function testOtherMergesChangeNothing(): void {
		$this->object(schema: 'organization', uuid: 'org-a', data: []);
		$this->object(schema: 'organization', uuid: 'org-b', data: []);
		$this->object(schema: 'module', uuid: 'orig', data: []);
		$this->object(schema: 'usage', uuid: 'u1', data: ['consumer' => 'org-b', 'module' => 'dup']);

		$this->relinker()->handle(new ObjectsMergedEvent(survivorUuid: 'org-a', mergedFromUuids: ['org-b'], mergeOperationId: 'op-5'));
		$this->relinker()->handle(new ObjectsMergedEvent(survivorUuid: 'orig', mergedFromUuids: ['dup'], mergeOperationId: 'op-6', isReversal: true));
		$this->relinker()->handle(new Event());
		$this->relinker(withObjectService: false)->handle(new ObjectsMergedEvent(survivorUuid: 'orig', mergedFromUuids: ['dup'], mergeOperationId: 'op-7'));
		$this->relinker()->handle(new ObjectsMergedEvent(survivorUuid: 'unknown', mergedFromUuids: ['dup'], mergeOperationId: 'op-8'));

		$this->assertSame([], $this->saved);
		$this->assertSame([], $this->audited);
	}//end testOtherMergesChangeNothing()

	/**
	 * The field rewrite on its own: scalar, list and a field without the record.
	 *
	 * @return void
	 */
	public function testRepointedRewritesOnlyTheMergedRecords(): void {
		$scalar = ['schema' => 'usage', 'field' => 'module', 'list' => false];
		$list = ['schema' => 'suite', 'field' => 'applications', 'list' => true];

		$this->assertSame(['module' => 's', 'x' => 1], CatalogueMergeRelinker::repointed(data: ['module' => 'l1', 'x' => 1], reference: $scalar, losers: ['l1', 'l2'], survivor: 's'));
		$this->assertNull(CatalogueMergeRelinker::repointed(data: ['module' => 'other'], reference: $scalar, losers: ['l1'], survivor: 's'));
		$this->assertNull(CatalogueMergeRelinker::repointed(data: [], reference: $scalar, losers: ['l1'], survivor: 's'));
		$this->assertSame(['applications' => ['s', 'b']], CatalogueMergeRelinker::repointed(data: ['applications' => ['l1', 'l2', 'b', 's']], reference: $list, losers: ['l1', 'l2'], survivor: 's'));
		$this->assertNull(CatalogueMergeRelinker::repointed(data: ['applications' => 'l1'], reference: $list, losers: ['l1'], survivor: 's'));
	}//end testRepointedRewritesOnlyTheMergedRecords()
}//end class
