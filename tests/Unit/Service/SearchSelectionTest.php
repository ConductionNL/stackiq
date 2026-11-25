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
	 * An `id` in the query, a list or one id, moves into the selection; OpenRegister main matches nothing for it in the query.
	 *
	 * @return void
	 */
	public function testAnIdFilterInTheQueryBecomesTheSelection(): void {
		$query = ['@self' => ['register' => 1, 'schema' => 2], 'id' => ['a', 'b'], '_limit' => 2];

		$options = SearchSelection::fromQuery(service: new SearchWithOptions(), method: 'searchObjectsPaginated', query: $query);
		$this->assertSame(expected: ['@self' => ['register' => 1, 'schema' => 2], '_limit' => 2], actual: $options['query']);
		$this->assertSame(expected: ['a', 'b'], actual: $options['options']->selection->ids);

		$legacy = SearchSelection::fromQuery(service: new SearchWithoutOptions(), method: 'searchObjects', query: ['id' => 'a']);
		$this->assertSame(expected: ['query' => [], 'ids' => ['a']], actual: $legacy);
	}//end testAnIdFilterInTheQueryBecomesTheSelection()

	/**
	 * Without an id filter, with an empty id list, or for a search that selects no ids, the query is passed as it is.
	 *
	 * @return void
	 */
	public function testOtherwiseTheQueryIsPassedAsItIs(): void {
		$service = new SearchWithOptions();

		$this->assertSame(expected: ['query' => ['name' => 'x']], actual: SearchSelection::fromQuery(service: $service, method: 'searchObjects', query: ['name' => 'x']));
		$this->assertSame(expected: ['query' => ['id' => []]], actual: SearchSelection::fromQuery(service: $service, method: 'searchObjects', query: ['id' => []]));
		$this->assertSame(expected: ['query' => ['id' => ['a']]], actual: SearchSelection::fromQuery(service: new SearchWithoutSelection(), method: 'searchObjects', query: ['id' => ['a']]));
	}//end testOtherwiseTheQueryIsPassedAsItIs()

	/**
	 * Without the contract classes, the selection stays its own named arguments.
	 *
	 * @return void
	 */
	public function testWithoutTakingOptionsTheSelectionIsUnchanged(): void {
		$this->assertSame(['uses' => 'x'], SearchSelection::arguments(takesOptions: false, selection: ['uses' => 'x']));
	}//end testWithoutTakingOptionsTheSelectionIsUnchanged()

	/**
	 * The first class pair the install has is used; a pair it lacks is skipped.
	 *
	 * OpenRegister main moved SearchOptions and ObjectSelection to the Contract
	 * namespace; looking only under Kernel\Contracts sent `ids` to a search that
	 * no longer takes it (a live 500 when starting a confirmation round).
	 *
	 * @return void
	 */
	public function testTheFirstInstalledClassPairIsUsed(): void {
		$pairs = [
			['No\\Such\\SearchOptions', 'No\\Such\\ObjectSelection'],
			[SearchOptions::class, ObjectSelection::class],
		];

		$arguments = SearchSelection::arguments(takesOptions: true, selection: ['ids' => ['a']], pairs: $pairs);

		$this->assertInstanceOf(expected: SearchOptions::class, actual: $arguments['options']);
		$this->assertSame(expected: ['a'], actual: $arguments['options']->selection->ids);
	}//end testTheFirstInstalledClassPairIsUsed()

	/**
	 * With no pair installed, the selection stays its own named arguments.
	 *
	 * @return void
	 */
	public function testWithNoInstalledPairTheSelectionIsUnchanged(): void {
		$pairs = [['No\\Such\\SearchOptions', 'No\\Such\\ObjectSelection']];

		$this->assertSame(expected: ['ids' => ['a']], actual: SearchSelection::arguments(takesOptions: true, selection: ['ids' => ['a']], pairs: $pairs));
	}//end testWithNoInstalledPairTheSelectionIsUnchanged()

	/**
	 * The Contract namespace is looked for first, the Kernel\Contracts one second.
	 *
	 * @return void
	 */
	public function testTheContractNamespaceIsPreferred(): void {
		$this->assertSame(expected: 'OCA\\OpenRegister\\Contract\\SearchOptions', actual: SearchSelection::CLASS_PAIRS[0][0]);
		$this->assertSame(expected: 'OCA\\OpenRegister\\Kernel\\Contracts\\SearchOptions', actual: SearchSelection::CLASS_PAIRS[1][0]);
	}//end testTheContractNamespaceIsPreferred()
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

/**
 * A search that takes no id selection at all.
 */
class SearchWithoutSelection {

	/**
	 * Search.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<int, mixed>
	 */
	public function searchObjects(array $query=[]): array {
		return [];
	}//end searchObjects()
}//end class
