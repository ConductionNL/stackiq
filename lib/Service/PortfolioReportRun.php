<?php

/**
 * Portfolio Report Run.
 *
 * The values one portfolio report build shares between its steps: the
 * organisation reported on, the resolved register/schema ids, the page-size
 * ceiling, the reference moment and the contracts indexed by gebruik. Built
 * by {@see PortfolioReportService} for one report and never handed out.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-aggregation-queries-are-bounded
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * State of one portfolio report build.
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-aggregation-queries-are-bounded
 */
final class PortfolioReportRun {

	/**
	 * The `afnemer` organisation UUID reported on.
	 *
	 * @var string
	 */
	public string $organisationUuid = '';

	/**
	 * The resolved OpenRegister ObjectService.
	 *
	 * @var ObjectServiceInterface
	 */
	public ObjectServiceInterface $objectService;

	/**
	 * Resolved register/schema ids.
	 *
	 * @var array{registerId: int|null, gebruikSchema: int|null, contractSchema: int|null, moduleSchema: int|null, moduleVersieSchema: int|null}
	 */
	public array $cfg;

	/**
	 * The gebruik page-size ceiling.
	 *
	 * @var integer
	 */
	public int $ceiling = 0;

	/**
	 * The reference moment for lifecycle and end-of-support derivation.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $now;

	/**
	 * Contract rows indexed by gebruik uuid.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $contractsByGebruik = [];
}//end class
