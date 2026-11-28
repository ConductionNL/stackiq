<?php

/**
 * Tests for the legacy catalogue matcher.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\LegacyCatalogue
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\LegacyCatalogue;

use OCA\Stackiq\Service\LegacyCatalogue\LegacyCatalogueMatcher;
use PHPUnit\Framework\TestCase;

/**
 * A row matches on KvK, then exact name, for suppliers; on supplier and name for products; on number for versions.
 */
class LegacyCatalogueMatcherTest extends TestCase {

	/**
	 * A matcher over Centric with Key2Financiën 2026.1, and a second supplier.
	 *
	 * @return LegacyCatalogueMatcher
	 */
	private function matcher(): LegacyCatalogueMatcher {
		return new LegacyCatalogueMatcher(
			organisations: [
				['id' => 'org-centric', 'name' => 'Centric', 'registrationNumber' => '12345678'],
				['id' => 'org-other', 'name' => 'Andere Leverancier', 'registrationNumber' => null],
				['id' => 'org-twin-a', 'name' => 'Dubbel', 'registrationNumber' => ''],
				['id' => 'org-twin-b', 'name' => 'Dubbel', 'registrationNumber' => ''],
			],
			modules: [
				['id' => 'mod-key2', 'name' => 'Key2Financiën', 'provider' => 'org-centric'],
				['id' => 'mod-other', 'name' => 'Zaaksysteem', 'provider' => ['id' => 'org-other']],
			],
			versions: [
				['id' => 'ver-2026', 'module' => 'mod-key2', 'version' => '2026.1'],
				['id' => 'ver-other', 'module' => ['uuid' => 'mod-other'], 'version' => '3'],
			],
		);
	}//end matcher()

	/**
	 * A known supplier, product and version are matched, with their ids.
	 *
	 * @return void
	 */
	public function testAKnownSupplierProductAndVersionAreMatched(): void {
		$decision = $this->matcher()->decide(row: ['supplierKvk' => '', 'supplierName' => 'Centric', 'product' => 'Key2Financiën', 'version' => '2026.1']);

		$this->assertSame(expected: ['decision' => LegacyCatalogueMatcher::MATCHED, 'id' => 'org-centric'], actual: $decision['supplier']);
		$this->assertSame(expected: ['decision' => LegacyCatalogueMatcher::MATCHED, 'id' => 'mod-key2'], actual: $decision['product']);
		$this->assertSame(expected: ['decision' => LegacyCatalogueMatcher::MATCHED, 'id' => 'ver-2026'], actual: $decision['version']);
	}//end testAKnownSupplierProductAndVersionAreMatched()

	/**
	 * The KvK number wins over the name, and spaces or dots in it do not matter.
	 *
	 * @return void
	 */
	public function testTheKvkNumberMatchesBeforeTheName(): void {
		$row      = ['supplierKvk' => '1234 5678', 'supplierName' => 'Centric B.V.', 'product' => 'Key2Financiën', 'version' => '2026.1'];
		$decision = $this->matcher()->decide(row: $row);

		$this->assertSame(expected: 'org-centric', actual: $decision['supplier']['id']);
		$this->assertSame(expected: 'mod-key2', actual: $decision['product']['id']);
	}//end testTheKvkNumberMatchesBeforeTheName()

	/**
	 * An unknown KvK number falls back to the exact name.
	 *
	 * @return void
	 */
	public function testAnUnknownKvkNumberFallsBackToTheName(): void {
		$row      = ['supplierKvk' => '87654321', 'supplierName' => 'Andere Leverancier', 'product' => 'Zaaksysteem', 'version' => '3'];
		$decision = $this->matcher()->decide(row: $row);

		$this->assertSame(expected: 'org-other', actual: $decision['supplier']['id']);
		$this->assertSame(expected: 'mod-other', actual: $decision['product']['id']);
		$this->assertSame(expected: 'ver-other', actual: $decision['version']['id']);
	}//end testAnUnknownKvkNumberFallsBackToTheName()

