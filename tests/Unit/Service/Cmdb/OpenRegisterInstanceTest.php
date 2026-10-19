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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * An OpenRegister class is built with the arguments its constructor declares.
 */
#[CoversClass(OpenRegisterInstance::class)]
class OpenRegisterInstanceTest extends TestCase {
	/**
	 * Reset the translations override.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		OpenRegisterInstance::$l10nOverride = null;
	}//end tearDown()

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
		OpenRegisterInstance::$l10nOverride = static fn (): IL10N => $l10n;
		$class = new class($l10n) {
			public function __construct(public readonly IL10N $l10n) {
			}
		};

		$object = (new OpenRegisterInstance(container: $this->createMock(ContainerInterface::class)))->build(class: $class::class);

		$this->assertInstanceOf($class::class, $object);
		$this->assertSame($l10n, $object->l10n);
	}//end testAClassRequiringTranslationsGetsThem()

	/**
	 * A constructor needing anything else, or translations that cannot be resolved, gives null.
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

		OpenRegisterInstance::$l10nOverride = static function (): IL10N {
			throw new \RuntimeException('no factory');
		};
		$needsL10n = new class($this->createMock(IL10N::class)) {
			public function __construct(public readonly IL10N $l10n) {
			}
		};
		$this->assertNull((new OpenRegisterInstance(container: $container))->build(class: $needsL10n::class));
	}//end testAnUnbuildableClassGivesNull()
}//end class
