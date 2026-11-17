<?php

/**
 * Shared applications in the organisation ArchiMate export.
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
use Psr\Log\NullLogger;

/**
 * The organisation export nests own and shared applications in the view
 * copies with their own colours, names the partner of a shared element, and
 * keeps the own-only export as it was.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateExportService
 * @uses \OCA\Stackiq\Service\ArchiMate\XmlAttribute
 * @uses \OCA\Stackiq\Service\ArchiMate\XmlAttributeRun
 * @uses \OCA\Stackiq\Service\ViewOrigin
 *
 * @spec openspec/changes/architecture-shared-usage-on-views
 */
class ArchiMateExportSharedUsageStyleTest extends TestCase {

	private const NS = 'http://www.opengroup.org/xsd/archimate/3.0/';

	/**
	 * The export service under test.
	 *
	 * @var ArchiMateExportService
	 */
	private ArchiMateExportService $service;

	/**
	 * Set up the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new ArchiMateExportService(new NullLogger());

	}//end setUp()

	/**
	 * An object service that answers the base model: one reference component
	 * and one view that draws it.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objects = [
			1 => [
				[
					'section' => 'element',
					'identifier' => 'id-rc1',
					'xml' => [
						'_identifier' => 'id-rc1',
						'_xsi__type' => 'ApplicationComponent',
						'name' => ['_value' => 'Zaaksysteem'],
					],
				],
			],
			2 => [
				[
					'section' => 'view',
					'identifier' => 'id-v1',
					'xml' => [
						'_identifier' => 'id-v1',
						'_xsi__type' => 'Diagram',
						'name' => ['_value' => 'Overzicht'],
						'node' => [
							[
								'_identifier' => 'id-n1',
								'_elementRef' => 'id-rc1',
								'_xsi__type' => 'Element',
								'_x' => '10',
								'_y' => '10',
								'_w' => '200',
								'_h' => '120',
							],
						],
					],
				],
			],
		];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			static fn (array $query): array => $objects[$query['@self']['schema']] ?? []
		);

		return $objectService;

	}//end objectService()

	/**
	 * Run the organisation export.
	 *
	 * @param array $gebruik   Own usages.
	 * @param array $deelnames Shared usages.
	 * @param bool  $withShare Whether the Deelnames option is ticked.
	 *
	 * @return \SimpleXMLElement The parsed export.
	 */
	private function export(array $gebruik, array $deelnames, bool $withShare): \SimpleXMLElement {
		$xml = $this->service->exportOrganizationArchiMateXml(
			objectService: $this->objectService(),
			registerId: 7,
			schemaIdMap: [1 => 'element', 2 => 'view'],
			orgName: 'Gemeente Tilburg',
			orgUuid: 'org-1',
			gebruikData: $gebruik,
			modulesData: [
				['id' => 'm-a', 'name' => 'Suite A'],
				['id' => 'm-b', 'name' => 'Suite B'],
			],
			deelnamesData: $deelnames,
			options: ['modules' => true, 'deelnames' => $withShare],
		);

		$parsed = simplexml_load_string($xml);
		$this->assertNotFalse($parsed, 'The export is well-formed XML.');
		$parsed->registerXPathNamespace('a', self::NS);
		$parsed->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');

		return $parsed;

	}//end export()

	/**
	 * An own usage of module $module on the reference component.
	 *
	 * @param string $module The module ID.
	 *
	 * @return array The gebruik object.
	 */
	private static function usage(string $module): array {
		return ['module' => $module, 'usedForReferenceComponents' => ['rc1']];

	}//end usage()

	/**
	 * A usage of module $module shared by $partner.
	 *
	 * @param string $module  The module ID.
	 * @param string $partner The partner's name.
	 *
	 * @return array The deelname gebruik object.
	 */
	private static function sharedUsage(string $module, string $partner): array {
		return [
			'module' => $module,
			'usedForReferenceComponents' => ['rc1'],
			'consumer' => ['id' => 'org-2', 'name' => $partner],
		];

	}//end sharedUsage()

	/**
	 * The fill and line colour of the nested node of an element, as "r,g,b".
	 *
	 * @param \SimpleXMLElement $xml       The export.
	 * @param string            $elementId The nested element's identifier.
	 *
	 * @return array{fill: string, line: string}
	 */
	private static function colours(\SimpleXMLElement $xml, string $elementId): array {
		$nodes = $xml->xpath("//a:view[@identifier='id-swc-view-v1']//a:node[@elementRef='$elementId']");
		self::assertCount(1, $nodes, "One nested node for $elementId.");
		$nodes[0]->registerXPathNamespace('a', self::NS);
		$fill = $nodes[0]->xpath('a:style/a:fillColor')[0];
		$line = $nodes[0]->xpath('a:style/a:lineColor')[0];

		return [
			'fill' => $fill['r'] . ',' . $fill['g'] . ',' . $fill['b'],
			'line' => $line['r'] . ',' . $line['g'] . ',' . $line['b'],
		];

	}//end colours()

