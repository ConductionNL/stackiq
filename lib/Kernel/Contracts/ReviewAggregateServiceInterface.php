<?php

/**
 * Contract of ReviewAggregateService
 *
 * The public surface of ReviewAggregateService that other components call, so they depend
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

/**
 * Contract of ReviewAggregateService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReviewAggregateServiceInterface {

	/**
	 * The approved-only aggregate (average + count) and a bounded list of
	 * approved reviews for a module or dienst.
	 *
	 * @param string $subjectType 'module' or 'catalogService'.
	 * @param string $subjectId The uuid of the module/dienst.
	 *
	 * @return array{ok:bool, reason:string, average:?float, count:int, items:array<int,array<string,mixed>>} Result.
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
	 */
	public function getAggregate(string $subjectType, string $subjectId): array;
}//end interface
