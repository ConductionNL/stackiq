<?php

/**
 * Unit tests for ViewImageService.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use OCA\Stackiq\Service\ViewImageService;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the SVG is the stored view, and nothing a view's text could inject.
 *
 * @covers \OCA\Stackiq\Service\ViewImageService
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
 */
class ViewImageServiceTest extends TestCase {

	/**
	 * The renderer.
	 *
	 * @var ViewImageService
	 */
	private ViewImageService $service;

	/**
	 * Build the renderer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new ViewImageService();
	}//end setUp()

	/**
	 * A GEMMA view with a group holding two components, joined by a Flow with one bendpoint.
	 *
	 * @param string $name The view name.
	 *
	 * @return array<string, mixed> The stored view.
	 */
	private function view(string $name = 'Zaakgericht werken, huidige situatie'): array {
		return [
			'name' => $name,
			'nodes' => [
				['viewNodeId' => 'n-group', 'x' => 10, 'y' => 10, 'width' => 400, 'height' => 200, 'parent' => null, 'name' => 'Zaakregistratie', 'type' => 'grouping'],
				['viewNodeId' => 'n-zaak', 'x' => 30, 'y' => 60, 'width' => 120, 'height' => 55, 'parent' => 'n-group', 'name' => 'Zaaksysteem', 'type' => 'applicatiecomponent'],
				['viewNodeId' => 'n-dms', 'x' => 250, 'y' => 60, 'width' => 120, 'height' => 55, 'parent' => 'n-group', 'name' => 'Documentbeheer', 'type' => 'ApplicationComponent'],
				['viewNodeId' => 'n-service', 'x' => 30, 'y' => 260, 'width' => 140, 'height' => 55, 'parent' => null, 'name' => 'Zaakafhandeling', 'type' => 'businessservice'],
			],
			'viewRelationships' => [
				['sourceId' => 'n-zaak', 'targetId' => 'n-dms', 'type' => 'flow', 'bendpoints' => [['x' => 200, 'y' => 40]]],
				['sourceId' => 'n-zaak', 'targetId' => 'n-service', 'type' => 'serving', 'bendpoints' => []],
				['sourceId' => 'n-zaak', 'targetId' => 'n-gone', 'type' => 'serving', 'bendpoints' => []],
			],
		];
	}//end view()

	/**
	 * The SVG as a queryable document.
	 *
	 * @param string $svg The SVG.
	 *
	 * @return DOMXPath The query object.
	 */
	private function xpath(string $svg): DOMXPath {
		$document = new DOMDocument();
		$this->assertTrue($document->loadXML($svg), 'the SVG must be well-formed XML');
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
		return $xpath;
	}//end xpath()

	/**
	 * Every node sits at its stored position and size, children inside their parent and painted after it.
	 *
	 * @return void
	 */
	public function testNodesSitAtTheirStoredGeometryWithChildrenInsideTheirParent(): void {
		$xpath = $this->xpath($this->service->renderSvg($this->view(), new DateTimeImmutable('2026-11-14')));

		$rects = [];
		foreach ($xpath->query('//s:rect[@rx]') as $rect) {
			$rects[] = [$rect->getAttribute('x'), $rect->getAttribute('y'), $rect->getAttribute('width'), $rect->getAttribute('height'), $rect->getAttribute('fill')];
		}

		$this->assertSame(
			[
				['10', '10', '400', '200', '#ffffff'],
				['30', '260', '140', '55', '#ffffb5'],
				['30', '60', '120', '55', '#b5ffff'],
				['250', '60', '120', '55', '#b5ffff'],
			],
			$rects,
			'top-level nodes first, then their children, each at its stored geometry and layer colour'
		);

		$texts = array_map(static fn ($node): string => $node->textContent, iterator_to_array($xpath->query('//s:text')));
		foreach (['Zaaksysteem', 'Documentbeheer', 'Zaakregistratie', 'applicatiecomponent'] as $expected) {
			$this->assertContains($expected, $texts);
		}

		$this->assertSame('Zaakgericht werken, huidige situatie · 2026-11-14 · drawn by stackiq', end($texts));
	}//end testNodesSitAtTheirStoredGeometryWithChildrenInsideTheirParent()

