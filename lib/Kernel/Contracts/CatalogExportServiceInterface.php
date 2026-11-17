<?php

/**
 * Contract of CatalogExportService
 *
 * The public surface of CatalogExportService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\Stackiq\Service\Export\CatalogExportRequest;
use RuntimeException;

/**
 * Contract of CatalogExportService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CatalogExportServiceInterface {

	/**
	 * The schemas this export serves: the two faceted lists.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMAS = ['module', 'catalogService'];

	/**
	 * Refuse a schema this export does not serve.
	 *
	 * @param string $schema The schema slug from the URL.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is not `module` or `catalogService`.
	 *
	 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function assertSchema(string $schema): void;

	/**
	 * Whether the caller may export the schema (see CatalogExportGate).
	 *
	 * @param string $schema The schema slug, already checked.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function mayExport(string $schema): bool;

	/**
	 * The export file of the list as filtered.
	 *
	 * @param string               $schema  `module` or `catalogService`, already checked.
	 * @param CatalogExportRequest $request The facets, search, quick filter, columns and format.
	 *
	 * @return array{body: string, contentType: string, filename: string}
	 *
	 * @throws InvalidArgumentException On an unknown format.
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function export(string $schema, CatalogExportRequest $request): array;
}//end interface
