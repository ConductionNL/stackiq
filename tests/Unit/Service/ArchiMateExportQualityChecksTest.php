<?php

/**
 * Quality checks and lookup maps of the ArchiMate export.
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

use InvalidArgumentException;
use JsonSerializable;
use OCA\Stackiq\Service\ArchiMateExportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the quality checks the export runs on its own XML (each refusal and
 * its message) and the module and partner lookup maps of the organisation
 * export.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateExportService
 *
 * @spec openspec/specs/archimate-export/spec.md
 */
class ArchiMateExportQualityChecksTest extends TestCase {

	/**
	 * The logger the service writes to.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The export service under test.
	 *
	 * @var ArchiMateExportService
	 */
	private ArchiMateExportService $service;

	/**
	 * Set up the service with a mocked logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->service = new ArchiMateExportService($this->logger);

	}//end setUp()

	/**
	 * Call a private method of the service.
	 *
	 * @param string $method    The method name.
	 * @param array  $arguments The named arguments.
	 *
	 * @return mixed
	 */
	private function call(string $method, array $arguments): mixed {
		$reflection = new ReflectionMethod(ArchiMateExportService::class, $method);
		return $reflection->invokeArgs($this->service, $arguments);

	}//end call()

	/**
	 * Wrap model content in a root element that declares the xsi namespace.
	 *
	 * @param string $body The inner markup.
	 *
	 * @return string
	 */
	private function model(string $body): string {
		return '<model xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . $body . '</model>';

	}//end model()

	/**
	 * An element with a matching object-id property.
	 *
	 * @param string $id   The identifier, with its id- prefix.
	 * @param string $name The element name.
	 *
	 * @return string
	 */
	private function element(string $id, string $name='Zaaksysteem'): string {
		$objectId = str_replace('id-', '', $id);
		return '<element identifier="' . $id . '" xsi:type="ApplicationComponent"><name>' . $name . '</name>'
			. '<properties><property propertyDefinitionRef="propid-2"><value>' . $objectId . '</value></property></properties>'
			. '</element>';

	}//end element()

	/**
	 * Run the checks and return the refusal message, or null when they pass.
	 *
	 * @param string $xml The XML to check.
	 *
	 * @return string|null
	 */
	private function refusal(string $xml): ?string {
		try {
			$this->call(method: 'runQualityAssuranceChecks', arguments: ['xmlString' => $xml]);
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
		}

		return null;

	}//end refusal()

	/**
	 * A well-formed model passes every check and logs the success.
	 *
	 * @return void
	 */
	public function testAWellFormedModelPassesEveryCheck(): void {
		$messages = [];
		$this->logger->method('info')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);
		$this->logger->expects($this->never())->method('error');

		$xml = $this->model(
			$this->element(id: 'id-a') . $this->element(id: 'id-b', name: 'Burgerzaken')
			. '<relationships><relationship identifier="id-r" xsi:type="Serving" source="id-a" target="id-b"/></relationships>'
		);

