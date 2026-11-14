<?php

/**
 * Tests for ArchiMateModelComparator.
 *
 * Two exchange files compared by identifier, per category
 * (architecture-round-trip-check task 1, REQ-ART-001).
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
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-001-stackiq-shall-compare-two-exchange-files-by-identifier-per-category
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use OCA\Stackiq\Service\ArchiMateModelComparator;
use PHPUnit\Framework\TestCase;

/**
 * Compares exchange files.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateModelComparator
 * @covers \OCA\Stackiq\Service\ArchiMateModelReader
 */
class ArchiMateModelComparatorTest extends TestCase {

	/**
	 * A small model with every category.
	 *
	 * @var string
	 */
	private const MODEL = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<model xmlns="http://www.opengroup.org/xsd/archimate/3.0/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" identifier="id-model">
  <name xml:lang="nl">Voorbeeld</name>
  <elements>
    <element identifier="id-a" xsi:type="ApplicationComponent">
      <name xml:lang="nl">Zaaksysteem</name>
      <documentation xml:lang="nl">Registreert zaken.</documentation>
      <properties>
        <property propertyDefinitionRef="propid-1"><value xml:lang="nl">Leverancier X</value></property>
      </properties>
    </element>
    <element identifier="id-b" xsi:type="ApplicationService">
      <name xml:lang="nl">Zaken registreren</name>
    </element>
    <element identifier="id-c" xsi:type="BusinessActor">
      <name xml:lang="nl">Medewerker</name>
    </element>
  </elements>
  <relationships>
    <relationship identifier="id-r1" source="id-a" target="id-b" xsi:type="Realization"/>
    <relationship identifier="id-r2" source="id-c" target="id-b" xsi:type="Serving"><name xml:lang="nl">gebruikt</name></relationship>
  </relationships>
  <organizations>
    <item>
      <label xml:lang="nl">Applicatie</label>
      <item identifierRef="id-a"/>
      <item>
        <label xml:lang="nl">Diensten</label>
        <item identifierRef="id-b"/>
      </item>
    </item>
  </organizations>
  <propertyDefinitions>
    <propertyDefinition identifier="propid-1" type="string"><name xml:lang="nl">Leverancier</name></propertyDefinition>
  </propertyDefinitions>
  <views>
    <diagrams>
      <view identifier="id-v1" xsi:type="Diagram" viewpoint="Application Cooperation">
        <name xml:lang="nl">Overzicht</name>
        <node identifier="id-n1" elementRef="id-a" xsi:type="Element" x="10" y="20" w="120" h="55">
          <node identifier="id-n2" elementRef="id-b" xsi:type="Element" x="30" y="40" w="100" h="40"/>
        </node>
        <connection identifier="id-c1" relationshipRef="id-r1" xsi:type="Relationship" source="id-n1" target="id-n2"/>
      </view>
    </diagrams>
  </views>
</model>
XML;

	/**
	 * The model with one string replaced.
	 *
	 * @param string $from The text to replace.
	 * @param string $to   Its replacement.
	 *
	 * @return string
	 */
	private static function with(string $from, string $to): string {
		self::assertStringContainsString($from, self::MODEL);
		return str_replace($from, $to, self::MODEL);
	}//end with()

	/**
	 * The categories that report any difference.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return list<string>
	 */
	private static function differing(array $report): array {
		return array_keys(array_filter(
			$report['categories'],
			static fn (array $category): bool => ($category['missing'] + $category['extra'] + $category['changed']) > 0
		));
	}//end differing()

	/**
	 * A file compared with itself reports every category, with counts and no difference.
	 *
	 * @return void
	 */
	public function testAFileEqualsItself(): void {
		$report = (new ArchiMateModelComparator())->compare(self::MODEL, self::MODEL);

		$this->assertTrue($report['lossless']);
		$this->assertSame(ArchiMateModelComparator::CATEGORIES, array_keys($report['categories']));
		$this->assertSame(
			['elements' => 3, 'relationships' => 2, 'views' => 1, 'viewNodes' => 2, 'propertyDefinitions' => 1, 'organizations' => 2],
			array_map(static fn (array $category): int => $category['source'], $report['categories'])
		);
		$this->assertSame([], self::differing($report));
	}//end testAFileEqualsItself()

	/**
	 * A changed relationship target is one changed relationship naming the field target, and nothing else.
	 *
	 * @return void
	 */
	public function testAChangedRelationshipTargetIsReported(): void {
		$report = (new ArchiMateModelComparator())->compare(
			self::MODEL,
			self::with('source="id-c" target="id-b"', 'source="id-c" target="id-a"')
		);

		$this->assertFalse($report['lossless']);
		$this->assertSame(['relationships'], self::differing($report));
		$this->assertSame(1, $report['categories']['relationships']['changed']);
		$this->assertSame(
			[['kind' => 'changed', 'identifier' => 'id-r2', 'name' => 'gebruikt', 'fields' => ['target']]],
			$report['categories']['relationships']['examples']
		);
	}//end testAChangedRelationshipTargetIsReported()

	/**
	 * Reversing the order of elements, relationships and nodes is not a difference.
	 *
	 * @return void
	 */
	public function testOrderIsNotADifference(): void {
		$document = new DOMDocument();
		$document->loadXML(self::MODEL);
		$xpath = new DOMXPath($document);
		foreach (["//*[local-name()='elements']", "//*[local-name()='relationships']"] as $query) {
			$parent   = $xpath->query($query)->item(0);
			$children = iterator_to_array($parent->childNodes);
			foreach (array_reverse($children) as $child) {
				$parent->appendChild($child);
			}
		}

		$report = (new ArchiMateModelComparator())->compare(self::MODEL, (string) $document->saveXML());

		$this->assertTrue($report['lossless']);
	}//end testOrderIsNotADifference()