	/**
	 * Connections run from box edge through their bendpoints to box edge, with their relation's arrowhead.
	 *
	 * @return void
	 */
	public function testConnectionsRunThroughTheirBendpointsWithTheirArrowhead(): void {
		$xpath = $this->xpath($this->service->renderSvg($this->view(), new DateTimeImmutable('2026-11-14')));
		$paths = iterator_to_array($xpath->query('//s:path[@stroke-width="1.2"]'));

		$this->assertCount(2, $paths, 'a connection to a node not on the view is left out');

		$flow = $paths[0];
		$this->assertSame('M150 61.6 L200 40 L250 61.6', $flow->getAttribute('d'), 'leaves the source right edge, bends, enters the target left edge');
		$this->assertSame('url(#end-filled)', $flow->getAttribute('marker-end'));
		$this->assertSame('6 3', $flow->getAttribute('stroke-dasharray'));

		$serving = $paths[1];
		$this->assertSame('url(#end-open)', $serving->getAttribute('marker-end'));
		$this->assertFalse($serving->hasAttribute('stroke-dasharray'));
		$this->assertSame(1, $xpath->query('//s:marker[@id="end-open"]')->length);
	}//end testConnectionsRunThroughTheirBendpointsWithTheirArrowhead()

	/**
	 * Every relation type gets its own ending; an unknown one is drawn as a plain association.
	 *
	 * @return void
	 */
	public function testRelationTypesMapToTheirMarkers(): void {
		$cases = [
			'composition' => ['', 'url(#start-diamond-filled)'],
			'aggregation' => ['', 'url(#start-diamond-hollow)'],
			'assignment' => ['url(#end-filled)', 'url(#start-dot)'],
			'RealizationRelationship' => ['url(#end-hollow)', ''],
			'specialization' => ['url(#end-hollow)', ''],
			'association' => ['', ''],
			'made-up' => ['', ''],
		];

		foreach ($cases as $type => [$end, $start]) {
			$view = $this->view();
			$view['viewRelationships'] = [['sourceId' => 'n-zaak', 'targetId' => 'n-service', 'type' => $type]];
			$path = $this->xpath($this->service->renderSvg($view))->query('//s:path[@stroke-width="1.2"]')->item(0);

			$this->assertSame($end, $path->getAttribute('marker-end'), $type . ' end');
			$this->assertSame($start, $path->getAttribute('marker-start'), $type . ' start');
		}
	}//end testRelationTypesMapToTheirMarkers()

	/**
	 * A name with markup is escaped, and the SVG has no script, external reference or foreignObject.
	 *
	 * @return void
	 */
	public function testAViewNameCannotInjectMarkup(): void {
		$view = $this->view('<script>alert(1)</script><a href="https://evil.example">x</a>');
		$view['nodes'][1]['name'] = '<foreignObject><img src=x onerror=alert(1)></foreignObject>';
		$view['nodes'][1]['type'] = '"><script>';

		$svg = $this->service->renderSvg($view);
		$xpath = $this->xpath($svg);

		$this->assertSame(0, $xpath->query('//s:script | //script | //s:foreignObject | //foreignObject | //s:a | //s:image')->length);
		$this->assertSame(0, $xpath->query('//@href | //@*[local-name()="href"] | //@onerror')->length);
		$this->assertStringNotContainsString('<script', $svg);
		$this->assertStringNotContainsString('<foreignObject', $svg);
		$this->assertSame('<script>alert(1)</script><a href="https://evil.example">x</a>', $xpath->query('//s:title')->item(0)->textContent);
	}//end testAViewNameCannotInjectMarkup()

	/**
	 * Geometry stored only under `xml`, and an empty view, still draw a valid SVG.
	 *
	 * @return void
	 */
	public function testGeometryUnderXmlAndAnEmptyViewStillDraw(): void {
		$view = $this->view();
		$stored = ['name' => 'Oud', 'xml' => ['nodes' => $view['nodes'], 'viewRelationships' => $view['viewRelationships']]];
		$this->assertSame(4, $this->xpath($this->service->renderSvg($stored))->query('//s:rect[@rx]')->length);

		$empty = $this->xpath($this->service->renderSvg(['name' => 'Leeg', 'nodes' => 'not a list']));
		$this->assertSame(0, $empty->query('//s:rect[@rx]')->length);
		$this->assertSame('20', $empty->query('/s:svg')->item(0)->getAttribute('width'));
	}//end testGeometryUnderXmlAndAnEmptyViewStillDraw()
}//end class
