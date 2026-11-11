<?php

/**
 * The GEMMA elements and application services the facet dimensions of a request resolve to.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * The referenceComponent elements by identifier and the application service
 * names by referenceComponent, resolved once for a facet request.
 *
 * Built and read by FacetService only.
 */
final class DimensionResolution {
	/**
	 * Constructor.
	 *
	 * @param array<string, array> $elementsById The referenceComponent elements by identifier.
	 * @param array<string, array<int, string>> $applicationServices The application service names by referenceComponent identifier.
	 */
	public function __construct(
		public readonly array $elementsById,
		public readonly array $applicationServices,
	) {
	}//end __construct()
}//end class