	/**
	 * A name that differs only in case is no exact match, so the supplier and all below it are created.
	 *
	 * @return void
	 */
	public function testANameIsMatchedExactlyNotByGuess(): void {
		$decision = $this->matcher()->decide(row: ['supplierKvk' => '', 'supplierName' => 'centric', 'product' => 'Key2Financiën', 'version' => '2026.1']);

		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $decision['supplier']['decision']);
		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $decision['product']['decision']);
		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $decision['version']['decision']);
		$this->assertNull(actual: $decision['supplier']['id']);
	}//end testANameIsMatchedExactlyNotByGuess()

	/**
	 * An unknown product of a known supplier is created, and so is its version.
	 *
	 * @return void
	 */
	public function testAnUnknownProductIsCreated(): void {
		$decision = $this->matcher()->decide(row: ['supplierName' => 'Centric', 'product' => 'Onbekend Pakket', 'version' => '2026.1']);

		$this->assertSame(expected: LegacyCatalogueMatcher::MATCHED, actual: $decision['supplier']['decision']);
		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $decision['product']['decision']);
		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $decision['version']['decision']);
	}//end testAnUnknownProductIsCreated()

	/**
	 * A product is matched within its supplier only: the same name under another supplier is created.
	 *
	 * @return void
	 */
	public function testAProductIsMatchedWithinItsSupplier(): void {
		$decision = $this->matcher()->decide(row: ['supplierName' => 'Andere Leverancier', 'product' => 'Key2Financiën', 'version' => '2026.1']);

		$this->assertSame(expected: 'org-other', actual: $decision['supplier']['id']);
		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $decision['product']['decision']);
	}//end testAProductIsMatchedWithinItsSupplier()

	/**
	 * An unknown version of a known product is created; a numeric version is read as text.
	 *
	 * @return void
	 */
	public function testAnUnknownVersionIsCreated(): void {
		$decision = $this->matcher()->decide(row: ['supplierName' => 'Centric', 'product' => 'Key2Financiën', 'version' => 2027]);
		$this->assertSame(expected: LegacyCatalogueMatcher::MATCHED, actual: $decision['product']['decision']);
		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $decision['version']['decision']);

		$numeric = $this->matcher()->decide(row: ['supplierName' => 'Andere Leverancier', 'product' => 'Zaaksysteem', 'version' => 3]);
		$this->assertSame(expected: 'ver-other', actual: $numeric['version']['id']);
	}//end testAnUnknownVersionIsCreated()

	/**
	 * A name two organisations share matches neither; an empty row creates everything.
	 *
	 * @return void
	 */
	public function testAnAmbiguousOrEmptySupplierIsCreated(): void {
		$twin = $this->matcher()->decide(row: ['supplierName' => 'Dubbel', 'product' => 'X', 'version' => '1']);
		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $twin['supplier']['decision']);

		$empty = $this->matcher()->decide(row: []);
		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $empty['supplier']['decision']);
		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $empty['version']['decision']);
	}//end testAnAmbiguousOrEmptySupplierIsCreated()

	/**
	 * Objects without an id, a supplier or a name are not indexed, and a KvK that is not eight digits is ignored.
	 *
	 * @return void
	 */
	public function testIncompleteObjectsAreNotIndexed(): void {
		$matcher = new LegacyCatalogueMatcher(
			organisations: [
				['name' => 'Zonder Id', 'registrationNumber' => '11112222'],
				['id' => 'org-long', 'name' => 'Lang', 'registrationNumber' => '00000001234567890000'],
			],
			modules: [
				['id' => 'mod-orphan', 'name' => 'Wees'],
				['id' => 'mod-long', 'name' => '', 'provider' => 'org-long'],
			],
			versions: [
				['id' => 'ver-orphan', 'version' => '1'],
			],
		);

		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $matcher->decide(row: ['supplierKvk' => '11112222'])['supplier']['decision']);
		$oin = $matcher->decide(row: ['supplierKvk' => '00000001234567890000']);
		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $oin['supplier']['decision']);

		$long = $matcher->decide(row: ['supplierName' => 'Lang', 'product' => '', 'version' => '1']);
		$this->assertSame(expected: 'org-long', actual: $long['supplier']['id']);
		$this->assertSame(expected: LegacyCatalogueMatcher::CREATE, actual: $long['product']['decision']);
	}//end testIncompleteObjectsAreNotIndexed()
}//end class
