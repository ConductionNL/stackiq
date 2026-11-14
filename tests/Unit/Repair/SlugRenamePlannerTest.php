<?php

/**
 * Unit tests for SlugRenamePlanner.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\SlugRenamePlanner;
use PHPUnit\Framework\TestCase;

/**
 * Which slug renames are safe to make on this install, and which are refused.
 *
 * @covers \OCA\Stackiq\Repair\SlugRenamePlanner
 */
class SlugRenamePlannerTest extends TestCase {

	/**
	 * A slug present here whose target is free is renamed.
	 *
	 * @return void
	 */
	public function testAPresentSlugWithAFreeTargetIsRenamed(): void {
		$plan = (new SlugRenamePlanner(existing: ['voorziening', 'other']))->plan(map: ['voorziening' => 'application']);

		$this->assertSame(['renames' => ['voorziening' => 'application'], 'refused' => []], $plan);
	}//end testAPresentSlugWithAFreeTargetIsRenamed()

	/**
	 * A slug this install does not have is neither renamed nor refused.
	 *
	 * @return void
	 */
	public function testAnAbsentSlugIsNothingToDo(): void {
		$plan = (new SlugRenamePlanner(existing: ['other']))->plan(map: ['voorziening' => 'application']);

		$this->assertSame(['renames' => [], 'refused' => []], $plan);
	}//end testAnAbsentSlugIsNothingToDo()

	/**
	 * A target that already exists is refused with the reason, and nothing is renamed.
	 *
	 * @return void
	 */
	public function testATakenTargetIsRefused(): void {
		$plan = (new SlugRenamePlanner(existing: ['voorziening', 'application']))->plan(map: ['voorziening' => 'application']);

		$this->assertSame(['renames' => [], 'refused' => ['voorziening' => "target slug 'application' already exists"]], $plan);
	}//end testATakenTargetIsRefused()

	/**
	 * A target claimed by an earlier rename in the same plan is taken for the later one.
	 *
	 * @return void
	 */
	public function testATargetClaimedEarlierInThePlanIsRefusedLater(): void {
		$plan = (new SlugRenamePlanner(existing: ['organisatie', 'organisaties']))->plan(
			map: ['organisatie' => 'organization', 'organisaties' => 'organization']
		);

		$this->assertSame(
			[
				'renames' => ['organisatie' => 'organization'],
				'refused' => ['organisaties' => "target slug 'organization' already exists"],
			],
			$plan
		);
	}//end testATargetClaimedEarlierInThePlanIsRefusedLater()

	/**
	 * Matching is exact: a slug differing only in case is not the same slug.
	 *
	 * @return void
	 */
	public function testMatchingIsExact(): void {
		$plan = (new SlugRenamePlanner(existing: ['Voorziening']))->plan(map: ['voorziening' => 'application']);

		$this->assertSame(['renames' => [], 'refused' => []], $plan);
	}//end testMatchingIsExact()
}//end class
