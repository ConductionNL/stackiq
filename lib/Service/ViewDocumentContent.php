<?php

/**
 * Stackiq ViewDocumentContent.
 *
 * The content fields of a document of an architecture view (design D3): the
 * view's name, description, viewpoint, a legend of the element types on the
 * view, the generation date, a link back to the view page, and the SVG drawn
 * from the stored geometry. It knows nothing about filinq; the gateway takes
 * what this builds.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Kernel\AppIdentity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Builds the content fields a view document is made from.
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 */
class ViewDocumentContent {

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator    $urlGenerator Builds the link back to the view page.
	 * @param IL10N            $l10n         Translates the labels in the document.
	 * @param ViewImageService $images       Draws the view.
	 * @param ITimeFactory     $clock        Gives the generation moment.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
		private readonly ViewImageService $images,
		private readonly ITimeFactory $clock,
	) {
	}//end __construct()

	/**
	 * The content of a document of this view.
	 *
	 * @param array<string, mixed> $view   The stored view.
	 * @param string               $viewId The id the view was read by, for the link.
	 *
	 * @return array<string, mixed> The content: name, description, viewpoint, legend
	 *                              (type and count per element type), generatedAt, link, svg and labels.
	 *
	 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
	 */
	public function contentFor(array $view, string $viewId): array {
		$at = $this->clock->now();

		return [
			'name' => $this->field(view: $view, keys: ['name']),
			'description' => $this->field(view: $view, keys: ['documentation', 'description']),
			'viewpoint' => $this->field(view: $view, keys: ['viewpoint']),
			'legend' => $this->legendOf(view: $view),
			'generatedAt' => $at->format('Y-m-d'),
			'link' => $this->linkTo(viewId: $viewId),
			'svg' => $this->images->renderSvg(view: $view, at: $at),
			'labels' => ['viewpoint' => $this->l10n->t('Viewpoint')],
		];
	}//end contentFor()

	/**
	 * The element types on the view with how often each occurs, most frequent first, then by name.
	 *
	 * @param array<string, mixed> $view The stored view.
	 *
	 * @return array<int, array{type: string, count: int}> The legend.
	 */
	private function legendOf(array $view): array {
		$nodes = ($view['nodes'] ?? ($view['xml']['nodes'] ?? []));
		if (is_array($nodes) === false) {
			return [];
		}

		$counts = [];
		foreach ($nodes as $node) {
			if (is_array($node) === false) {
				continue;
			}

			$type = trim((string)($node['type'] ?? ''));
			if ($type === '') {
				continue;
			}

			$counts[$type] = (($counts[$type] ?? 0) + 1);
		}

		uksort(
			$counts,
			static fn (string $one, string $other): int => [$counts[$other], $one] <=> [$counts[$one], $other]
		);

		$legend = [];
		foreach ($counts as $type => $count) {
			$legend[] = ['type' => (string)$type, 'count' => $count];
		}

		return $legend;
	}//end legendOf()

	/**
	 * A text field of the view, read from the view or its imported XML, first non-empty wins.
	 *
	 * @param array<string, mixed> $view The stored view.
	 * @param array<int, string>   $keys The field names, in order.
	 *
	 * @return string The text, '' when none is set.
	 */
	private function field(array $view, array $keys): string {
		foreach ($keys as $key) {
			foreach ([($view[$key] ?? null), ($view['xml'][$key] ?? null)] as $value) {
				if (is_scalar($value) === true && trim((string)$value) !== '') {
					return trim((string)$value);
				}
			}
		}

		return '';
	}//end field()

	/**
	 * The absolute link to the view page.
	 *
	 * @param string $viewId The view id.
	 *
	 * @return string The link.
	 */
	private function linkTo(string $viewId): string {
		$app = rtrim($this->urlGenerator->getAbsoluteURL('/index.php/apps/'.AppIdentity::APP_ID), '/');

		return $app.'/views/'.rawurlencode($viewId);
	}//end linkTo()
}//end class