		$this->assertNull($this->refusal(xml: $xml));
		$this->assertSame(
			['Running Quality Assurance checks on exported XML', 'All Quality Assurance checks passed successfully'],
			$messages
		);

	}//end testAWellFormedModelPassesEveryCheck()

	/**
	 * A refusal is logged as an error and rethrown with the export prefix.
	 *
	 * @return void
	 */
	public function testARefusalIsLoggedAndRethrownWithThePrefix(): void {
		$this->logger->expects($this->once())
			->method('error')
			->with('Quality Assurance check failed: Element missing identifier');

		$this->assertSame(
			'Export QA validation failed: Element missing identifier',
			$this->refusal(xml: $this->model('<element xsi:type="ApplicationComponent"/>'))
		);

	}//end testARefusalIsLoggedAndRethrownWithThePrefix()

	/**
	 * Unparseable XML is refused through the same path.
	 *
	 * @return void
	 */
	public function testUnparseableXmlIsRefused(): void {
		$previous = libxml_use_internal_errors(true);
		try {
			$message = $this->refusal(xml: '<model><unclosed></model>');
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		$this->assertNotNull($message);
		$this->assertStringStartsWith('Export QA validation failed: ', $message);

	}//end testUnparseableXmlIsRefused()

	/**
	 * Elements need a type and a unique identifier.
	 *
	 * @return void
	 */
	public function testElementsNeedATypeAndAUniqueIdentifier(): void {
		$this->assertSame(
			'Export QA validation failed: Element missing xsi:type: id-a',
			$this->refusal(xml: $this->model('<element identifier="id-a"/>'))
		);
		$this->assertSame(
			'Export QA validation failed: Duplicate identifier found: id-a',
			$this->refusal(xml: $this->model($this->element(id: 'id-a') . $this->element(id: 'id-a')))
		);

	}//end testElementsNeedATypeAndAUniqueIdentifier()

	/**
	 * Relationships need a type and a source and target that exist.
	 *
	 * @return void
	 */
	public function testRelationshipsNeedATypeAndExistingEnds(): void {
		$elements = $this->element(id: 'id-a') . $this->element(id: 'id-b');
		$cases    = [
			'<relationship identifier="id-r" source="id-a" target="id-b"/>'                        => 'Relationship missing xsi:type: id-r',
			'<relationship identifier="id-r" xsi:type="Serving" target="id-b"/>'                   => 'Relationship missing source: id-r',
			'<relationship identifier="id-r" xsi:type="Serving" source="id-x" target="id-b"/>'     => 'Relationship source references non-existent identifier: id-x',
			'<relationship identifier="id-r" xsi:type="Serving" source="id-a"/>'                   => 'Relationship missing target: id-r',
			'<relationship identifier="id-r" xsi:type="Serving" source="id-a" target="id-y"/>'     => 'Relationship target references non-existent identifier: id-y',
		];

		foreach ($cases as $relationship => $expected) {
			$this->assertSame(
				'Export QA validation failed: ' . $expected,
				$this->refusal(xml: $this->model($elements . $relationship)),
				$relationship
			);
		}

	}//end testRelationshipsNeedATypeAndExistingEnds()

	/**
	 * Properties need a definition reference and a non-empty value.
	 *
	 * @return void
	 */
	public function testPropertiesNeedAReferenceAndAValue(): void {
		$cases = [
			'<property><value>x</value></property>'                                => 'Property missing propertyDefinitionRef',
			'<property propertyDefinitionRef="propid-9"/>'                         => 'Property missing value element: propid-9',
			'<property propertyDefinitionRef="propid-9"><value>  </value></property>' => 'Property has empty value: propid-9',
		];

		foreach ($cases as $property => $expected) {
			$this->assertSame(
				'Export QA validation failed: ' . $expected,
				$this->refusal(xml: $this->model('<propertyDefinitions>' . $property . '</propertyDefinitions>')),
				$property
			);
		}

	}//end testPropertiesNeedAReferenceAndAValue()

	/**
	 * Every element carries its object id as propid-2, equal to its identifier.
	 *
	 * @return void
	 */
	public function testElementsCarryTheirObjectIdProperty(): void {
		$this->assertSame(
			'Export QA validation failed: Element missing propid-2 property: id-a',
			$this->refusal(xml: $this->model('<element identifier="id-a" xsi:type="ApplicationComponent"><name>A</name></element>'))
		);
		$this->assertSame(
			'Export QA validation failed: Element propid-2 value mismatch. Expected: a, Got: b (Element: id-a)',
			$this->refusal(
				xml: $this->model(
					'<element identifier="id-a" xsi:type="ApplicationComponent">'
					. '<properties><property propertyDefinitionRef="propid-2"><value>b</value></property></properties></element>'
				)
			)
		);

	}//end testElementsCarryTheirObjectIdProperty()

	/**
	 * Names, documentation and values must be trimmed and single-spaced.
	 *
	 * @return void
	 */
	public function testTextContentMustBeNormalised(): void {
		$this->assertSame(
			"Export QA validation failed: Text content not normalized in <name>. Expected: 'Zaak systeem', Got: 'Zaak  systeem'",
			$this->refusal(xml: $this->model($this->element(id: 'id-a', name: 'Zaak  systeem')))
		);
		$this->assertSame(
			"Export QA validation failed: Text content not normalized in <documentation>. Expected: 'Uitleg', Got: ' Uitleg'",
			$this->refusal(xml: $this->model('<documentation> Uitleg</documentation>'))
		);

	}//end testTextContentMustBeNormalised()

	/**
	 * The module maps take names from modules (plain or @self, arrays or
	 * serialisable objects) and reference components from gebruik.
	 *
	 * @return void
	 */
	public function testTheModuleLookupMaps(): void {
		$moduleObject = new class implements JsonSerializable {
			/**
			 * The serialised module.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'm2', 'name' => 'Module twee']];
			}
		};
		$usageObject = new class implements JsonSerializable {
			/**
			 * The serialised gebruik.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['module' => 'm2', 'usedForReferenceComponents' => ['rc3']];
			}
		};

		[$refMap, $nameMap] = $this->call(
			method: 'buildModuleLookupMaps',
			arguments: [
				'gebruikData' => [
					['moduleName' => 'no module id'],
					[
						'module' => 'm1',
						'usedForReferenceComponents' => [
							['id' => 'rc1'],
							['uuid' => 'rc2'],
							'rc1',
							['name' => 'no id'],
						],
					],
					$usageObject,
					['module' => 'm3', 'moduleName' => 'Uit gebruik', 'usedForReferenceComponents' => 'not a list'],
					['module' => 'm4', '@self' => ['name' => 'Self naam']],
					['module' => 'm5'],
				],
				'modulesData' => [
					['id' => 'm1', 'name' => 'Module een'],
					$moduleObject,
					['id' => 'm9'],
				],
			]
		);

		$this->assertSame(['m1' => ['id-rc1', 'id-rc2'], 'm2' => ['id-rc3']], $refMap);
		$this->assertSame(
			['m1' => 'Module een', 'm2' => 'Module twee', 'm3' => 'Uit gebruik', 'm4' => 'Self naam', 'm5' => 'Module'],
			$nameMap
		);

	}//end testTheModuleLookupMaps()

	/**
	 * The partner map names each module's partners once, in first-seen
	 * order, from the source organisation or else the consumer's name.
	 *
	 * @return void
	 */
	public function testThePartnerMap(): void {
		$usageObject = new class implements JsonSerializable {
			/**
			 * The serialised deelname.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['module' => 7, 'consumer' => ['name' => 'Gemeente C']];
			}
		};

		$map = $this->call(
			method: 'buildPartnerMap',
			arguments: [
				'deelnamesData' => [
					'not an array',
					['module' => ['nested'], '_sourceOrganization' => 'Skipped'],
					['module' => 'm1', '_sourceOrganization' => 'Gemeente A'],
					['module' => 'm1', 'consumer' => ['name' => 'Gemeente B']],
					['module' => 'm1', '_sourceOrganization' => 'Gemeente A'],
					['module' => 'm1', '_sourceOrganization' => '', 'consumer' => ['name' => '']],
					['module' => 'm2', 'consumer' => 'not an array'],
					$usageObject,
				],
			]
		);

		$this->assertSame(['m1' => ['Gemeente A', 'Gemeente B'], 7 => ['Gemeente C']], $map);

	}//end testThePartnerMap()
}//end class