	/**
	 * A lost element, an added one, a changed property value and a moved node are each reported in their category.
	 *
	 * @return void
	 */
	public function testLossesAndChangesAreReportedPerCategory(): void {
		$result = self::with('<element identifier="id-c" xsi:type="BusinessActor">', '<element identifier="id-d" xsi:type="BusinessActor">');
		$result = str_replace('Leverancier X', 'leverancier x', $result);
		$result = str_replace('x="30" y="40"', 'x="31" y="40"', $result);
		$result = str_replace('<item identifierRef="id-b"/>', '', $result);

		$report = (new ArchiMateModelComparator())->compare(self::MODEL, $result);
		$elements = $report['categories']['elements'];

		$this->assertSame(['missing' => 1, 'extra' => 1, 'changed' => 1], ['missing' => $elements['missing'], 'extra' => $elements['extra'], 'changed' => $elements['changed']]);
		$this->assertSame(
			[
				['kind' => 'missing', 'identifier' => 'id-c', 'name' => 'Medewerker'],
				['kind' => 'changed', 'identifier' => 'id-a', 'name' => 'Zaaksysteem', 'fields' => ['property:Leverancier']],
				['kind' => 'extra', 'identifier' => 'id-d', 'name' => 'Medewerker'],
			],
			$elements['examples']
		);
		$this->assertSame(['position'], $report['categories']['viewNodes']['examples'][0]['fields']);
		$this->assertSame('id-n2', $report['categories']['viewNodes']['examples'][0]['identifier']);
		$this->assertSame(
			[['kind' => 'changed', 'identifier' => 'Applicatie / Diensten', 'name' => 'Applicatie / Diensten', 'fields' => ['items']]],
			$report['categories']['organizations']['examples']
		);
		$this->assertSame(['elements', 'viewNodes', 'organizations'], self::differing($report));
	}//end testLossesAndChangesAreReportedPerCategory()

	/**
	 * A view whose connection or viewpoint changed is a changed view.
	 *
	 * @return void
	 */
	public function testAChangedViewIsReported(): void {
		$result = self::with('<connection identifier="id-c1" relationshipRef="id-r1"', '<connection identifier="id-c1" relationshipRef="id-r2"');
		$result = str_replace('viewpoint="Application Cooperation"', 'viewpoint="Layered"', $result);

		$report = (new ArchiMateModelComparator())->compare(self::MODEL, $result);

		$this->assertSame(['connections', 'viewpoint'], $report['categories']['views']['examples'][0]['fields']);
	}//end testAChangedViewIsReported()

	/**
	 * More than 50 differences give 50 examples and the full count.
	 *
	 * @return void
	 */
	public function testExamplesAreCappedAndCountsAreNot(): void {
		$elements = '';
		foreach (range(1, 70) as $n) {
			$elements .= '<element identifier="id-x' . $n . '" xsi:type="Node"><name>Server ' . $n . '</name></element>';
		}

		$source = self::with('<elements>', '<elements>' . $elements);
		$report = (new ArchiMateModelComparator())->compare($source, self::MODEL);

		$this->assertSame(70, $report['categories']['elements']['missing']);
		$this->assertSame(73, $report['categories']['elements']['source']);
		$this->assertSame(3, $report['categories']['elements']['result']);
		$this->assertCount(ArchiMateModelComparator::MAX_EXAMPLES, $report['categories']['elements']['examples']);
	}//end testExamplesAreCappedAndCountsAreNot()

	/**
	 * A name in two languages compares both.
	 *
	 * @return void
	 */
	public function testEveryLanguageOfANameCounts(): void {
		$source = self::with('<name xml:lang="nl">Medewerker</name>', '<name xml:lang="nl">Medewerker</name><name xml:lang="en">Employee</name>');
		$result = self::with('<name xml:lang="nl">Medewerker</name>', '<name xml:lang="nl">Medewerker</name><name xml:lang="en">Staff</name>');

		$report = (new ArchiMateModelComparator())->compare($source, $result);

		$this->assertSame(['name'], $report['categories']['elements']['examples'][0]['fields']);
		$this->assertSame('en:Employee | nl:Medewerker', $report['categories']['elements']['examples'][0]['name']);
	}//end testEveryLanguageOfANameCounts()

	/**
	 * The GEMMA fixture equals itself in reverse element order.
	 *
	 * @return void
	 */
	public function testTheGemmaFixtureEqualsItselfReversed(): void {
		$xml = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/GEMMA_testdata_below_1_5mb.xml');
		$document = new DOMDocument();
		$document->loadXML($xml);
		$parent = (new DOMXPath($document))->query("//*[local-name()='elements']")->item(0);
		foreach (array_reverse(iterator_to_array($parent->childNodes)) as $child) {
			$parent->appendChild($child);
		}

		$report = (new ArchiMateModelComparator())->compare($xml, (string) $document->saveXML());

		$this->assertTrue($report['lossless']);
		$this->assertGreaterThan(1000, $report['categories']['elements']['source']);
	}//end testTheGemmaFixtureEqualsItselfReversed()

	/**
	 * A file that is not XML is refused, naming which one.
	 *
	 * @return void
	 */
	public function testAFileThatIsNotXmlIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The result is not an ArchiMate exchange file.');

		(new ArchiMateModelComparator())->compare(self::MODEL, 'not xml <');
	}//end testAFileThatIsNotXmlIsRefused()
}//end class