	/**
	 * Own and shared applications are nested in the component with their own colours.
	 *
	 * @return void
	 */
	public function testSharedApplicationsGetTheirOwnColours(): void {
		$xml = $this->export(
			gebruik: [self::usage('m-a')],
			deelnames: [self::sharedUsage('m-b', 'Gemeente Utrecht')],
			withShare: true,
		);

		$this->assertSame(['fill' => '200,255,200', 'line' => '0,150,0'], self::colours($xml, 'id-swc-app-m-a'));
		$this->assertSame(['fill' => '210,225,255', 'line' => '40,80,170'], self::colours($xml, 'deelname-app-m-b'));

	}//end testSharedApplicationsGetTheirOwnColours()

	/**
	 * A shared element carries "Gedeeld door" with the partner; an own one does not.
	 *
	 * @return void
	 */
	public function testASharedElementNamesItsPartner(): void {
		$xml = $this->export(
			gebruik: [self::usage('m-a')],
			deelnames: [
				self::sharedUsage('m-b', 'Gemeente Utrecht'),
				self::sharedUsage('m-b', 'Gemeente Breda'),
				self::sharedUsage('m-b', 'Gemeente Utrecht'),
			],
			withShare: true,
		);

		$definitions = $xml->xpath("//a:propertyDefinition[a:name='Gedeeld door']");
		$this->assertCount(1, $definitions);
		$definitionId = (string)$definitions[0]['identifier'];

		$shared = $xml->xpath("//a:element[@identifier='deelname-app-m-b']/a:properties/a:property[@propertyDefinitionRef='$definitionId']/a:value");
		$this->assertSame(['Gemeente Utrecht, Gemeente Breda'], array_map('strval', $shared));

		$own = $xml->xpath("//a:element[@identifier='id-swc-app-m-a']/a:properties/a:property[@propertyDefinitionRef='$definitionId']");
		$this->assertSame([], $own);

	}//end testASharedElementNamesItsPartner()

	/**
	 * The same application owned and shared on one component is nested once, as own.
	 *
	 * @return void
	 */
	public function testAnOwnedAndSharedApplicationIsNestedOnceAsOwn(): void {
		$xml = $this->export(
			gebruik: [self::usage('m-a')],
			deelnames: [self::sharedUsage('m-a', 'Gemeente Utrecht')],
			withShare: true,
		);

		$nested = $xml->xpath("//a:view[@identifier='id-swc-view-v1']//a:node[@identifier='id-n1']/a:node");
		$this->assertCount(1, $nested);
		$this->assertSame('id-swc-app-m-a', (string)$nested[0]['elementRef']);
		$this->assertSame(['fill' => '200,255,200', 'line' => '0,150,0'], self::colours($xml, 'id-swc-app-m-a'));

	}//end testAnOwnedAndSharedApplicationIsNestedOnceAsOwn()

	/**
	 * Without deelnames only own applications are nested, and no shared property is defined.
	 *
	 * @return void
	 */
	public function testTheExportWithoutDeelnamesNestsOnlyOwnApplications(): void {
		$xml = $this->export(
			gebruik: [self::usage('m-a')],
			deelnames: [self::sharedUsage('m-b', 'Gemeente Utrecht')],
			withShare: false,
		);

		$nested = $xml->xpath("//a:view[@identifier='id-swc-view-v1']//a:node[@identifier='id-n1']/a:node");
		$this->assertSame(['id-swc-app-m-a'], array_map(static fn ($n): string => (string)$n['elementRef'], $nested));
		$this->assertSame(['fill' => '200,255,200', 'line' => '0,150,0'], self::colours($xml, 'id-swc-app-m-a'));
		$this->assertSame([], $xml->xpath("//a:propertyDefinition[a:name='Gedeeld door']"));
		$this->assertSame([], $xml->xpath("//a:element[@identifier='deelname-app-m-b']"));

		$connections = $xml->xpath("//a:view[@identifier='id-swc-view-v1']/a:connection");
		$this->assertCount(1, $connections);
		$this->assertSame('id-swc-rel-m-a-rc1', (string)$connections[0]['relationshipRef']);
		$this->assertSame('id-n1', (string)$connections[0]['target']);

	}//end testTheExportWithoutDeelnamesNestsOnlyOwnApplications()

	/**
	 * A shared usage whose partner is unknown is nested shared, without the property.
	 *
	 * @return void
	 */
	public function testASharedUsageWithoutAPartnerNameHasNoSharedByProperty(): void {
		$xml = $this->export(
			gebruik: [],
			deelnames: [['module' => 'm-b', 'usedForReferenceComponents' => ['rc1'], 'consumer' => 'org-2']],
			withShare: true,
		);

		$this->assertSame(['fill' => '210,225,255', 'line' => '40,80,170'], self::colours($xml, 'deelname-app-m-b'));
		$this->assertSame([], $xml->xpath("//a:propertyDefinition[a:name='Gedeeld door']"));

	}//end testASharedUsageWithoutAPartnerNameHasNoSharedByProperty()
}//end class
