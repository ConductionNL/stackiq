<?php

/**
 * Unit tests for GemmaObjectReader's reference, section, role and relationship readers.
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

use OCA\Stackiq\Service\ArchiMate\GemmaObjectReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers every reader of GemmaObjectReader except gemmaType(), which has its own golden test.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class GemmaObjectReaderTest extends TestCase {

	private GemmaObjectReader $reader;

	/**
	 * Set up the reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->reader = new GemmaObjectReader();
	}//end setUp()

	/**
	 * A uuid id is the reference.
	 *
	 * @return void
	 */
	public function testReferencePrefersUuidId(): void {
		$object = ['@self' => ['id' => '0A1B2C3D-0000-4000-8000-123456789ABC'], 'identifier' => 'id-abc'];

		$this->assertSame('0A1B2C3D-0000-4000-8000-123456789ABC', $this->reader->reference($object));
	}//end testReferencePrefersUuidId()

	/**
	 * Without a uuid id, the identifier with its id- prefix stripped is the reference.
	 *
	 * @return void
	 */
	public function testReferenceStripsIdPrefixFromIdentifier(): void {
		$object = ['@self' => ['id' => '42'], 'identifier' => 'id-9f8e'];

		$this->assertSame('9f8e', $this->reader->reference($object));
	}//end testReferenceStripsIdPrefixFromIdentifier()

	/**
	 * An identifier without the prefix is returned unchanged.
	 *
	 * @return void
	 */
	public function testReferenceKeepsPlainIdentifier(): void {
		$this->assertSame('slug-x', $this->reader->reference(['identifier' => 'slug-x']));
	}//end testReferenceKeepsPlainIdentifier()

	/**
	 * Only strings are role text.
	 *
	 * @return void
	 */
	public function testRoleText(): void {
		$this->assertSame('levert', $this->reader->roleText('levert'));
		$this->assertSame('', $this->reader->roleText(''));
		$this->assertNull($this->reader->roleText(['levert']));
		$this->assertNull($this->reader->roleText(null));
		$this->assertNull($this->reader->roleText(5));
	}//end testRoleText()

	/**
	 * The section is singularised by trimming trailing s characters.
	 *
	 * @return void
	 */
	public function testSection(): void {
		$this->assertSame('element', $this->reader->section(['section' => 'elements']));
		$this->assertSame('view', $this->reader->section(['section' => 'view']));
		$this->assertSame('', $this->reader->section([]));
	}//end testSection()

	/**
	 * The relationship type is read from type, then the flattened xsi type, then the XML attribute.
	 *
	 * @return void
	 */
	public function testRelationTypeLookupOrder(): void {
		$this->assertSame(
			'Serving',
			$this->reader->relationType(['type' => 'Serving', '_xsi__type' => 'Flow', '_attributes' => ['xsi:type' => 'Access']])
		);
		$this->assertSame('Flow', $this->reader->relationType(['_xsi__type' => 'Flow', '_attributes' => ['xsi:type' => 'Access']]));
		$this->assertSame('Access', $this->reader->relationType(['_attributes' => ['xsi:type' => 'Access']]));
		$this->assertNull($this->reader->relationType([]));
	}//end testRelationTypeLookupOrder()

	/**
	 * The endpoint resolves from the flattened property, then the XML, then the XML attributes.
	 *
	 * @param array<string, mixed> $relationship The relationship.
	 * @param string|null          $expected     The expected source.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testEndpoint(array $relationship, ?string $expected): void {
		$this->assertSame($expected, $this->reader->endpoint($relationship, 'source'));
	}//end testEndpoint()

	/**
	 * Relationships and the source they resolve to.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function endpoints(): array {
		return [
			'flattened'             => [['source' => 'id-a', 'xml' => ['source' => 'id-b']], 'id-a'],
			'empty flattened'       => [['source' => '', 'xml' => ['source' => 'id-b']], 'id-b'],
			'xml value'             => [['xml' => ['source' => ['_value' => 'id-c']]], 'id-c'],
			'href beats value'      => [['source' => ['_attributes' => ['href' => 'id-h'], '_value' => 'id-v']], 'id-h'],
			'xml attributes'        => [['xml' => ['_attributes' => ['source' => 'id-d']]], 'id-d'],
			'non-string skipped'    => [['source' => 5, 'xml' => ['_attributes' => ['source' => 'id-e']]], 'id-e'],
			'nothing'               => [['target' => 'id-t'], null],
			'array without a value' => [['source' => ['other' => 'x']], null],
		];
	}//end endpoints()

	/**
	 * The requested end is read, not the other one.
	 *
	 * @return void
	 */
	public function testEndpointReadsRequestedEnd(): void {
		$relationship = ['source' => 'id-s', 'target' => 'id-t'];

		$this->assertSame('id-t', $this->reader->endpoint($relationship, 'target'));
	}//end testEndpointReadsRequestedEnd()
}//end class
