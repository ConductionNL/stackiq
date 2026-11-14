<?php

/**
 * IntegrationLeavesFragmentTest
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

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The register as loadSettings() merges it carries every integration leaf
 * once, and the contract keeps its decision leaf.
 *
 * @covers \OCA\Stackiq\Service\SettingsService
 *
 * @spec openspec/changes/adopt-integration-leaves/tasks.md#1.3
 */
final class IntegrationLeavesFragmentTest extends TestCase {

	/**
	 * The leaf each schema must carry after the merge.
	 *
	 * @var array<string, string>
	 */
	private const LEAVES = [
		'contactPerson'   => 'contacts',
		'organization'    => 'contacts',
		'catalogContract' => 'calendar',
		'moduleVersion'   => 'calendar',
		'software-review' => 'deck',
		'module'          => 'bookmarks',
		'catalogService'  => 'bookmarks',
	];

	/**
	 * The register with every register.d fragment merged in the order loadSettings() uses.
	 *
	 * @return array<mixed>
	 */
	private function mergedRegister(): array {
		$root   = dirname(__DIR__, 3);
		$merged = json_decode((string) file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'), true);
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);

		$fragments = glob($root.'/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$merged = $merge->invoke(null, $merged, json_decode((string) file_get_contents($fragment), true));
		}

		return $merged;
	}//end mergedRegister()

	/**
	 * The contract carries decidesk-decisions and calendar exactly once each.
	 *
	 * @return void
	 */
	public function testTheContractKeepsItsDecisionLeafAndGainsCalendarOnce(): void {
		$linked = $this->mergedRegister()['components']['schemas']['catalogContract']['configuration']['linkedTypes'];

		$this->assertSame(1, count(array_keys($linked, 'decidesk-decisions', true)));
		$this->assertSame(1, count(array_keys($linked, 'calendar', true)));
		$this->assertCount(2, $linked);
	}//end testTheContractKeepsItsDecisionLeafAndGainsCalendarOnce()

	/**
	 * All seven schemas carry their declared leaf, once.
	 *
	 * @return void
	 */
	public function testEverySchemaCarriesItsLeafOnce(): void {
		$schemas = $this->mergedRegister()['components']['schemas'];
		foreach (self::LEAVES as $schema => $leaf) {
			$linked = ($schemas[$schema]['configuration']['linkedTypes'] ?? []);
			$this->assertSame(1, count(array_keys($linked, $leaf, true)), $schema.' carries '.$leaf.' once');
		}
	}//end testEverySchemaCarriesItsLeafOnce()

	/**
	 * Merging the fragment over a contract with the full array would list the
	 * decision leaf twice: lists concatenate, which is why the fragment adds
	 * only the new leaf.
	 *
	 * @return void
	 */
	public function testListsConcatenateSoTheFragmentAddsOnlyTheNewLeaf(): void {
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);
		$base = ['configuration' => ['linkedTypes' => ['decidesk-decisions']]];

		$this->assertSame(
			['decidesk-decisions', 'decidesk-decisions', 'calendar'],
			$merge->invoke(null, $base, ['configuration' => ['linkedTypes' => ['decidesk-decisions', 'calendar']]])['configuration']['linkedTypes']
		);
		$this->assertSame(
			['decidesk-decisions', 'calendar'],
			$merge->invoke(null, $base, ['configuration' => ['linkedTypes' => ['calendar']]])['configuration']['linkedTypes']
		);
	}//end testListsConcatenateSoTheFragmentAddsOnlyTheNewLeaf()
}//end class
