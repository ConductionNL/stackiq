<?php

/**
 * The organisation ArchiMate export writes the organisation's connections as flow relationships.
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
 * Runs the organisation export over the objects the golden import saved (as
 * ArchiMateExportRoundTripTest does) with two applications and their connections.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateExportService
 * @covers \OCA\Stackiq\Service\ArchiMate\ConnectionFlows
 * @uses \OCA\Stackiq\Service\ArchiMate\XmlAttribute
 * @uses \OCA\Stackiq\Service\ArchiMate\XmlAttributeRun
 * @uses \OCA\Stackiq\Service\ViewOrigin
 *
 * @spec openspec/changes/archive/2026-11-25-connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-004-the-organisation-archimate-export-can-carry-the-organisations-connections
 */
final class ArchiMateExportConnectionsTest extends TestCase {

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
	 * The export of two applications with the given connections and options.
	 *
	 * @param ArchiMateExportService $service The export service.
	 * @param array<int, array<string, mixed>> $connections The connections.
	 * @param bool $withConnections Whether the connections option is on.
	 *
	 * @return SimpleXMLElement The exported document.
	 */
	private function export(ArchiMateExportService $service, array $connections, bool $withConnections = true): SimpleXMLElement {
		return new SimpleXMLElement(
			$service->exportOrganizationArchiMateXml(
				objectService: $this->objects(),
				registerId: 7,
				schemaIdMap: $this->schemaIdMap,
				orgName: 'Gemeente Test',
				orgUuid: 'org-uuid',
				gebruikData: [
					['module' => 'm-1', 'usedForReferenceComponents' => ['el-1']],
					['module' => 'm-2', 'usedForReferenceComponents' => ['el-2']],
				],
				modulesData: [['id' => 'm-1', 'name' => 'Zaak Pro'], ['id' => 'm-2', 'name' => 'Archief Plus']],
				deelnamesData: [],
				options: ['modules' => true, 'connections' => $withConnections],
				connectionsData: $connections
			)
		);
	}//end export()

	/**
	 * The flow relationships of a document: identifier, source, target and its properties by definition.
	 *
	 * @param SimpleXMLElement $doc The document.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function flows(SimpleXMLElement $doc): array {
		$flows = [];
		foreach ($doc->relationships->relationship as $relationship) {
			if ((string) $relationship->attributes(self::XSI_NS)['type'] !== 'FlowRelationship') {
				continue;
			}

			$properties = [];
			foreach ($relationship->properties->property as $property) {
				$properties[(string) $property['propertyDefinitionRef']] = (string) $property->value;
			}

			$flows[] = ['source' => (string) $relationship['source'], 'target' => (string) $relationship['target'], 'properties' => $properties];
		}

		return $flows;
	}//end flows()

	/**
	 * Two connections between the exported applications are two flows with transport properties.
	 *
	 * @return void
	 */
	public function testTwoConnectionsBecomeTwoFlowsWithTheirProperties(): void {
		$service = $this->service();
		$doc = $this->export(
			service: $service,
			connections: [
				['id' => 'c-1', 'moduleA' => ['id' => 'm-1'], 'moduleB' => 'm-2', 'type' => 'api', 'dataExchangeDirection' => 'AtoB', 'status' => 'in use'],
				['id' => 'c-2', 'moduleA' => 'm-1', 'moduleB' => 'm-2', 'type' => 'file transfer', 'dataExchangeDirection' => 'BtoA', 'status' => 'withdrawn'],
			]
		);

		$this->assertSame(
			expected: [
				['source' => 'id-swc-app-m-1', 'target' => 'id-swc-app-m-2', 'properties' => [
					'id-swc-propdef-bron' => 'Stackiq',
					'id-swc-propdef-connection-type' => 'api',
					'id-swc-propdef-connection-direction' => 'AtoB',
					'id-swc-propdef-connection-status' => 'in use',
				]],
				['source' => 'id-swc-app-m-2', 'target' => 'id-swc-app-m-1', 'properties' => [
					'id-swc-propdef-bron' => 'Stackiq',
					'id-swc-propdef-connection-type' => 'file transfer',
					'id-swc-propdef-connection-direction' => 'BtoA',
					'id-swc-propdef-connection-status' => 'withdrawn',
				]],
			],
			actual: self::flows(doc: $doc)
		);
		$definitions = [];
		foreach ($doc->propertyDefinitions->propertyDefinition as $definition) {
			$definitions[(string) $definition['identifier']] = (string) $definition->name;
		}

		$this->assertSame(expected: 'Transporttype', actual: $definitions['id-swc-propdef-connection-type'] ?? null);
		$this->assertSame(expected: 0, actual: $service->lastSkippedConnections());
	}//end testTwoConnectionsBecomeTwoFlowsWithTheirProperties()

	/**
	 * A bi-directional connection is two flows; a national provision is a flow to its element.
	 *
	 * @return void
	 */
	public function testABiDirectionalConnectionAndANationalProvision(): void {
		$doc = $this->export(
			service: $this->service(),
			connections: [
				['id' => 'c-3', 'moduleA' => 'm-1', 'moduleB' => 'm-2', 'type' => 'api', 'dataExchangeDirection' => 'bi-directional'],
				['id' => 'c-4', 'moduleA' => 'm-2', 'nonMunicipalProvision' => ['id' => 'uuid-el-1'], 'type' => 'digikoppeling', 'dataExchangeDirection' => 'AtoB'],
			]
		);

		$this->assertSame(
			expected: [['id-swc-app-m-1', 'id-swc-app-m-2'], ['id-swc-app-m-2', 'id-swc-app-m-1'], ['id-swc-app-m-2', 'id-el-1']],
			actual: array_map(static fn (array $flow): array => [$flow['source'], $flow['target']], self::flows(doc: $doc))
		);
	}//end testABiDirectionalConnectionAndANationalProvision()

	/**
	 * A connection whose other end is not in the export is skipped and counted.
	 *
	 * @return void
	 */
	public function testAConnectionWithAnEndOutsideTheExportIsSkippedAndCounted(): void {
		$service = $this->service();
		$doc = $this->export(
			service: $service,
			connections: [
				['id' => 'c-5', 'moduleA' => 'm-1', 'moduleB' => 'm-elsewhere', 'type' => 'api', 'dataExchangeDirection' => 'AtoB'],
				['id' => 'c-6', 'moduleA' => 'm-1', 'type' => 'api'],
				['id' => 'c-7', 'moduleA' => 'm-1', 'moduleB' => 'm-2', 'type' => 'api', 'dataExchangeDirection' => 'AtoB'],
			]
		);

		$this->assertCount(expectedCount: 1, haystack: self::flows(doc: $doc));
		$this->assertSame(expected: 2, actual: $service->lastSkippedConnections());
	}//end testAConnectionWithAnEndOutsideTheExportIsSkippedAndCounted()

	/**
	 * Without the connections option no flow is written and no flow property is defined.
	 *
	 * @return void
	 */
	public function testWithoutTheOptionNoFlowIsWritten(): void {
		$doc = $this->export(
			service: $this->service(),
			connections: [['id' => 'c-1', 'moduleA' => 'm-1', 'moduleB' => 'm-2', 'type' => 'api', 'dataExchangeDirection' => 'AtoB']],
			withConnections: false
		);

		$this->assertSame(expected: [], actual: self::flows(doc: $doc));
		$this->assertStringNotContainsString(needle: 'id-swc-propdef-connection-', haystack: (string) $doc->asXML());
	}//end testWithoutTheOptionNoFlowIsWritten()
}//end class
