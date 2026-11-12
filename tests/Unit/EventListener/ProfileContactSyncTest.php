<?php

/**
 * Unit tests for ProfileContactSync.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\EventListener
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

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\Stackiq\EventListener\ProfileContactSync;
use PHPUnit\Framework\TestCase;

/**
 * Where a profile sync looks for the user's contactpersoon.
 *
 * @covers \OCA\Stackiq\EventListener\ProfileContactSync
 */
class ProfileContactSyncTest extends TestCase {
	/**
	 * Register and schema come from the voorzieningen configuration.
	 *
	 * @return void
	 */
	public function testRegisterAndSchemaComeFromTheConfiguration(): void {
		$sync                      = new ProfileContactSync();
		$sync->voorzieningenConfig = ['register' => '4', 'contactpersoon_schema' => 17, 'organisatie_schema' => 9];

		self::assertSame('4', $sync->register());
		self::assertSame(17, $sync->schema());
		self::assertSame(['register' => 4, 'schema' => 17], $sync->selfQuery());
	}

	/**
	 * Without configuration both are empty and the query asks for register and schema zero.
	 *
	 * @return void
	 */
	public function testAMissingConfigurationIsEmpty(): void {
		$sync = new ProfileContactSync();

		self::assertSame('', $sync->register());
		self::assertSame('', $sync->schema());
		self::assertSame(['register' => 0, 'schema' => 0], $sync->selfQuery());
		self::assertSame('', $sync->userId);
		self::assertSame([], $sync->patch);
	}
}
