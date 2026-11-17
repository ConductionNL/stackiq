<?php

/**
 * Unit test for SearchSelection.
 *
 * Pins the selection arguments for both OpenRegister search signatures: one
 * `options` argument where the SearchOptions contract exists, `ids` / `uses`
 * named arguments where it does not.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\ObjectSelection;
use OCA\OpenRegister\Kernel\Contracts\SearchOptions;
use OCA\Stackiq\Service\SearchSelection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Stackiq\Service\SearchSelection
 */
class SearchSelectionTest extends TestCase {

	/**
	 * A search that takes `options` gets the ids as one SearchOptions argument.
	 *
	 * @return void
	 */
	public function testIdsAreOneOptionsArgumentForASearchThatTakesOptions(): void {
		$arguments = SearchSelection::ids(service: new SearchWithOptions(), method: 'searchObjects', ids: ['a', 'b']);

		$this->assertSame(['options'], array_keys($arguments));
		$this->assertInstanceOf(SearchOptions::class, $arguments['options']);
		$this->assertInstanceOf(ObjectSelection::class, $arguments['options']->selection);
		$this->assertSame(['a', 'b'], $arguments['options']->selection->ids);
		$this->assertNull($arguments['options']->selection->uses);
	}//end testIdsAreOneOptionsArgumentForASearchThatTakesOptions()

	/**
	 * A search that takes `options` gets a uses lookup inside it.
	 *
	 * @return void
	 */
	public function testUsesIsOneOptionsArgumentForASearchThatTakesOptions(): void {
		$arguments = SearchSelection::uses(service: new SearchWithOptions(), method: 'searchObjectsPaginated', uses: 'suite-1');

		$this->assertSame('suite-1', $arguments['options']->selection->uses);
		$this->assertNull($arguments['options']->selection->ids);
	}//end testUsesIsOneOptionsArgumentForASearchThatTakesOptions()

	/**
	 * A search that takes `ids` and `uses` gets them as its own named arguments.
	 *
	 * @return void
	 */
	public function testASearchWithoutOptionsGetsItsOwnNamedArguments(): void {
		$service = new SearchWithoutOptions();

		$this->assertSame(['ids' => ['a']], SearchSelection::ids(service: $service, method: 'searchObjects', ids: ['a']));
		$this->assertSame(['uses' => 'suite-1'], SearchSelection::uses(service: $service, method: 'searchObjectsPaginated', uses: 'suite-1'));
		$this->assertSame(['ids' => ['a']], SearchSelection::ids(service: $service, method: 'noSuchSearch', ids: ['a']));
	}//end testASearchWithoutOptionsGetsItsOwnNamedArguments()

	/**
	 * Without the contract classes, the selection stays its own named arguments.
	 *
	 * @return void
	 */
	public function testWithoutTakingOptionsTheSelectionIsUnchanged(): void {
		$this->assertSame(['uses' => 'x'], SearchSelection::arguments(takesOptions: false, selection: ['uses' => 'x']));
	}//end testWithoutTakingOptionsTheSelectionIsUnchanged()
}//end class

/**
 * A search that takes its selection as one `options` argument.
 */
class SearchWithOptions {

	/**
	 * Search.
	 *
	 * @param array<string, mixed> $query   The query.
	 * @param SearchOptions|null   $options The selection.
	 *
	 * @return array<int, mixed>
	 */
	public function searchObjects(array $query=[], ?SearchOptions $options=null): array {
		return [];
	}//end searchObjects()

	/**
	 * Search, paginated.
	 *
	 * @param array<string, mixed> $query   The query.
	 * @param SearchOptions|null   $options The selection.
	 *
	 * @return array<string, mixed>
	 */
	public function searchObjectsPaginated(array $query=[], ?SearchOptions $options=null): array {
		return [];
	}//end searchObjectsPaginated()
}//end class

/**
 * A search that takes `ids` and `uses` as named arguments.
 */
class SearchWithoutOptions {

	/**
	 * Search.
	 *
	 * @param array<string, mixed>    $query The query.
	 * @param array<int, string>|null $ids   The ids.
	 * @param string|null             $uses  The uuid.
	 *
	 * @return array<int, mixed>
	 */
	public function searchObjects(array $query=[], ?array $ids=null, ?string $uses=null): array {
		return [];
	}//end searchObjects()

	/**
	 * Search, paginated.
	 *
	 * @param array<string, mixed>    $query The query.
	 * @param array<int, string>|null $ids   The ids.
	 * @param string|null             $uses  The uuid.
	 *
	 * @return array<string, mixed>
	 */
	public function searchObjectsPaginated(array $query=[], ?array $ids=null, ?string $uses=null): array {
		return [];
	}//end searchObjectsPaginated()
}//end class
