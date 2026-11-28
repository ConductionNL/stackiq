<?php

/**
 * The applications and the fillable module names per reference component.
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
 * What the coverage of one landscape knows per reference component: the
 * applications that cover it, by module, and the names of the used modules
 * that declare they could.
 *
 * Built and read by ReferenceComponentCoverageDerivation only.
 */
final class CoverageIndex {
	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, array<string, string>>> $applications The covering applications by component and module.
	 * @param array<string, array<int, string>> $fillableBy The names of the modules that declare a component, by component.
	 */
	public function __construct(
		public readonly array $applications,
		public readonly array $fillableBy,
	) {
	}//end __construct()
}//end class
