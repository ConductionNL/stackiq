<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\SearchOptions.
 *
 * Mirrors the constructor of OpenRegister's value object (openregister #9103),
 * so tests can assert what a searchObjects() call asks for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\OpenRegister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Stub of the options of one search: selection, views, view scope and deleted.
 */
final class SearchOptions {

	/**
	 * Build the options.
	 *
	 * @param ObjectSelection $selection         Ids/uses selection.
	 * @param array|null      $views             Views to apply.
	 * @param bool            $viewScopeRequired Refuse without a view scope.
	 * @param bool            $deleted           Search the deleted objects.
	 */
	public function __construct(
		public readonly ObjectSelection $selection = new ObjectSelection(),
		public readonly ?array $views = null,
		public readonly bool $viewScopeRequired = false,
		public readonly bool $deleted = false,
	) {
	}//end __construct()

}//end class
