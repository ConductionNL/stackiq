<?php

/**
 * Unit tests for XmlAttributeRun.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\ArchiMate;

use OCA\Stackiq\Service\ArchiMate\XmlAttribute;
use OCA\Stackiq\Service\ArchiMate\XmlAttributeRun;
use PHPUnit\Framework\TestCase;

/**
 * Covers the text write and the written-attribute bookkeeping.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class XmlAttributeRunTest extends TestCase {

	/**
	 * Setting the text writes the element content.
	 *
	 * @return void
	 */
	public function testSetTextWritesElementContent(): void {
		$xml = new \SimpleXMLElement('<root><name/></root>');
		$run = new XmlAttributeRun($xml->name);

		$run->setText('Zaaksysteem');

		$this->assertSame('Zaaksysteem', (string) $xml->name);
		$this->assertSame($xml->name->asXML(), $run->xml->asXML());
	}//end testSetTextWritesElementContent()

	/**
	 * A recorded name is reported as written, others are not.
	 *
	 * @return void
	 */
	public function testHasReportsRecordedNames(): void {
		$run = new XmlAttributeRun(new \SimpleXMLElement('<e/>'));

		$this->assertFalse($run->has('identifier'));

		$run->record('identifier');
		$run->record('xsi:type');

		$this->assertTrue($run->has('identifier'));
		$this->assertTrue($run->has('xsi:type'));
		$this->assertFalse($run->has('name'));
	}//end testHasReportsRecordedNames()

	/**
	 * The lookup compares loosely, so a numeric name matches its int form.
	 *
	 * @return void
	 */
	public function testHasComparesLoosely(): void {
		$run = new XmlAttributeRun(new \SimpleXMLElement('<e/>'));
		$run->record('3');

		$this->assertTrue($run->has(3));
		$this->assertFalse($run->has(4));
	}//end testHasComparesLoosely()

	/**
	 * An attribute keeps its name and raw value.
	 *
	 * @return void
	 */
	public function testXmlAttributeKeepsRawValue(): void {
		$attribute = new XmlAttribute('xml:lang', ['nl']);

		$this->assertSame('xml:lang', $attribute->name);
		$this->assertSame(['nl'], $attribute->value);
	}//end testXmlAttributeKeepsRawValue()
}//end class
