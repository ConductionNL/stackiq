<?php

/**
 * Tests for the GEMMA standard linker.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\ArchiMate;

use OCA\Stackiq\Service\ArchiMate\GemmaStandardLinker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The links derived from elements and relationships in import shape.
 *
 * @covers \OCA\Stackiq\Service\ArchiMate\GemmaStandardLinker
 * @covers \OCA\Stackiq\Service\ArchiMate\GemmaObjectReader
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class GemmaStandardLinkerTest extends TestCase {

	private const COMPONENT = '0b1e7c2a-0000-4000-8000-00000000c001';
	private const STANDARD  = '0b1e7c2a-0000-4000-8000-00000000a001';
	private const OTHER     = '0b1e7c2a-0000-4000-8000-00000000a002';

	/**
	 * A repeated role link and a standard in both roles are each listed once.
	 *
	 * @return void
	 */
	public function testRepeatedLinksAreListedOnce(): void {
		$linked = $this->link(
			relationships: [
				['source' => 'id-rc', 'target' => 'id-s1', 'verbindingsrol' => 'Aanbevolen'],
				['source' => 'id-rc', 'target' => 'id-s1', 'verbindingsrol' => 'Aanbevolen'],
				['source' => 'id-rc', 'target' => 'id-s2', 'verbindingsrol' => 'Aanbevolen'],
				['source' => 'id-s2', 'target' => 'id-rc', 'verbindingsrol' => 'verplicht'],
			]
		);

		self::assertSame([self::STANDARD, self::OTHER], $linked[0]['recommendedStandards']);
		self::assertSame([self::OTHER], $linked[0]['mandatoryStandards']);
		self::assertSame([self::STANDARD, self::OTHER], $linked[0]['standards']);
	}//end testRepeatedLinksAreListedOnce()

	/**
	 * A role the linker does not know still marks the component as linked, with no standards.
	 *
	 * @return void
	 */
	public function testAnUnknownRoleLinksNoStandard(): void {
		$linked = $this->link(relationships: [['source' => 'id-rc', 'target' => 'id-s1', 'verbindingsrol' => 'Optioneel']]);

		self::assertSame([], $linked[0]['recommendedStandards']);
		self::assertSame([], $linked[0]['mandatoryStandards']);
		self::assertSame([], $linked[0]['standards']);
	}//end testAnUnknownRoleLinksNoStandard()

	/**
	 * A component whose standards have no versions gets no linked versions.
	 *
	 * @return void
	 */
	public function testNoVersionsMeansNoLinkedVersions(): void {
		$linked = $this->link(relationships: [['source' => 'id-rc', 'target' => 'id-s1', 'verbindingsrol' => 'Verplicht']]);

		self::assertSame([self::STANDARD], $linked[0]['mandatoryStandards']);
		self::assertArrayNotHasKey('linkedStandardVersions', $linked[0]);
	}//end testNoVersionsMeansNoLinkedVersions()

	/**
	 * Relationship ends are read from the XML when they are not flattened.
	 *
	 * @return void
	 */
	public function testEndsAreReadFromTheXml(): void {
		$linked = $this->link(
			relationships: [
				['xml' => ['source' => ['_attributes' => ['href' => 'id-rc']], 'target' => ['_value' => 'id-s1']], 'verbindingsrol' => 'Verplicht'],
				['xml' => ['_attributes' => ['source' => 'id-s2', 'target' => 'id-rc']], 'verbindingsrol' => 'Aanbevolen'],
			]
		);

		self::assertSame([self::STANDARD], $linked[0]['mandatoryStandards']);
		self::assertSame([self::OTHER], $linked[0]['recommendedStandards']);
	}//end testEndsAreReadFromTheXml()

	/**
	 * A relationship with a missing or empty end links nothing.
	 *
	 * @return void
	 */
	public function testAMissingEndLinksNothing(): void {
		$linked = $this->link(
			relationships: [
				['target' => 'id-s1', 'verbindingsrol' => 'Verplicht'],
				['source' => '', 'target' => 'id-s1', 'verbindingsrol' => 'Verplicht'],
			]
		);

		self::assertArrayNotHasKey('standards', $linked[0]);
	}//end testAMissingEndLinksNothing()

	/**
	 * The GEMMA type is found under another property name, as an XML value, or through the property mapping.
	 *
	 * @return void
	 */
	public function testTheGemmaTypeIsFoundInOtherShapes(): void {
		$objects = [
			['section' => 'element', 'identifier' => 'id-rc', '@self' => ['id' => self::COMPONENT], 'GEMMA_Type' => ['_value' => 'Referentiecomponent']],
			['section' => 'element', 'identifier' => 'id-s1', '@self' => ['id' => self::STANDARD], 'soort' => 'Standaard', '_propertyMapping' => ['soort' => 'GEMMA type']],
			['section' => 'relationship', 'source' => 'id-rc', 'target' => 'id-s1', 'verbindingsrol' => 'Verplicht'],
		];

		$linked = (new GemmaStandardLinker(new NullLogger()))->link($objects);

		self::assertSame([self::STANDARD], $linked[0]['mandatoryStandards']);
	}//end testTheGemmaTypeIsFoundInOtherShapes()

	/**
	 * An element without an identifier is ignored.
	 *
	 * @return void
	 */
	public function testAnElementWithoutIdentifierIsIgnored(): void {
		$objects = [
			['section' => 'element', '@self' => ['id' => self::STANDARD], 'gemmaType' => 'Standaard'],
			['section' => 'element', 'identifier' => 'id-v', '@self' => ['id' => 'v'], 'gemmaType' => 'Standaardversie'],
			['section' => 'relationship', 'source' => 'id-v', 'target' => '', 'type' => 'Specialization'],
		];

		set_error_handler(static function (int $level, string $message): never {
			throw new \ErrorException($message, 0, $level);
		});
		try {
			$linked = (new GemmaStandardLinker(new NullLogger()))->link($objects);
		} finally {
			restore_error_handler();
		}

		self::assertSame($objects, $linked);
	}//end testAnElementWithoutIdentifierIsIgnored()

	/**
	 * An object without a uuid id is referenced by its identifier, without the "id-" prefix when it has one.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutUuidIsReferencedBySlug(): void {
		$objects = [
			['section' => 'element', 'identifier' => 'id-rc', '@self' => ['id' => 'id-rc'], 'gemmaType' => 'Referentiecomponent'],
			['section' => 'element', 'identifier' => 'id-s1', '@self' => ['id' => 'id-s1'], 'gemmaType' => 'Standaard'],
			['section' => 'element', 'identifier' => 'std-2', '@self' => ['id' => 'std-2'], 'gemmaType' => 'Standaard'],
			['section' => 'relationship', 'source' => 'id-rc', 'target' => 'id-s1', 'verbindingsrol' => 'Verplicht'],
			['section' => 'relationship', 'source' => 'id-rc', 'target' => 'std-2', 'verbindingsrol' => 'Verplicht'],
		];

		$linked = (new GemmaStandardLinker(new NullLogger()))->link($objects);

		self::assertSame(['s1', 'std-2'], $linked[0]['mandatoryStandards']);
	}//end testAnObjectWithoutUuidIsReferencedBySlug()

	/**
	 * The property mapping counts only a non-empty property whose original name says GEMMA type.
	 *
	 * @return void
	 */
	public function testTheMappingNeedsAGemmaTypeName(): void {
		$objects = [
			['section' => 'element', 'identifier' => 'id-rc', '@self' => ['id' => self::COMPONENT], 'gemmaType' => 'Referentiecomponent'],
			['section' => 'element', 'identifier' => 'id-s1', '@self' => ['id' => self::STANDARD], 'soort' => 'Standaard', '_propertyMapping' => ['soort' => 'Object type']],
			[
				'section'          => 'element',
				'identifier'       => 'id-s2',
				'@self'            => ['id' => self::OTHER],
				'leeg'             => '',
				'soort'            => 'Standaard',
				'_propertyMapping' => ['leeg' => 'GEMMA type', 'soort' => 'GEMMA type (oud)'],
			],
			['section' => 'relationship', 'source' => 'id-rc', 'target' => 'id-s1', 'verbindingsrol' => 'Verplicht'],
			['section' => 'relationship', 'source' => 'id-rc', 'target' => 'id-s2', 'verbindingsrol' => 'Verplicht'],
		];

		$linked = (new GemmaStandardLinker(new NullLogger()))->link($objects);

		self::assertSame([self::OTHER], $linked[0]['mandatoryStandards']);
	}//end testTheMappingNeedsAGemmaTypeName()

	/**
	 * The relationship type is also read from the XML type attributes.
	 *
	 * @return void
	 */
	public function testTheRelationshipTypeIsReadFromXsiAttributes(): void {
		$objects = [
			['section' => 'element', 'identifier' => 'id-s1', '@self' => ['id' => self::STANDARD], 'gemmaType' => 'Standaard'],
			['section' => 'element', 'identifier' => 'id-v1', '@self' => ['id' => 'v1'], 'gemmaType' => 'Standaardversie'],
			['section' => 'element', 'identifier' => 'id-v2', '@self' => ['id' => 'v2'], 'gemmaType' => 'Standaardversie'],
			['section' => 'relationship', 'source' => 'id-v1', 'target' => 'id-s1', '_xsi__type' => 'Specialization'],
			['section' => 'relationship', 'source' => 'id-v2', 'target' => 'id-s1', '_attributes' => ['xsi:type' => 'Specialization']],
		];

		$linked = (new GemmaStandardLinker(new NullLogger()))->link($objects);

		self::assertSame(self::STANDARD, $linked[1]['standard']);
		self::assertSame(self::STANDARD, $linked[2]['standard']);
	}//end testTheRelationshipTypeIsReadFromXsiAttributes()

	/**
	 * An empty relationship end never matches an element with an empty identifier.
	 *
	 * @return void
	 */
	public function testAnEmptyEndMatchesNoElement(): void {
		$objects = [
			['section' => 'element', 'identifier' => '', '@self' => ['id' => self::COMPONENT], 'gemmaType' => 'Referentiecomponent'],
			['section' => 'element', 'identifier' => 'id-s1', '@self' => ['id' => self::STANDARD], 'gemmaType' => 'Standaard'],
			['section' => 'relationship', 'source' => '', 'target' => 'id-s1', 'verbindingsrol' => 'Verplicht'],
		];

		$linked = (new GemmaStandardLinker(new NullLogger()))->link($objects);

		self::assertArrayNotHasKey('standards', $linked[0]);
	}//end testAnEmptyEndMatchesNoElement()

	/**
	 * Link one component and two standards through the given relationships.
	 *
	 * @param array<int, array<string, mixed>> $relationships The relationships.
	 *
	 * @return array<int, array<string, mixed>> The linked objects.
	 */
	private function link(array $relationships): array {
		$objects = [
			['section' => 'element', 'identifier' => 'id-rc', '@self' => ['id' => self::COMPONENT], 'gemmaType' => 'Referentiecomponent'],
			['section' => 'element', 'identifier' => 'id-s1', '@self' => ['id' => self::STANDARD], 'gemmaType' => 'Standaard'],
			['section' => 'element', 'identifier' => 'id-s2', '@self' => ['id' => self::OTHER], 'gemmaType' => 'Standaard'],
		];
		foreach ($relationships as $relationship) {
			$objects[] = ['section' => 'relationship'] + $relationship;
		}

		return (new GemmaStandardLinker(new NullLogger()))->link($objects);
	}//end link()
}//end class
