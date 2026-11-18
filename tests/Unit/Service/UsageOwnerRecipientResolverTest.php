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
		$entity = $this->createMock(ObjectEntity::class);
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
		$container = $this->createMock(ContainerInterface::class);
		if ($available === true) {
			$container->method('get')->with(ObjectServiceInterface::class)->willReturn($this->createMock(ObjectServiceInterface::class));
		} else {
			$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		}

		return new UsageOwnerRecipientResolver($owners, $container, new NullLogger());
	}//end resolver()

	public function testItIsAnOpenRegisterRecipientResolver(): void {
		$this->assertInstanceOf(RecipientResolverInterface::class, $this->resolver($this->createMock(MaintenanceRecipientService::class)));
	}

	public function testAVulnerabilityReachesTheOwnersOfTheUsagesOfEachAffectedApplication(): void {
		$owners = $this->createMock(MaintenanceRecipientService::class);
		$owners->expects($this->exactly(2))->method('ownerUserIds')
			->willReturnCallback(static fn (ObjectServiceInterface $service, string $moduleId): array => match ($moduleId) {
				'zaaksysteem-x' => ['anna', 'bert'],
				'archief-y' => ['bert', 'carla'],
			});

		$uids = $this->resolver($owners)->resolve(
			$this->object(['name' => 'CVE-2026-1234', 'modules' => ['zaaksysteem-x', ['id' => 'archief-y', 'name' => 'Archief Y']]]),
			[]
		);

		$this->assertSame(['anna', 'bert', 'carla'], $uids);
	}

	public function testAModuleVersionReachesTheOwnersOfTheUsagesOfItsApplication(): void {
		$owners = $this->createMock(MaintenanceRecipientService::class);
		$owners->expects($this->once())->method('ownerUserIds')
			->with($this->isInstanceOf(ObjectServiceInterface::class), 'zaaksysteem-x')
			->willReturn(['anna']);

		$uids = $this->resolver($owners)->resolve($this->object(['version' => '5.2', 'module' => ['uuid' => 'zaaksysteem-x']]), []);

		$this->assertSame(['anna'], $uids);
	}

	public function testAnObjectNamingNoApplicationReachesNobody(): void {
		$owners = $this->createMock(MaintenanceRecipientService::class);
		$owners->expects($this->never())->method('ownerUserIds');

		$this->assertSame([], $this->resolver($owners)->resolve($this->object(['name' => 'CVE-2026-1234', 'modules' => []]), []));
	}

	public function testWithoutOpenRegisterNobodyIsResolved(): void {
		$owners = $this->createMock(MaintenanceRecipientService::class);
		$owners->expects($this->never())->method('ownerUserIds');

		$this->assertSame([], $this->resolver($owners, false)->resolve($this->object(['modules' => ['zaaksysteem-x']]), []));
	}

	public function testTheApplicationIdsAreReadFromModulesThenModuleOnceEach(): void {
		$this->assertSame(
			['a', 'b', 'c'],
			UsageOwnerRecipientResolver::applicationIds(['modules' => ['a', ['id' => 'b'], 'a', '', null, ['name' => 'no id']], 'module' => 'c'])
		);
		$this->assertSame(['c'], UsageOwnerRecipientResolver::applicationIds(['module' => ['id' => 'c']]));
		$this->assertSame([], UsageOwnerRecipientResolver::applicationIds([]));
	}
}
