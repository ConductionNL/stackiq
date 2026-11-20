<?php

/**
 * A usage's provider follows its application's supplier.
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
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Service\OpenRegisterLookup;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\UsageProviderSync;
use OCP\App\IAppManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers UsageProviderSync over a mocked object service.
 *
 * @covers \OCA\Stackiq\Service\UsageProviderSync
 * @uses   \OCA\Stackiq\Service\RelationRefs
 * @uses   \OCA\Stackiq\Service\SearchBounds
 * @uses   \OCA\Stackiq\Service\OpenRegisterLookup
 */
final class UsageProviderSyncTest extends TestCase {

	/**
	 * The saves made.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * How many times each module was read.
	 *
	 * @var array<string, int>
	 */
	private array $reads = [];

	/**
	 * A sync over stored modules and usages; usage schema 12, module schema 13, register 3.
	 *
	 * @param array<string, array<string, mixed>> $modules The modules by uuid.
	 * @param list<array<string, mixed>>          $usages  The usages a search returns.
	 *
	 * @return UsageProviderSync
	 */
	private function sync(array $modules, array $usages=[]): UsageProviderSync {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use ($modules) {
				$this->reads[$id] = ($this->reads[$id] ?? 0) + 1;
				if (isset($modules[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($modules[$id]);
				return $entity;
			}
		);
		$objects->method('searchObjects')->willReturn($usages);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac) {
				$this->saves[] = ['object' => $object, 'schema' => $options->target->schema, 'uuid' => $options->uuid, 'rbac' => $_rbac];
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
		$types->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $t) => ['usage' => 12, 'module' => 13][$t] ?? null);
		$types->method('getRegisterIdForObjectType')->willReturn(3);

		return new UsageProviderSync(lookup: $lookup, types: $types, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end sync()

	/**
	 * The provider becomes the modules supplier.
	 *
	 * @return void
	 */
	public function testTheProviderBecomesTheModulesSupplier(): void {
		$this->assertSame(expected: ['provider' => 'org-s'], actual: UsageProviderSync::patch(usage: ['module' => 'm1'], supplier: 'org-s'));
		$this->assertSame(
			expected: ['provider' => 'org-s'],
			actual: UsageProviderSync::patch(usage: ['module' => 'm1', 'provider' => 'org-old'], supplier: 'org-s')
		);
	}

	/**
	 * Nothing changes when it already matches or the supplier is unknown.
	 *
	 * @return void
	 */
	public function testNothingChangesWhenItAlreadyMatchesOrTheSupplierIsUnknown(): void {
		$this->assertSame(expected: [], actual: UsageProviderSync::patch(usage: ['provider' => ['id' => 'org-s']], supplier: 'org-s'));
		$this->assertSame(expected: [], actual: UsageProviderSync::patch(usage: ['provider' => 'org-hand'], supplier: null));
		$this->assertSame(expected: [], actual: UsageProviderSync::patch(usage: [], supplier: ''));
	}

	/**
	 * The supplier is read from the module once and null without one.
	 *
	 * @return void
	 */
	public function testTheSupplierIsReadFromTheModuleOnceAndNullWithoutOne(): void {
		$sync = $this->sync(modules: ['m1' => ['provider' => ['id' => 'org-s']], 'm2' => ['name' => 'no supplier']]);

		$this->assertSame(expected: 'org-s', actual: $sync->supplierFor(usage: ['module' => 'm1']));
		$this->assertSame(expected: 'org-s', actual: $sync->supplierFor(usage: ['module' => ['id' => 'm1']]));
		$this->assertNull(actual: $sync->supplierFor(usage: ['module' => 'm2']));
		$this->assertNull(actual: $sync->supplierFor(usage: ['module' => 'gone']));
		$this->assertNull(actual: $sync->supplierFor(usage: []));
		$this->assertSame(expected: 1, actual: $this->reads['m1']);
	}

	/**
	 * Backfill writes only the usages whose provider is wrong.
	 *
	 * @return void
	 */
	public function testBackfillWritesOnlyTheUsagesWhoseProviderIsWrong(): void {
		$sync = $this->sync(
			modules: ['m1' => ['provider' => 'org-s']],
			usages: [
				['id' => 'u1', 'module' => 'm1', '@self' => ['id' => 'u1']],
				['id' => 'u2', 'module' => 'm1', 'provider' => 'org-s'],
				['id' => 'u3', 'module' => 'gone'],
				'not a record',
			]
		);

		$this->assertSame(expected: 1, actual: $sync->backfill());
		$this->assertSame(
			expected: [['object' => ['id' => 'u1', 'module' => 'm1', 'provider' => 'org-s'], 'schema' => 12, 'uuid' => 'u1', 'rbac' => false]],
			actual: $this->saves
		);
	}
}
