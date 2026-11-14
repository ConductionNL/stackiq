<?php

/**
 * Unit tests for MigrateRegisterSlugDecisions.
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

use OCA\Stackiq\Repair\MigrateRegisterSlugDecisions;
use OCA\Stackiq\Repair\SlugRenamePlanner;
use PHPUnit\Framework\TestCase;

/**
 * The pure decisions behind the register slug migration.
 *
 * @covers \OCA\Stackiq\Repair\MigrateRegisterSlugDecisions
 * @uses   \OCA\Stackiq\Repair\SlugRenamePlanner
 */
class MigrateRegisterSlugDecisionsTest extends TestCase {

	/**
	 * The plan is the planner's, over the slugs that exist.
	 *
	 * @return void
	 */
	public function testThePlanRenamesWhatExistsAndRefusesATakenTarget(): void {
		$plan = (new MigrateRegisterSlugDecisions())->plan(
			map: ['voorzieningen' => 'stackiq', 'oud' => 'nieuw', 'weg' => 'elders'],
			existing: ['voorzieningen', 'oud', 'nieuw']
		);

		$this->assertSame(
			['renames' => ['voorzieningen' => 'stackiq'], 'refused' => ['oud' => "target slug 'nieuw' already exists"]],
			$plan
		);
	}//end testThePlanRenamesWhatExistsAndRefusesATakenTarget()

	/**
	 * Slugs are read from rows as strings, a row without one giving ''.
	 *
	 * @return void
	 */
	public function testSlugsAreReadFromRows(): void {
		$this->assertSame(
			['voorzieningen', '', '42'],
			(new MigrateRegisterSlugDecisions())->slugsFrom(rows: [['slug' => 'voorzieningen', 'id' => 1], ['id' => 2], ['slug' => 42]])
		);
	}//end testSlugsAreReadFromRows()

	/**
	 * The old slugs, then the new ones, each read once.
	 *
	 * @return void
	 */
	public function testBothSidesOfEveryRenameAreReadOnce(): void {
		$this->assertSame(
			['a', 'b', 'c', 'x'],
			(new MigrateRegisterSlugDecisions())->slugsToRead(map: ['a' => 'x', 'b' => 'x', 'c' => 'a'])
		);
	}//end testBothSidesOfEveryRenameAreReadOnce()

	/**
	 * One placeholder per value, none for zero or a negative count.
	 *
	 * @return void
	 */
	public function testPlaceholdersMatchTheCount(): void {
		$decisions = new MigrateRegisterSlugDecisions();

		$this->assertSame('?,?,?', $decisions->placeholders(count: 3));
		$this->assertSame('?', $decisions->placeholders(count: 1));
		$this->assertSame('', $decisions->placeholders(count: 0));
		$this->assertSame('', $decisions->placeholders(count: -2));
	}//end testPlaceholdersMatchTheCount()
}//end class
