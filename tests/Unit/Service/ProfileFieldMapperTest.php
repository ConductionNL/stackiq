<?php

/**
 * ProfileFieldMapper maps Nextcloud profile keys onto contact fields and drops the keys it does not know.
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

use OCA\Stackiq\Service\ProfileFieldMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProfileFieldMapper.
 */
#[CoversClass(ProfileFieldMapper::class)]
class ProfileFieldMapperTest extends TestCase {

	/**
	 * Known keys map to their contact field; unknown keys to null.
	 *
	 * @return void
	 */
	public function testMapsKnownKeys(): void {
		$mapper = new ProfileFieldMapper();

		$this->assertSame('e-mailadres', $mapper->mapToContactField('email'));
		$this->assertSame('name', $mapper->mapToContactField('displayname'));
		$this->assertSame('organization', $mapper->mapToContactField('organisation'));
		$this->assertSame('aanhef', $mapper->mapToContactField('headline'));
		$this->assertNull($mapper->mapToContactField('Email'));
		$this->assertNull($mapper->mapToContactField('avatar'));
	}//end testMapsKnownKeys()

	/**
	 * The payload carries the mapped fields with their values and drops the rest.
	 *
	 * @return void
	 */
	public function testBuildsThePayload(): void {
		$payload = (new ProfileFieldMapper())->buildContactPayload(
			['email' => 'a@example.org', 'avatar' => 'x', 'phone' => '0201234567', 'biography' => '']
		);

		$this->assertSame(['e-mailadres' => 'a@example.org', 'telefoonnummer' => '0201234567', 'biografie' => ''], $payload);
	}//end testBuildsThePayload()

	/**
	 * The field map lists ten keys, each mapping to the field mapToContactField answers.
	 *
	 * @return void
	 */
	public function testTheFieldMapAgreesWithTheMapper(): void {
		$mapper = new ProfileFieldMapper();
		$map = $mapper->getFieldMap();

		$this->assertCount(10, $map);
		foreach ($map as $ncKey => $field) {
			$this->assertSame($field, $mapper->mapToContactField($ncKey));
		}
	}//end testTheFieldMapAgreesWithTheMapper()
}//end class
