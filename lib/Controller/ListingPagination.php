<?php

/**
 * Listing pagination for Stackiq controllers
 *
 * Reads the pagination query parameters of a listing request (with and
 * without leading underscore) and turns them into service options.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aanbod-listings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCP\IRequest;

/**
 * Pagination options shared by the aanbod and aangeboden-gebruik listings.
 *
 * @spec openspec/specs/aanbod-listings/spec.md
 */
final class ListingPagination {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The listing request.
	 */
	public function __construct(
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The raw limit, offset and page parameters; the underscored name wins.
	 *
	 * @return array{limit: mixed, offset: mixed, page: mixed}
	 *
	 * @spec openspec/specs/aanbod-listings/spec.md
	 */
	public function rawParams(): array {
		return [
			'limit' => $this->request->getParam('_limit') ?? $this->request->getParam('limit'),
			'offset' => $this->request->getParam('_offset') ?? $this->request->getParam('offset'),
			'page' => $this->request->getParam('_page') ?? $this->request->getParam('page'),
		];
	}//end rawParams()

	/**
	 * The pagination options for the numeric raw parameters; limit and offset
	 * are kept with and without underscore for compatibility.
	 *
	 * @param array{limit: mixed, offset: mixed, page: mixed} $raw The raw parameters.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/specs/aanbod-listings/spec.md
	 */
	public function options(array $raw): array {
		$options = [];
		if ($this->isNumber(value: $raw['limit']) === true) {
			$options['_limit'] = (int)$raw['limit'];
			$options['limit']  = (int)$raw['limit'];
		}

		if ($this->isNumber(value: $raw['offset']) === true) {
			$options['_offset'] = (int)$raw['offset'];
			$options['offset']  = (int)$raw['offset'];
		}

		if ($this->isNumber(value: $raw['page']) === true) {
			$options['_page'] = (int)$raw['page'];
		}

		return $options;
	}//end options()

	/**
	 * Whether a raw parameter is a number.
	 *
	 * @param mixed $value The raw parameter.
	 *
	 * @return bool
	 */
	private function isNumber(mixed $value): bool {
		return $value !== null && is_numeric($value) === true;
	}//end isNumber()
}//end class
