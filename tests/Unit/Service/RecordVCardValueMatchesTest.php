<?php

/**
 * Characterisation tests for RecordVCard::valueMatches.
 *
 * Pins which single and multi-valued vCard property values match a needle.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\RecordVCard;
use PHPUnit\Framework\TestCase;

/**
 * Cases for matching a vCard property value.
 *
 * @covers \OCA\Stackiq\Service\RecordVCard
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 */
class RecordVCardValueMatchesTest extends TestCase {

	/**
	 * Values compared trimmed and case-insensitively, single and multi-valued; an empty needle never matches.
	 *
	 * @return void
	 */
	public function testValuesMatchTrimmedAndCaseInsensitively(): void {
		$vcard = new RecordVCard();
		$cases = [
			[' Alice@Example.com ', 'alice@example.com', true],
			['alice@example.com', ' ALICE@example.com ', true],
			['alice@example.com', 'bob@example.com', false],
			['', '', false],
			['anything', '   ', false],
			[null, 'x', false],
			[42, '42', true],
			[[], 'x', false],
			[['bob@x.nl', ' ALICE@x.nl'], 'alice@x.nl', true],
			[[['value' => 'Alice@x.nl', 'type' => 'work']], 'alice@x.nl', true],
			[[['type' => 'work'], 'carol@x.nl'], 'carol@x.nl', true],
			[[['type' => 'work']], '', false],
			[[['value' => 'bob@x.nl'], null], 'alice@x.nl', false],
			[[[], 'x'], 'x', true],
		];
		foreach ($cases as [$value, $needle, $expected]) {
			$this->assertSame($expected, $vcard->valueMatches($value, $needle), var_export([$value, $needle], true));
		}

	}//end testValuesMatchTrimmedAndCaseInsensitively()
}//end class
