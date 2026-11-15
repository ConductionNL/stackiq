<?php

/**
 * Tests for OpenRegisterInstance.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

use OCA\Stackiq\Service\Cmdb\OpenRegisterInstance;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * An OpenRegister class is built with the arguments its constructor declares.
 */
#[CoversClass(OpenRegisterInstance::class)]
class OpenRegisterInstanceTest extends TestCase {
	/**
	 * A class without constructor parameters (older OpenRegister) is built as is.
	 *
	 * @return void
	 */
	public function testAClassWithoutParametersIsBuilt(): void {
		$object = (new OpenRegisterInstance(container: $this->createMock(ContainerInterface::class)))->build(class: \stdClass::class);

		$this->assertInstanceOf(\stdClass::class, $object);
	}//end testAClassWithoutParametersIsBuilt()

	/**
	 * A class that requires an IL10N (newer OpenRegister) gets OpenRegister's translations.
	 *
	 * @return void
	 */
	public function testAClassRequiringTranslationsGetsThem(): void {
		$l10n = $this->createMock(IL10N::class);
		$factory = $this->createMock(IFactory::class);
		$factory->expects($this->once())->method('get')->with('openregister')->willReturn($l10n);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(IFactory::class)->willReturn($factory);
		$class = new class($l10n) {
			public function __construct(public readonly IL10N $l10n) {
			}
		};

		$object = (new OpenRegisterInstance(container: $container))->build(class: $class::class);

		$this->assertInstanceOf($class::class, $object);
		$this->assertSame($l10n, $object->l10n);
	}//end testAClassRequiringTranslationsGetsThem()

	/**
	 * A constructor needing anything else, or a container without the translation factory, gives null.
	 *
	 * @return void
	 */
	public function testAnUnbuildableClassGivesNull(): void {
		$container = $this->createMock(ContainerInterface::class);
		$other = new class('x') {
			public function __construct(public readonly string $value) {
			}
		};
		$this->assertNull((new OpenRegisterInstance(container: $container))->build(class: $other::class));

		$noFactory = $this->createMock(ContainerInterface::class);
		$noFactory->method('get')->willThrowException(new \RuntimeException('not in this container'));
		$needsL10n = new class($this->createMock(IL10N::class)) {
			public function __construct(public readonly IL10N $l10n) {
			}
		};
		$this->assertNull((new OpenRegisterInstance(container: $noFactory))->build(class: $needsL10n::class));
	}//end testAnUnbuildableClassGivesNull()
}//end class
