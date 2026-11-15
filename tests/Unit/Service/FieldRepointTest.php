<?php

/**
 * FieldRepoint moves a reference from a source id to a target id, in a scalar field and in a list field, and reports when nothing changed.
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

use OCA\Stackiq\Service\FieldRepoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FieldRepoint.
 */
#[CoversClass(FieldRepoint::class)]
class FieldRepointTest extends TestCase {

	/**
	 * A repoint of organization from org-old to org-new, optionally in a list.
	 *
	 * @param string|null $arrayField The list field, or null.
	 *
	 * @return FieldRepoint
	 */
	private function repoint(?string $arrayField = null): FieldRepoint {
		$repoint = new FieldRepoint();
		$repoint->objectType = 'module';
		$repoint->field = 'organization';
		$repoint->arrayField = $arrayField;
		$repoint->source = 'org-old';
		$repoint->target = 'org-new';
		return $repoint;
	}//end repoint()

	/**
	 * A scalar reference to the source is moved to the target.
	 *
	 * @return void
	 */
	public function testTheScalarFieldIsRepointed(): void {
		$this->assertSame(
			['name' => 'M', 'organization' => 'org-new'],
			$this->repoint()->apply(['name' => 'M', 'organization' => 'org-old'])
		);
	}//end testTheScalarFieldIsRepointed()

	/**
	 * Every source entry in the list is replaced, others kept in order.
	 *
	 * @return void
	 */
	public function testTheListFieldIsRepointed(): void {
		$this->assertSame(
			['organization' => 'org-x', 'organizations' => ['org-a', 'org-new', 'org-b', 'org-new']],
			$this->repoint('organizations')->apply(['organization' => 'org-x', 'organizations' => ['org-a', 'org-old', 'org-b', 'org-old']])
		);
	}//end testTheListFieldIsRepointed()

	/**
	 * Scalar and list are both repointed in one pass.
	 *
	 * @return void
	 */
	public function testBothAreRepointedTogether(): void {
		$this->assertSame(
			['organization' => 'org-new', 'organizations' => ['org-new']],
			$this->repoint('organizations')->apply(['organization' => 'org-old', 'organizations' => ['org-old']])
		);
	}//end testBothAreRepointedTogether()

	/**
	 * Nothing to move answers null: no reference, a list without the source,
	 * a list field that is not a list, or a loosely equal value.
	 *
	 * @return void
	 */
	public function testNothingToMoveIsNull(): void {
		$repoint = $this->repoint('organizations');

		$this->assertNull($repoint->apply(['organization' => 'org-x']));
		$this->assertNull($repoint->apply(['organizations' => ['org-a']]));
		$this->assertNull($repoint->apply(['organizations' => 'org-old']));
		$this->assertNull($repoint->apply([]));
		$this->assertNull($this->repoint()->apply(['organizations' => ['org-old']]));
	}//end testNothingToMoveIsNull()
}//end class
