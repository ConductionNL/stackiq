<?php

/**
 * The ArchiMate export of the objects an import stored, and the organisation-enriched export.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ArchiMateExportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SimpleXMLElement;

/**
 * Feeds the objects the golden import of tests/data/archimate_golden.xml saved
 * (pinned in golden/archiMateImport.json) back through the export, and checks
 * the model that comes out against the source document.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateExportService
 * @uses \OCA\Stackiq\Service\ArchiMate\XmlAttribute
 * @uses \OCA\Stackiq\Service\ArchiMate\XmlAttributeRun
 */
final class ArchiMateExportRoundTripTest extends TestCase {

	private const ARCHIMATE_NS = 'http://www.opengroup.org/xsd/archimate/3.0/';

	private const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';

	/**
	 * The schema id => schema type map the import used.
	 *
	 * @var array<int, string>
	 */
	private array $schemaIdMap = [];

	/**
	 * The stored objects per schema id.
	 *
	 * @var array<int, list<array<string, mixed>>>
	 */
	private array $stored = [];

	/**
	 * The queries the export sent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The warnings logged, by message.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Load the objects the golden import saved, grouped by the schema they were saved under.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$golden = json_decode((string) file_get_contents(__DIR__.'/golden/archiMateImport.json'), true);
		foreach ($golden['optimized archimate_golden.xml []']['calls'] as $call) {
			if ($call[0] !== 'objects.saveObjects') {
				continue;
			}

			foreach ($call[1]['objects'] as $object) {
				$this->stored[$object['@self']['schema']][] = $object;
				$this->schemaIdMap[$object['@self']['schema']] = $object['section'];
			}
		}
	}//end setUp()

	/**
	 * An export service whose logger records the warnings.
	 *
	 * @return ArchiMateExportService
	 */
	private function service(): ArchiMateExportService {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->warnings[] = (string) $message;
			}
		);

		return new ArchiMateExportService(logger: $logger);
	}//end service()

	/**
	 * An object service answering the stored objects of the queried schema, or throwing for the failing ones.
	 *
	 * @param list<int> $failing Schema ids whose query throws.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(array $failing = []): ObjectServiceInterface {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query) use ($failing): array {
				$this->queries[] = $query;
				$schema = $query['@self']['schema'] ?? null;
				if (in_array(needle: $schema, haystack: $failing, strict: true) === true) {
					throw new RuntimeException('table missing');
				}

				return $this->stored[$schema] ?? [];
			}
		);

		return $objects;
	}//end objects()

	/**
	 * The identifiers of one tag in a document, in order.
	 *
	 * @param string $xml The document.
	 * @param string $tag The local tag name.
	 *
	 * @return list<string>
	 */
	private static function identifiers(string $xml, string $tag): array {
		$doc = new SimpleXMLElement($xml);
		$doc->registerXPathNamespace('a', self::ARCHIMATE_NS);

		return array_map(static fn (SimpleXMLElement $node): string => (string) $node['identifier'], $doc->xpath('//a:'.$tag.'[@identifier]'));
	}//end identifiers()

	/**
	 * Every element, relationship, view, node, connection and property definition of the source comes back.
	 *
	 * @return void
	 */
	public function testTheExportGivesBackEveryIdentifierOfTheSource(): void {
		$xml    = $this->service()->exportArchiMateXml(objectService: $this->objects(), registerId: 7, schemaIdMap: $this->schemaIdMap);
		$source = (string) file_get_contents(__DIR__.'/../../data/archimate_golden.xml');

		foreach (['element', 'relationship', 'view', 'node', 'connection', 'propertyDefinition'] as $tag) {
			$this->assertSame(expected: self::identifiers(xml: $source, tag: $tag), actual: self::identifiers(xml: $xml, tag: $tag), message: $tag);
		}

		$this->assertSame(expected: [], actual: $this->warnings);
	}//end testTheExportGivesBackEveryIdentifierOfTheSource()

	/**
	 * Each schema is queried on its own, unfiltered by RBAC, within the register.
	 *
	 * @return void
	 */
	public function testEachSchemaIsQueriedOnItsOwn(): void {
		$this->service()->exportArchiMateXml(objectService: $this->objects(), registerId: 7, schemaIdMap: $this->schemaIdMap);

		$this->assertSame(
			expected: array_map(static fn (int $schema): array => ['@self' => ['register' => 7, 'schema' => $schema], '_limit' => 10000], array_keys($this->schemaIdMap)),
			actual: $this->queries
		);
	}//end testEachSchemaIsQueriedOnItsOwn()

	/**
	 * The model carries its name, documentation and properties, and elements their type, names and resolved properties.
	 *
	 * @return void
	 */
	public function testTheModelAndItsElementsKeepTheirContent(): void {
		$doc = new SimpleXMLElement($this->service()->exportArchiMateXml(objectService: $this->objects(), registerId: 7, schemaIdMap: $this->schemaIdMap));

		$this->assertSame(expected: 'model', actual: $doc->getName());
		$this->assertSame(expected: 'id-model-golden', actual: (string) $doc['identifier']);
		$this->assertSame(expected: 'Golden model', actual: (string) $doc->name);
		$this->assertSame(expected: 'Model used to pin the import output', actual: (string) $doc->documentation);
		$this->assertSame(expected: 'Model unit', actual: (string) $doc->properties->property->value);

		$element = $doc->elements->element[0];
		$this->assertSame(expected: 'ApplicationComponent', actual: (string) $element->attributes(self::XSI_NS)['type']);
		$this->assertSame(expected: 'Zaaksysteem', actual: (string) $element->name);
		$this->assertSame(expected: 'nl', actual: (string) $element->name->attributes('xml', true)['lang']);
		$this->assertSame(expected: 'Handles cases', actual: (string) $element->documentation);
		$this->assertSame(expected: 'uuid-el-1', actual: (string) $element->properties->property[0]->value);
		$this->assertSame(expected: 'propid-1', actual: (string) $element->properties->property[0]['propertyDefinitionRef']);

		$relationship = $doc->relationships->relationship[0];
		$this->assertSame(
			expected: ['Serving', 'id-el-1', 'id-el-2', 'serves'],
			actual: [(string) $relationship->attributes(self::XSI_NS)['type'], (string) $relationship['source'], (string) $relationship['target'], (string) $relationship->name]
		);
		$this->assertSame(expected: 'Object ID', actual: (string) $doc->propertyDefinitions->propertyDefinition[0]->name);
	}//end testTheModelAndItsElementsKeepTheirContent()

	/**
	 * The organisation tree is written as nested items, and views keep their nodes, styles and connections.
	 *
	 * @return void
	 */
	public function testTheOrganisationTreeAndViewsAreRebuilt(): void {
		$doc = new SimpleXMLElement($this->service()->exportArchiMateXml(objectService: $this->objects(), registerId: 7, schemaIdMap: $this->schemaIdMap));

		$business = $doc->organizations->item[0];
		$this->assertSame(expected: 'Business', actual: (string) $business->label);
		$this->assertSame(expected: 'id-el-1', actual: (string) $business->item[0]['identifierRef']);
		$this->assertSame(expected: 'Nested', actual: (string) $business->item[1]->label);
		$this->assertSame(expected: 'id-el-2', actual: (string) $business->item[1]->item['identifierRef']);

		$view = $doc->views->diagrams->view[0];
		$this->assertSame(expected: ['Overview', 'Layered'], actual: [(string) $view->name, (string) $view['viewpoint']]);
		$node = $view->node[0];
		$this->assertSame(expected: ['10', '20', '120', '55', 'id-el-1'], actual: [(string) $node['x'], (string) $node['y'], (string) $node['w'], (string) $node['h'], (string) $node['elementRef']]);
		$this->assertSame(expected: '181', actual: (string) $node->style->fillColor['b']);
		$this->assertSame(expected: 'id-node-2', actual: (string) $node->node['identifier']);
		$this->assertSame(expected: 'A note', actual: (string) $view->node[1]->label);
		$this->assertSame(expected: ['id-rel-1', '50'], actual: [(string) $view->connection['relationshipRef'], (string) $view->connection->bendpoint['x']]);
	}//end testTheOrganisationTreeAndViewsAreRebuilt()

	/**
	 * A schema whose query fails is logged and left out; the others still export.
	 *
	 * @return void
	 */
	public function testAFailingSchemaIsLeftOut(): void {
		$relationshipSchema = (int) array_search(needle: 'relationship', haystack: $this->schemaIdMap, strict: true);
		$xml = $this->service()->exportArchiMateXml(objectService: $this->objects(failing: [$relationshipSchema]), registerId: 7, schemaIdMap: $this->schemaIdMap);

		$this->assertSame(expected: [], actual: self::identifiers(xml: $xml, tag: 'relationship'));
		$this->assertSame(expected: ['id-el-1', 'id-el-2', 'el-3', 'id-el-4', 'id-el-5'], actual: self::identifiers(xml: $xml, tag: 'element'));
		$this->assertContains(needle: 'Failed to retrieve objects for schema', haystack: $this->warnings);
	}//end testAFailingSchemaIsLeftOut()

	/**
	 * Without a schema map the register is read whole; a failure there aborts the export.
	 *
	 * @return void
	 */
	public function testWithoutASchemaMapTheRegisterIsReadWhole(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->once())->method('searchObjects')
			->with(['@self' => ['register' => 7], '_limit' => 10000], false, false)
			->willReturn([['identifier' => 'whole-register', 'section' => 'element']]);
		$this->assertSame(
			expected: [['identifier' => 'whole-register', 'section' => 'element']],
			actual: $this->service()->getObjectsFromDatabase(objectService: $objects, registerId: 7)
		);

		$failing = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$failing->method('searchObjects')->willThrowException(new RuntimeException('register gone'));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to retrieve objects from database: register gone');
		$this->service()->getObjectsFromDatabase(objectService: $failing, registerId: 7);
	}//end testWithoutASchemaMapTheRegisterIsReadWhole()

	/**
	 * The organisation export adds an application per used module and per participation, specialising the referenced components.
	 *
	 * @return void
	 */
	public function testTheOrganisationExportAddsItsApplications(): void {
		$doc = new SimpleXMLElement(
			$this->service()->exportOrganizationArchiMateXml(
				objectService: $this->objects(),
				registerId: 7,
				schemaIdMap: $this->schemaIdMap,
				orgName: 'Gemeente Test',
				orgUuid: 'org-uuid',
				gebruikData: [
					['module' => 'm-1', 'usedForReferenceComponents' => ['el-1', ['id' => 'el-2']]],
					['module' => 'm-2', 'moduleName' => 'Losse module', 'usedForReferenceComponents' => [['uuid' => 'el-4']]],
					['module' => null],
				],
				modulesData: [['id' => 'm-1', 'name' => 'Zaak Pro']],
				deelnamesData: [['module' => 'm-3', 'usedForReferenceComponents' => ['el-1']]],
				options: ['modules' => true, 'deelnames' => true]
			)
		);
		$xml = (string) $doc->asXML();

		$this->assertSame(
			expected: ['id-el-1', 'id-el-2', 'el-3', 'id-el-4', 'id-el-5', 'id-swc-app-m-1', 'id-swc-app-m-2', 'deelname-app-m-3'],
			actual: self::identifiers(xml: $xml, tag: 'element')
		);
		$app = $doc->elements->element[5];
		$this->assertSame(expected: ['ApplicationComponent', 'Zaak Pro'], actual: [(string) $app->attributes(self::XSI_NS)['type'], (string) $app->name]);
		$this->assertSame(expected: 'Losse module', actual: (string) $doc->elements->element[6]->name);

		$this->assertSame(
			expected: ['id-rel-1', 'rel-2', 'id-swc-rel-m-1-el-1', 'id-swc-rel-m-1-el-2', 'id-swc-rel-m-2-el-4', 'deelname-rel-m-3-el-1'],
			actual: self::identifiers(xml: $xml, tag: 'relationship')
		);
		$specialisation = $doc->relationships->relationship[2];
		$this->assertSame(
			expected: ['id-swc-app-m-1', 'id-el-1'],
			actual: [(string) $specialisation['source'], (string) $specialisation['target']]
		);
		$this->assertContains(needle: 'id-swc-propdef-bron', haystack: self::identifiers(xml: $xml, tag: 'propertyDefinition'));
	}//end testTheOrganisationExportAddsItsApplications()

	/**
	 * The organisation gets its own folder of applications, participations, relations and view copies, named after it.
	 *
	 * @return void
	 */
	public function testTheOrganisationGetsItsOwnFolder(): void {
		$doc = new SimpleXMLElement(
			$this->service()->exportOrganizationArchiMateXml(
				objectService: $this->objects(),
				registerId: 7,
				schemaIdMap: $this->schemaIdMap,
				orgName: 'Gemeente Test',
				orgUuid: 'org-uuid',
				gebruikData: [['module' => 'm-1', 'usedForReferenceComponents' => ['el-1']]],
				modulesData: [],
				deelnamesData: [['module' => 'm-3', 'usedForReferenceComponents' => ['el-2']]],
				options: ['deelnames' => true]
			)
		);

		$folder = $doc->organizations->item[2];
		$this->assertSame(expected: 'Gemeente Test', actual: (string) $folder->label);
		$sections = [];
		foreach ($folder->item as $section) {
			$sections[(string) $section->label] = array_map(static fn (SimpleXMLElement $item): string => (string) $item['identifierRef'], iterator_to_array($section->item, false));
		}

		$this->assertSame(
			expected: [
				'Gebruikt (Stackiq)'  => ['id-swc-app-m-1'],
				'Deelnames (Stackiq)' => ['deelname-app-m-3'],
				'Relaties (Stackiq)'  => ['id-swc-rel-m-1-el-1', 'deelname-rel-m-3-el-2'],
				'Views (Stackiq)'     => ['id-swc-view-view-1', 'id-swc-view-view-2'],
			],
			actual: $sections
		);

		$copy = $doc->views->diagrams->view[2];
		$this->assertSame(expected: ['id-swc-view-view-1', 'Overview Gemeente Test'], actual: [(string) $copy['identifier'], (string) $copy->name]);
		$this->assertSame(expected: 'id-swc-propdef-bron', actual: (string) $copy->properties->property[1]['propertyDefinitionRef']);
		$this->assertSame(expected: 'Stackiq', actual: (string) $copy->properties->property[1]->value);
	}//end testTheOrganisationGetsItsOwnFolder()

	/**
	 * Without the module option and without participations the export adds no applications.
	 *
	 * @return void
	 */
	public function testNoOptionsAddNoApplications(): void {
		$xml = $this->service()->exportOrganizationArchiMateXml(
			objectService: $this->objects(),
			registerId: 7,
			schemaIdMap: $this->schemaIdMap,
			orgName: 'Gemeente Test',
			orgUuid: 'org-uuid',
			gebruikData: [['module' => 'm-1', 'usedForReferenceComponents' => ['el-1']]],
			modulesData: [],
			deelnamesData: [],
			options: ['modules' => false, 'deelnames' => true]
		);

		$this->assertSame(expected: ['id-el-1', 'id-el-2', 'el-3', 'id-el-4', 'id-el-5'], actual: self::identifiers(xml: $xml, tag: 'element'));
		$this->assertSame(expected: ['id-rel-1', 'rel-2'], actual: self::identifiers(xml: $xml, tag: 'relationship'));
	}//end testNoOptionsAddNoApplications()
}//end class
