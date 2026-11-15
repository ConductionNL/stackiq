<?php

/**
 * Tests for ViewDocumentContent: the fields a document of a view is made from.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Stackiq\Service\ViewDocumentContent;
use OCA\Stackiq\Service\ViewImageService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewDocumentContent::class)]
class ViewDocumentContentTest extends TestCase {

	private ViewImageService&MockObject $images;

	private ViewDocumentContent $content;

	protected function setUp(): void {
		parent::setUp();
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->with('/index.php/apps/stackiq')
			->willReturn('https://cloud.example/index.php/apps/stackiq/');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);
		$this->images  = $this->createMock(ViewImageService::class);
		$this->content = new ViewDocumentContent(urlGenerator: $urls, l10n: $l10n, images: $this->images);
	}

	public function testTheContentHoldsTheViewItsLegendDateLinkAndDrawing(): void {
		$at   = new DateTimeImmutable('2026-11-15T09:30:00+01:00');
		$view = [
			'name' => ' Zaakgericht werken ',
			'documentation' => 'De applicaties rond de zaak.',
			'viewpoint' => 'Application Cooperation',
			'nodes' => [
				['viewNodeId' => 'n1', 'type' => 'ApplicationComponent'],
				['viewNodeId' => 'n2', 'type' => 'BusinessProcess'],
				['viewNodeId' => 'n3', 'type' => 'ApplicationComponent'],
				['viewNodeId' => 'n4', 'type' => 'ApplicationService'],
				['viewNodeId' => 'n5', 'type' => ''],
				['viewNodeId' => 'n6'],
				'not a node',
			],
		];
		$this->images->expects($this->once())->method('renderSvg')->with($view, $at)->willReturn('<svg/>');

		$this->assertSame(
			[
				'name' => 'Zaakgericht werken',
				'description' => 'De applicaties rond de zaak.',
				'viewpoint' => 'Application Cooperation',
				'legend' => [
					['type' => 'ApplicationComponent', 'count' => 2],
					['type' => 'ApplicationService', 'count' => 1],
					['type' => 'BusinessProcess', 'count' => 1],
				],
				'generatedAt' => '2026-11-15',
				'link' => 'https://cloud.example/index.php/apps/stackiq/views/view%201',
				'svg' => '<svg/>',
				'labels' => ['viewpoint' => 'nl:Viewpoint'],
			],
			$this->content->contentFor(view: $view, viewId: 'view 1', at: $at)
		);
	}

	public function testFieldsAndNodesAreReadFromTheImportedXmlWhenTheViewLacksThem(): void {
		$this->images->method('renderSvg')->willReturn('<svg/>');
		$view = [
			'name' => '',
			'description' => '   ',
			'xml' => [
				'name' => 'Uit de import',
				'documentation' => 'Toelichting uit de import.',
				'nodes' => [['viewNodeId' => 'n1', 'type' => 'Node']],
			],
		];

		$content = $this->content->contentFor(view: $view, viewId: 'v-2', at: new DateTimeImmutable('2026-01-02'));

		$this->assertSame('Uit de import', $content['name']);
		$this->assertSame('Toelichting uit de import.', $content['description']);
		$this->assertSame('', $content['viewpoint']);
		$this->assertSame([['type' => 'Node', 'count' => 1]], $content['legend']);
	}

	public function testAViewWithoutNodesHasAnEmptyLegend(): void {
		$this->images->method('renderSvg')->willReturn('<svg/>');

		$content = $this->content->contentFor(view: ['name' => 'Leeg', 'nodes' => 'none'], viewId: 'v-3', at: new DateTimeImmutable());

		$this->assertSame([], $content['legend']);
		$this->assertSame('', $content['description']);
	}
}
