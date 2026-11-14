<?php

/**
 * Contract of PortfolioReportService
 *
 * The public surface of PortfolioReportService that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

use Exception;

/**
 * Contract of PortfolioReportService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortfolioReportServiceInterface {

	/**
	 * Build the CSV export of the same bounded, organisation-scoped row set
	 * the JSON report uses — never a separate unbounded/unscoped data path.
	 *
	 * @param string $organisationUuid The `afnemer` organisation UUID to export.
	 *
	 * @return string The CSV document (header + one data row per gebruik).
	 *
	 * @throws Exception When OpenRegister or the voorzieningen configuration is unavailable.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-csv-export-of-the-portfolio-report
	 */
	public function buildCsv(string $organisationUuid): string;

	/**
	 * Build the portfolio rationalization report for one organisation.
	 *
	 * Every OpenRegister query issued here carries an explicit `_limit`
	 * (`bound-unbounded-searchobjects-scans`). The caller (controller) MUST
	 * have already authorised the requesting user for `$organisationUuid`
	 * before invoking this method — this service does not itself gate
	 * access.
	 *
	 * @param string $organisationUuid The `afnemer` organisation UUID to report on.
	 *
	 * @return array<string,mixed> The report payload.
	 *
	 * @throws Exception When OpenRegister or the voorzieningen configuration is unavailable.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-aggregation-queries-are-bounded
	 */
	public function buildReport(string $organisationUuid): array;
}//end interface
