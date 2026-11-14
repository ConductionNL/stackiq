<?php

/**
 * ModuleComponentGuard: an application is never part of itself or of one of its components.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-application-components/specs/application-components/spec.md#requirement-req-acm-001-an-application-can-name-the-application-it-is-a-component-of
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ModuleComponentGuard;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Modules are read from an in-memory map of uuid to partOf.
 *
 * @covers \OCA\Stackiq\Service\ModuleComponentGuard
 */
class ModuleComponentGuardTest extends TestCase {

	/**
	 * The ids the guard asked for, in order.
	 *
	 * @var array<int,string>
	 */
	private array $reads = [];

	/**
	 * A guard over modules with the given parents.
	 *
	 * @param array<string,mixed> $parents partOf by module uuid; a uuid not listed cannot be found.
	 * @param bool                $fails   Whether reading throws.
	 *
	 * @return ModuleComponentGuard
	 */
	private function guard(array $parents, bool $fails = false): ModuleComponentGuard {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null, bool $_rbac = true, bool $_multitenancy = true) use ($parents, $fails): ?ObjectEntityInterface {
				$this->assertSame([7, 42, false, false], [$register, $schema, $_rbac, $_multitenancy]);
				$this->reads[] = (string)$id;
				if ($fails === true) {
					throw new RuntimeException('database gone');
				}

				if (array_key_exists((string)$id, $parents) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn(['name' => (string)$id, 'partOf' => $parents[(string)$id]]);
				return $entity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);

		$locator = $this->createMock(ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->with('module')->willReturn(7);
		$locator->method('getSchemaIdForObjectType')->with('module')->willReturn(42);

		return new ModuleComponentGuard($container, $locator);
	}//end guard()

	/**
	 * A module that names itself is refused without a read.
	 *
	 * @return void
	 */
	public function testAModuleCannotBePartOfItself(): void {
		$this->assertSame('An application cannot be part of itself.', $this->guard(parents: [])->refusal(data: ['partOf' => 'zaak'], moduleUuid: 'zaak'));
		$this->assertSame('An application cannot be part of itself.', $this->guard(parents: [])->refusal(data: ['partOf' => ['id' => 'zaak']], moduleUuid: 'zaak'));
		$this->assertSame([], $this->reads);
	}//end testAModuleCannotBePartOfItself()

	/**
	 * Application A with component C: setting A part of C is refused, as is a deeper loop.
	 *
	 * @return void
	 */
	public function testACycleIsRefused(): void {
		$guard = $this->guard(parents: ['portaal' => 'zaak', 'formulieren' => ['uuid' => 'portaal'], 'zaak' => null]);

		$this->assertSame('An application cannot be part of one of its own components.', $guard->refusal(data: ['partOf' => 'portaal'], moduleUuid: 'zaak'));
		$this->assertSame('An application cannot be part of one of its own components.', $guard->refusal(data: ['partOf' => 'formulieren'], moduleUuid: 'zaak'));
	}//end testACycleIsRefused()

	/**
	 * A component of an application without a parent, no parent at all, and a new module are allowed.
	 *
	 * @return void
	 */
	public function testAnOrdinaryParentIsAllowed(): void {
		$guard = $this->guard(parents: ['zaak' => null, 'portaal' => 'zaak']);

		$this->assertNull($guard->refusal(data: ['partOf' => 'zaak'], moduleUuid: 'documenten'));
		$this->assertSame(['zaak'], $this->reads);
		$this->assertNull($guard->refusal(data: ['partOf' => 'portaal'], moduleUuid: 'documenten'));
		$this->assertNull($guard->refusal(data: ['partOf' => ''], moduleUuid: 'zaak'));
		$this->assertNull($guard->refusal(data: [], moduleUuid: 'zaak'));
		$this->reads = [];
		$this->assertNull($guard->refusal(data: ['partOf' => 'zaak'], moduleUuid: null));
		$this->assertSame([], $this->reads);
	}//end testAnOrdinaryParentIsAllowed()

	/**
	 * A loop elsewhere in the chain, an unreadable parent or a failing read ends the walk without a refusal.
	 *
	 * @return void
	 */
	public function testTheWalkEnds(): void {
		$this->assertNull($this->guard(parents: ['a' => 'b', 'b' => 'a'])->refusal(data: ['partOf' => 'a'], moduleUuid: 'x'));
		$this->assertSame(['a', 'b'], $this->reads);

		$this->reads = [];
		$this->assertNull($this->guard(parents: [])->refusal(data: ['partOf' => 'gone'], moduleUuid: 'x'));
		$this->assertNull($this->guard(parents: [], fails: true)->refusal(data: ['partOf' => 'a'], moduleUuid: 'x'));

		$chain = [];
		for ($i = 0; $i < 40; $i++) {
			$chain['m' . $i] = 'm' . ($i + 1);
		}

		$this->reads = [];
		$this->assertNull($this->guard(parents: $chain)->refusal(data: ['partOf' => 'm0'], moduleUuid: 'x'));
		$this->assertCount(ModuleComponentGuard::MAX_DEPTH, $this->reads);
	}//end testTheWalkEnds()

	/**
	 * A reference is a uuid or an object carrying one.
	 *
	 * @return void
	 */
	public function testReferences(): void {
		$this->assertSame('a', ModuleComponentGuard::reference(value: ' a '));
		$this->assertSame('b', ModuleComponentGuard::reference(value: ['id' => 'b', 'uuid' => 'c']));
		$this->assertSame('c', ModuleComponentGuard::reference(value: ['uuid' => 'c']));
		$this->assertNull(ModuleComponentGuard::reference(value: 12));
		$this->assertNull(ModuleComponentGuard::reference(value: ['name' => 'x']));
	}//end testReferences()
}//end class
