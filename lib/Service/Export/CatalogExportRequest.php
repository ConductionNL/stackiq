<?php

/**
 * Stackiq catalogue export request
 *
 * What a list export asks for: the facets, the search, the quick filter, the
 * columns and the file format.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Export;

/**
 * The filters, columns and format of one list export.
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */
final class CatalogExportRequest {

	/**
	 * Constructor.
	 *
	 * @param array<string, array>                          $facets      The selected facet values per GEMMA dimension.
	 * @param string|null                                   $search      The search box.
	 * @param array<string, mixed>                          $quickFilter The active quick filter's field values.
	 * @param array<int, array{key: string, label: string}> $columns     The page's columns; empty for the default ones.
	 * @param string                                        $format      `csv` or `xlsx`.
	 */
	public function __construct(
		public readonly array $facets=[],
		public readonly ?string $search=null,
		public readonly array $quickFilter=[],
		public readonly array $columns=[],
		public readonly string $format='csv',
	) {
	}//end __construct()
}//end class
