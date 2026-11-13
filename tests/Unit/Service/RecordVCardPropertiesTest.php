<?php

/**
 * Unit tests for RecordVCard::properties.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\RecordVCard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the vCard properties built from organisation and person records.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @covers \OCA\Stackiq\Service\RecordVCard
 */
class RecordVCardPropertiesTest extends TestCase {

	/**
	 * An organisation record gives an org card with KVK nickname, URL and channels.
	 *
	 * @return void
	 */
	public function testOrganisationRecord(): void {
		$this->assertSame(
			[
				'FN' => 'Gemeente Test',
				'ORG' => 'Gemeente Test',
				'KIND' => 'org',
				'X-KVK' => '0363',
				'NICKNAME' => '0363',
				'URL' => 'https://test.nl',
				'EMAIL' => 'info@test.nl',
				'TEL' => '010-1234567',
			],
			(new RecordVCard())->properties(
				'organization',
				[
					'name' => ' Gemeente Test ',
					'cbsCode' => '0363',
					'website' => 'https://test.nl',
					'email' => 'info@test.nl',
					'telefoonnummer' => ' 010-1234567 ',
				]
			)
		);

	}//end testOrganisationRecord()

	/**
	 * Empty optional organisation fields are left out.
	 *
	 * @return void
	 */
	public function testOrganisationWithoutOptionalFields(): void {
		$this->assertSame(
			['FN' => '', 'ORG' => '', 'KIND' => 'org'],
			(new RecordVCard())->properties('organization', ['cbsCode' => '  ', 'website' => ''])
		);

	}//end testOrganisationWithoutOptionalFields()

	/**
	 * A person record joins tussenvoegsel and achternaam as the family name and
	 * prefers e-mailadres over email.
	 *
	 * @return void
	 */
	public function testPersonRecord(): void {
		$this->assertSame(
			[
				'FN' => 'Jan van Dijk',
				'N' => 'van Dijk;Jan;;;',
				'TITLE' => 'Beheerder',
				'EMAIL' => 'jan@test.nl',
			],
			(new RecordVCard())->properties(
				'contactpersoon',
				[
					'voornaam' => 'Jan',
					'tussenvoegsel' => 'van',
					'achternaam' => 'Dijk',
					'role' => 'Beheerder',
					'e-mailadres' => 'jan@test.nl',
					'email' => 'other@test.nl',
				]
			)
		);

	}//end testPersonRecord()

	/**
	 * A person without tussenvoegsel or role has no stray spaces or title.
	 *
	 * @return void
	 */
	public function testPersonWithoutTussenvoegsel(): void {
		$this->assertSame(
			['FN' => 'Piet Jansen', 'N' => 'Jansen;Piet;;;'],
			(new RecordVCard())->properties('contactpersoon', ['voornaam' => 'Piet', 'achternaam' => 'Jansen', 'role' => ' '])
		);

	}//end testPersonWithoutTussenvoegsel()

	/**
	 * The first value of a vCard property is read from plain and value lists.
	 *
	 * @return void
	 */
	public function testFirstValue(): void {
		$card = new RecordVCard();

		$this->assertSame('a', $card->firstValue('a'));
		$this->assertSame('b', $card->firstValue(['b', 'c']));
		$this->assertSame('d', $card->firstValue([['value' => 'd'], ['value' => 'e']]));
		$this->assertSame('', $card->firstValue([]));

	}//end testFirstValue()

}//end class
