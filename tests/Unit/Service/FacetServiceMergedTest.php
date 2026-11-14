<?php

/**
 * FacetServiceMergedTest
 *
 * operations-record-reconciliation REQ-RRC-004: the catalogue lists and their
 * facet counts leave out records OpenRegister merged into another.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\FacetService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FacetService::withoutMerged().
 */
class FacetServiceMergedTest extends TestCase {

	/**
	 * Merged records leave; active ones and records without a status stay, in order.
	 *
	 * @return void
	 */
	public function testMergedRecordsLeaveTheList(): void {
		$objects = [
			['id' => 'a', 'recordStatus' => 'Active'],
			['id' => 'b', 'recordStatus' => 'Merged', 'mergedInto' => 'a'],
			['id' => 'c'],
			['id' => 'd', 'recordStatus' => 'merged'],
		];

		$this->assertSame(
			[['id' => 'a', 'recordStatus' => 'Active'], ['id' => 'c'], ['id' => 'd', 'recordStatus' => 'merged']],
			FacetService::withoutMerged(objects: $objects)
		);
		$this->assertSame([], FacetService::withoutMerged(objects: []));
	}//end testMergedRecordsLeaveTheList()
}//end class
