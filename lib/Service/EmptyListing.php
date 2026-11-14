<?php

/**
 * The empty page of a paginated listing.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * The page a listing answers when it has nothing to list: no results on page 1 of 0.
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */
final class EmptyListing {
	/**
	 * The empty page, to which a caller may add a `message` or `error`.
	 *
	 * @return array{results: array, total: int, page: int, pages: int, limit: int, offset: int}
	 *
	 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function page(): array {
		return [
			'results' => [],
			'total' => 0,
			'page' => 1,
			'pages' => 0,
			'limit' => 20,
			'offset' => 0,
		];
	}//end page()
}//end class
