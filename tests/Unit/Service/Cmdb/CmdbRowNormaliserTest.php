<?php

/**
 * Tests for the CMDB row normaliser.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

use OCA\Stackiq\Service\Cmdb\CmdbRowNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Excel serial dates, ids and trimming.
 */
class CmdbRowNormaliserTest extends TestCase {
	/**
	 * The serials of the anonymised export become the dates design.md names; ids lose their decimal part.
	 *
	 * @return void
	 */
	public function testSerialDatesAndIdsAreNormalised(): void {
		$row = (new CmdbRowNormaliser())->normalise(
			cells: [
				'Datum' => 45111.380322627316,
				'Referentie datum wijziging' => 46232.552113113423,
				'End-of-Life Functioneel' => 53359,
				'APPID' => 1234.0,
				'Applicatie Code' => ' APP-test123 ',
				'Applicatie Naam' => '  naamtest123 ',
				'Vendor' => null,
			],
			dateColumns: ['Datum', 'Referentie datum wijziging', 'End-of-Life Functioneel'],
			idColumns: ['APPID']
		);

		$this->assertSame(
			[
				'Datum' => '2023-07-04',
				'Referentie datum wijziging' => '2026-07-29',
				'End-of-Life Functioneel' => '2046-02-01',
				'APPID' => '1234',
				'Applicatie Code' => 'APP-test123',
				'Applicatie Naam' => 'naamtest123',
				'Vendor' => '',
			],
			$row
		);
	}//end testSerialDatesAndIdsAreNormalised()

	/**
	 * A value the profile lists as empty for its column becomes '', case-insensitively and before
	 * the date conversion; the same value in another column stays.
	 *
	 * @return void
	 */
	public function testEmptyValuesBecomeEmpty(): void {
		$row = (new CmdbRowNormaliser())->normalise(
			cells: ['BNN Classificatie' => 'nb', 'End-of-Life Functioneel' => 49675, 'Roepnaam' => 'NB', 'Datum' => 49675],
			dateColumns: ['End-of-Life Functioneel', 'Datum'],
			idColumns: [],
			emptyValues: ['BNN Classificatie' => ['NB'], 'End-of-Life Functioneel' => ['49675']]
		);

		$this->assertSame(['BNN Classificatie' => '', 'End-of-Life Functioneel' => '', 'Roepnaam' => 'NB', 'Datum' => '2036-01-01'], $row);
	}//end testEmptyValuesBecomeEmpty()

	/**
	 * The string forms of serials and ids convert the same way.
	 *
	 * @return void
	 */
	public function testStringSerialsAndIdsConvertToo(): void {
		$normaliser = new CmdbRowNormaliser();

		$this->assertSame('2023-07-04', $normaliser->normaliseDate(value: '45111.380322627316'));
		$this->assertSame('1234', $normaliser->normaliseId(value: '1234.0'));
		$this->assertSame('1234', $normaliser->normaliseId(value: 1234));
		$this->assertSame('12.5', $normaliser->normaliseId(value: 12.5));
		$this->assertSame('APP-1.0', $normaliser->normaliseId(value: 'APP-1.0'));
	}//end testStringSerialsAndIdsConvertToo()

	/**
	 * A non-numeric date stays as it is, for the pack's date transform to judge; out-of-range serials too.
	 *
	 * @return void
	 */
	public function testNonSerialDatesStayAsTheyAre(): void {
		$normaliser = new CmdbRowNormaliser();

		$this->assertSame('2026-10-01', $normaliser->normaliseDate(value: ' 2026-10-01 '));
		$this->assertSame('onbekend', $normaliser->normaliseDate(value: 'onbekend'));
		$this->assertSame('0', $normaliser->normaliseDate(value: 0));
		$this->assertSame('', $normaliser->normaliseDate(value: null));
	}//end testNonSerialDatesStayAsTheyAre()

	/**
	 * Excel's 1900 leap-year bug and the 1904 date system.
	 *
	 * @return void
	 */
	public function testDateSystemsAndTheLeapYearBug(): void {
		$normaliser = new CmdbRowNormaliser();

		$this->assertSame('1900-01-01', $normaliser->normaliseDate(value: 1));
		$this->assertSame('1900-02-28', $normaliser->normaliseDate(value: 59));
		$this->assertSame('1900-03-01', $normaliser->normaliseDate(value: 61));
		$this->assertSame('2023-07-04', $normaliser->normaliseDate(value: 43649, date1904: true));
	}//end testDateSystemsAndTheLeapYearBug()

	/**
	 * Booleans become TRUE/FALSE text; whole floats lose their decimal part.
	 *
	 * @return void
	 */
	public function testOtherScalarsBecomeText(): void {
		$row = (new CmdbRowNormaliser())->normalise(cells: ['a' => true, 'b' => false, 'c' => 2.0, 'd' => 2.25], dateColumns: [], idColumns: []);

		$this->assertSame(['a' => 'TRUE', 'b' => 'FALSE', 'c' => '2', 'd' => '2.25'], $row);
	}//end testOtherScalarsBecomeText()

	/**
	 * A non-breaking space, a zero-width space or a byte-order mark around a value is trimmed, as plain whitespace is.
	 *
	 * @return void
	 */
	public function testUnicodeWhitespaceIsTrimmed(): void {
		$row = (new CmdbRowNormaliser())->normalise(
			cells: ['APPID' => "APP-1\u{00A0}", 'Applicatie Naam' => "\u{FEFF}\u{200B} Naam\u{00A0}met spatie \u{00A0}"],
			dateColumns: [],
			idColumns: ['APPID']
		);

		$this->assertSame('APP-1', $row['APPID']);
		$this->assertSame("Naam\u{00A0}met spatie", $row['Applicatie Naam'], 'only the ends are trimmed');
	}//end testUnicodeWhitespaceIsTrimmed()
}//end class
