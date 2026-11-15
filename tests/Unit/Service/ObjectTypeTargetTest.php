<?php

/**
 * ObjectTypeTarget resolves an object type to its register and schema, or to nothing when either is not configured.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ObjectTypeTarget;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ObjectTypeTarget.
 */
#[CoversClass(ObjectTypeTarget::class)]
class ObjectTypeTargetTest extends TestCase {

	/**
	 * A target over a locator answering the given ids.
	 *
	 * @param int|null $register The register id.
	 * @param int|null $schema   The schema id.
	 *
	 * @return ObjectTypeTarget
	 */
	private function target(?int $register, ?int $schema): ObjectTypeTarget {
		$locator = $this->createMock(ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->with('module')->willReturn($register);
		$locator->method('getSchemaIdForObjectType')->with('module')->willReturn($schema);

		return new ObjectTypeTarget($locator);
	}//end target()

	/**
	 * A configured type resolves to its register and schema.
	 *
	 * @return void
	 */
	public function testAConfiguredTypeResolves(): void {
		$this->assertSame(['register' => 3, 'schema' => 17], $this->target(3, 17)->resolve('module'));
	}//end testAConfiguredTypeResolves()

	/**
	 * A missing register or schema resolves to nothing.
	 *
	 * @return void
	 */
	public function testAMissingHalfResolvesToNothing(): void {
		$this->assertNull($this->target(null, 17)->resolve('module'));
		$this->assertNull($this->target(3, null)->resolve('module'));
		$this->assertNull($this->target(null, null)->resolve('module'));
	}//end testAMissingHalfResolvesToNothing()
}//end class
