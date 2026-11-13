<?php

/**
 * Contract of FacetService
 *
 * The public surface of FacetService that other components call, so they depend
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of FacetService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FacetServiceInterface {

	/**
	 * Get GEMMA-dimension facet counts for a schema.
	 *
	 * @param string $schema `module` or `dienst`.
	 * @param array $filters Currently-selected facet values keyed by dimension,
	 *                       e.g. `['referenceComponent' => ['Zaakregistratiecomponent']]`.
	 * @param string|null $search Free-text query narrowing the candidate set.
	 * @param string|null $organization Optional organisation override (mirrors
	 *                                  view-enrichment-api's `organization` parameter).
	 *
	 * @throws InvalidArgumentException When `$schema` is not supported.
	 * @throws RuntimeException When OpenRegister's ObjectService is unavailable.
	 *
	 * @return array{referenceComponent: array, standard: array, applicationService: array,
	 *     domain: array, _meta: array{totalMatched: int, processingTimeMs: float, cached: bool,
	 *     matchedObjectIds: string[]}}
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-queries-must-be-bounded
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-must-respect-the-callers-rbactenant-context
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached
	 */
	public function getFacets(string $schema, array $filters = [], ?string $search = null, ?string $organization = null): array;
}//end interface
