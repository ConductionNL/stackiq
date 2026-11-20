<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface;
use OCA\Stackiq\Service\MaintenanceRecipientService;
use OCA\Stackiq\Service\UsageOwnerRecipientResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A notification about an application reaches the owners of every usage of it.
 *
 * @covers \OCA\Stackiq\Service\UsageOwnerRecipientResolver
 * @uses   \OCA\Stackiq\Service\MaintenanceRecipientService
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/softwarecatalog-notifications/spec.md
 */
final class UsageOwnerRecipientResolverTest extends TestCase {

	/**
	 * An object with the given data.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end object()

	/**
	 * The resolver over an owner lookup and a container that does or does not hold the ObjectService.
	 *
	 * @param MaintenanceRecipientService $owners    The owner lookup.
	 * @param bool                        $available Whether OpenRegister's ObjectService resolves.
	 *
	 * @return UsageOwnerRecipientResolver
	 */
	private function resolver(MaintenanceRecipientService $owners, bool $available = true): UsageOwnerRecipientResolver {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($available === true) {
			$container->method('get')->with(ObjectServiceInterface::class)->willReturn(
				$this->createMock(originalClassName: ObjectServiceInterface::class)
			);
		} else {
			$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		}

		return new UsageOwnerRecipientResolver(owners: $owners, container: $container, logger: new NullLogger());
	}//end resolver()

	/**
	 * It is an open register recipient resolver.
	 *
	 * @return void
	 */
	public function testItIsAnOpenRegisterRecipientResolver(): void {
		$this->assertInstanceOf(
			expected: RecipientResolverInterface::class,
			actual: $this->resolver(owners: $this->createMock(originalClassName: MaintenanceRecipientService::class))
		);
	}

	/**
	 * Avulnerability reaches the owners of the usages of each affected application.
	 *
	 * @return void
	 */
	public function testAVulnerabilityReachesTheOwnersOfTheUsagesOfEachAffectedApplication(): void {
		$owners = $this->createMock(originalClassName: MaintenanceRecipientService::class);
		$owners->expects($this->exactly(count: 2))->method('ownerUserIds')
			->willReturnCallback(static fn (ObjectServiceInterface $service, string $moduleId): array => match ($moduleId) {
				'zaaksysteem-x' => ['anna', 'bert'],
				'archief-y' => ['bert', 'carla'],
			});

		$uids = $this->resolver(owners: $owners)->resolve(
			$this->object(data: ['name' => 'CVE-2026-1234', 'modules' => ['zaaksysteem-x', ['id' => 'archief-y', 'name' => 'Archief Y']]]),
			[]
		);

		$this->assertSame(expected: ['anna', 'bert', 'carla'], actual: $uids);
	}

	/**
	 * Amodule version reaches the owners of the usages of its application.
	 *
	 * @return void
	 */
	public function testAModuleVersionReachesTheOwnersOfTheUsagesOfItsApplication(): void {
		$owners = $this->createMock(originalClassName: MaintenanceRecipientService::class);
		$owners->expects($this->once())->method('ownerUserIds')
			->with($this->isInstanceOf(className: ObjectServiceInterface::class), 'zaaksysteem-x')
			->willReturn(['anna']);

		$uids = $this->resolver(owners: $owners)->resolve($this->object(data: ['version' => '5.2', 'module' => ['uuid' => 'zaaksysteem-x']]), []);

		$this->assertSame(expected: ['anna'], actual: $uids);
	}

	/**
	 * An object naming no application reaches nobody.
	 *
	 * @return void
	 */
	public function testAnObjectNamingNoApplicationReachesNobody(): void {
		$owners = $this->createMock(originalClassName: MaintenanceRecipientService::class);
		$owners->expects($this->never())->method('ownerUserIds');

		$this->assertSame(
			expected: [],
			actual: $this->resolver(owners: $owners)->resolve($this->object(data: ['name' => 'CVE-2026-1234', 'modules' => []]), [])
		);
	}

	/**
	 * Without open register nobody is resolved.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNobodyIsResolved(): void {
		$owners = $this->createMock(originalClassName: MaintenanceRecipientService::class);
		$owners->expects($this->never())->method('ownerUserIds');

		$this->assertSame(
			expected: [],
			actual: $this->resolver(owners: $owners, available: false)->resolve($this->object(data: ['modules' => ['zaaksysteem-x']]), [])
		);
	}

	/**
	 * The application ids are read from modules then module once each.
	 *
	 * @return void
	 */
	public function testTheApplicationIdsAreReadFromModulesThenModuleOnceEach(): void {
		$this->assertSame(
			expected: ['a', 'b', 'c'],
			actual: UsageOwnerRecipientResolver::applicationIds(
				['modules' => ['a', ['id' => 'b'], 'a', '', null, ['name' => 'no id']], 'module' => 'c']
			)
		);
		$this->assertSame(expected: ['c'], actual: UsageOwnerRecipientResolver::applicationIds(['module' => ['id' => 'c']]));
		$this->assertSame(expected: [], actual: UsageOwnerRecipientResolver::applicationIds([]));
	}
}
