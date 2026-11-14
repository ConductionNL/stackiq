<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ArchiMate\XmlAttributeRun;
use OCA\Stackiq\Service\ArchiMateExportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the AMEF export's folder-building and OpenRegister→ArchiMate conversion
 * helpers: the folder nodes the typed add*ToXml wrappers attach, how objects and
 * views land under them for each input format, and how flat organised objects
 * are mapped back to the nested ArchiMate sections.
 *
 * @spec openspec/specs/archimate-export/spec.md
 */
#[CoversClass(ArchiMateExportService::class)]
#[UsesClass(XmlAttributeRun::class)]
class ArchiMateExportConvertAndFoldersTest extends TestCase {
	/**
	 * Build the service without its constructor and give it a silent logger,
	 * so the methods that log (view and conversion helpers) can run.
	 *
	 * @return ArchiMateExportService
	 */
	private function makeService(): ArchiMateExportService {
		$reflection = new ReflectionClass(ArchiMateExportService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		$logger     = $reflection->getProperty('logger');
		$logger->setAccessible(true);
		$logger->setValue($service, new NullLogger());
		return $service;
	}

	/**
	 * Invoke a private/protected method by name.
	 *
	 * @param ArchiMateExportService $service The service.
	 * @param string                 $method  The method name.
	 * @param array                  $args    Positional arguments.
	 *
	 * @return mixed
	 */
	private function call(ArchiMateExportService $service, string $method, array $args) {
		$ref = new ReflectionMethod($service, $method);
		$ref->setAccessible(true);
		return $ref->invokeArgs($service, $args);
	}

	public function testAddElementsToXmlBuildsTheApplicationFolderWithOneElementPerObject(): void {
		$service = $this->makeService();
		$xml     = new \SimpleXMLElement('<model/>');

		$service->addElementsToXml($xml, [
			['xml_data' => ['identifier' => 'e1']],
			['xml_data' => ['identifier' => 'e2']],
		]);

		$this->assertCount(1, $xml->folder);
		$folder = $xml->folder[0];
		$this->assertSame('Application', (string)$folder['name']);
		$this->assertSame('folder-elements', (string)$folder['id']);
		$this->assertSame('application', (string)$folder['type']);
		$this->assertCount(2, $folder->element);
		$this->assertSame('e1', (string)$folder->element[0]->identifier);
	}

	public function testAddOrganizationsToXmlUsesItemChildrenAndBusinessFolder(): void {
		$service = $this->makeService();
		$xml     = new \SimpleXMLElement('<model/>');

		$service->addOrganizationsToXml($xml, [['xml_data' => ['identifier' => 'o1']]]);

		$folder = $xml->folder[0];
		$this->assertSame('Organizations', (string)$folder['name']);
		$this->assertSame('business', (string)$folder['type']);
		$this->assertCount(1, $folder->item);
		$this->assertSame('o1', (string)$folder->item[0]->identifier);
	}

	public function testAddObjectsToXmlAddsNothingForAnEmptyList(): void {
		$service = $this->makeService();
		$xml     = new \SimpleXMLElement('<model/>');

		$service->addRelationshipsToXml($xml, []);

		$this->assertCount(0, $xml->folder);
	}

	public function testAddViewsToXmlAttachesAViewPerEntryWithItsNodes(): void {
		$service = $this->makeService();
		$xml     = new \SimpleXMLElement('<model/>');

		$service->addViewsToXml($xml, [
			['xml_data' => ['name' => 'Overview']],
		]);

		$folder = $xml->folder[0];
		$this->assertSame('Views', (string)$folder['name']);
		$this->assertSame('diagrams', (string)$folder['type']);
		$this->assertCount(1, $folder->view);
		$this->assertSame('Overview', (string)$folder->view[0]->name);
	}

	public function testAddViewsToXmlDoesNothingWhenThereAreNoViews(): void {
		$service = $this->makeService();
		$xml     = new \SimpleXMLElement('<model/>');

		$service->addViewsToXml($xml, []);

		$this->assertCount(0, $xml->folder);
	}

	public function testExtractViewDataReadsEachOfTheThreeFormats(): void {
		$service = $this->makeService();

		$fromProperties = $this->call($service, 'extractViewData', [['properties' => ['xml_data' => json_encode(['a' => 1])]]]);
		$this->assertSame(['a' => 1], $fromProperties);

		$fromXmlData = $this->call($service, 'extractViewData', [['xml_data' => ['b' => 2]]]);
		$this->assertSame(['b' => 2], $fromXmlData);

		$direct = $this->call($service, 'extractViewData', [['name' => 'direct']]);
		$this->assertSame(['name' => 'direct'], $direct);

		$invalid = $this->call($service, 'extractViewData', [['xml_data' => '5']]);
		$this->assertNull($invalid);
	}

	public function testOrganizeObjectsBySchemaTypeGroupsByMappedSchemaAndDropsUnknown(): void {
		$service = $this->makeService();

		$organized = $this->call($service, 'organizeObjectsBySchemaType', [
			[
				['@self' => ['schema' => 'sel'], 'identifier' => 'e1'],
				['@self' => ['schema' => 'srel'], 'identifier' => 'r1'],
				['@self' => ['schema' => 'sel'], 'identifier' => 'e2'],
				['@self' => ['schema' => 'unmapped'], 'identifier' => 'x'],
			],
			['sel' => 'element', 'srel' => 'relationship'],
		]);

		$this->assertSame(['element', 'relationship'], array_keys($organized));
		$this->assertCount(2, $organized['element']);
		$this->assertArrayNotHasKey('unmapped', $organized);
	}

	public function testMapSchemaTypeToSectionTranslatesKnownTypesAndPassesOthersThrough(): void {
		$service = $this->makeService();

		$this->assertSame('model_metadata', $this->call($service, 'mapSchemaTypeToSection', ['model']));
		$this->assertSame('elements', $this->call($service, 'mapSchemaTypeToSection', ['element']));
		$this->assertSame('property_definitions', $this->call($service, 'mapSchemaTypeToSection', ['property_definition']));
		$this->assertSame('custom', $this->call($service, 'mapSchemaTypeToSection', ['custom']));
	}

	public function testReconstructNestedXmlStructureWrapsViewsAndOrganizations(): void {
		$service = $this->makeService();

		$result = $this->call($service, 'reconstructNestedXmlStructure', [[
			'views' => ['v1' => ['name' => 'A']],
			'organizations' => ['o1' => ['name' => 'B'], 'o2' => ['name' => 'C']],
			'elements' => ['e1' => ['name' => 'D']],
		]]);

		$this->assertSame(['diagrams' => ['v1' => ['name' => 'A']]], $result['views']);
		$this->assertSame(['item' => [['name' => 'B'], ['name' => 'C']]], $result['organizations']);
		$this->assertSame(['e1' => ['name' => 'D']], $result['elements']);
	}

	public function testConvertFromOpenRegisterObjectsRebuildsEverySection(): void {
		$service = $this->makeService();

		$result = $service->convertFromOpenRegisterObjects(
			[
				['@self' => ['schema' => 'sm'], 'xml_data' => json_encode(['name' => 'My Model']), 'identifier' => 'm'],
				['@self' => ['schema' => 'se'], 'xml_data' => json_encode(['name' => 'Elem']), 'identifier' => 'e1'],
				['@self' => ['schema' => 'sv'], 'xml_data' => json_encode(['name' => 'View']), 'identifier' => 'view1'],
			],
			['sm' => 'model', 'se' => 'element', 'sv' => 'view'],
		);

		$this->assertSame(['name' => 'My Model'], $result['model_metadata']);
		$this->assertSame(['e1' => ['name' => 'Elem']], $result['elements']);
		$this->assertSame(['diagrams' => ['view1' => ['name' => 'View']]], $result['views']);
	}

	public function testCleanObjectDataForXmlStripsMetadataUnderscoreDuplicatesAndFlattenedProperties(): void {
		$service = $this->makeService();

		$clean = $this->call($service, 'cleanObjectDataForXml', [
			[
				'@self'          => ['id' => 1],
				'section'        => 'elements',
				'identifier'     => 'e1',
				'name'           => 'Keep',
				'_name'          => 'dup',
				'_attributes'    => ['x' => 1],
				'gemmaType'      => 'should-go',
			],
			['propRef' => 'gemmaType'],
		]);

		$this->assertArrayNotHasKey('@self', $clean);
		$this->assertArrayNotHasKey('section', $clean);
		$this->assertArrayNotHasKey('identifier', $clean);
		// A single-underscore field is dropped when its clean twin exists.
		$this->assertArrayNotHasKey('_name', $clean);
		// A flattened property named in the propDefMap is removed.
		$this->assertArrayNotHasKey('gemmaType', $clean);
		$this->assertSame('Keep', $clean['name']);
		// The reserved _attributes bag is preserved.
		$this->assertSame(['x' => 1], $clean['_attributes']);
	}
}
