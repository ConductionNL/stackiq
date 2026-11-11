<?php

/**
 * The ids and slugs that link a gebruik object to AMEF elements.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * Reads the referentiecomponent ids of a gebruik object and the slugs of the AMEF elements found for them.
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class AmefReferences {
	/**
	 * Collect the id of every referentiecomponent that has one.
	 *
	 * @param mixed $referenceComponents The referentiecomponenten
	 *
	 * @return array The ids.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function idsOf(mixed $referenceComponents): array {
		$referenceIds = [];
		foreach ($referenceComponents as $component) {
			if (isset($component['id']) === true) {
				$referenceIds[] = $component['id'];
			}
		}

		return $referenceIds;
	}//end idsOf()

	/**
	 * Collect the slugs of the found AMEF elements.
	 *
	 * @param array $amefElements The found AMEF elements
	 * @param array $stats        Processing statistics, updated in place
	 *
	 * @return array The slugs, in element order.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function slugsOf(array $amefElements, array &$stats): array {
		$amefSlugs = [];
		foreach ($amefElements as $amefElement) {
			$amefData = $amefElement->getObject();
			if (isset($amefData['slug']) === true) {
				$amefSlugs[] = $amefData['slug'];
				$stats['amefElementsProcessed']++;
			}
		}

		return $amefSlugs;
	}//end slugsOf()
}//end class
