<?php

/**
 * The catalogue reference map covers every reference in the register.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-005-the-organisation-merge-must-re-point-every-reference-to-the-merged-organisation
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\CatalogueReferenceMap;
use OCA\Stackiq\Tests\Unit\Settings\ReconciliationDeclarationTest;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Settings/ReconciliationDeclarationTest.php';

/**
 * Reads the merged register and fails on a reference the map does not list.
 */
class CatalogueReferenceMapTest extends TestCase {

	/**
	 * Every `$ref` in the merged register to one target schema, as
	 * schema => field => is-array.
	 *
	 * @param string $target The referenced schema.
	 *
	 * @return array<string, array<string, bool>> The references.
	 */
	private function registerReferencesTo(string $target): array {
		$found = [];
		foreach (ReconciliationDeclarationTest::mergedRegister()['components']['schemas'] as $schema => $definition) {
			foreach (($definition['properties'] ?? []) as $field => $property) {
				$ref = ($property['$ref'] ?? '');
				if ($ref === '') {
					// An array property may carry an empty top-level `$ref` next to its items' one (model.organizations).
					$ref = ($property['items']['$ref'] ?? null);
				}
				if ($ref !== '#/components/schemas/' . $target) {
					continue;
				}

				$found[$schema][$field] = (($property['type'] ?? null) === 'array');
			}
		}

		ksort($found);
		return $found;
	}//end registerReferencesTo()

	/**
	 * The map sorted for comparison.
	 *
	 * @param string $target The referenced schema.
	 *
	 * @return array<string, array<string, bool>> The map entry.
	 */
	private function mapped(string $target): array {
		$map = CatalogueReferenceMap::referencesTo(schema: $target);
		ksort($map);
		return $map;
	}//end mapped()

	/**
	 * Every reference to an application, a service or an organisation is listed,
	 * with the right shape, and nothing else.
	 *
	 * @return void
	 */
	public function testTheMapListsEveryReferenceInTheRegister(): void {
		foreach (['module', 'catalogService', 'organization'] as $target) {
			$this->assertSame(
				$this->registerReferencesTo(target: $target),
				$this->mapped(target: $target),
				'CatalogueReferenceMap must list every $ref to ' . $target
			);
		}
	}//end testTheMapListsEveryReferenceInTheRegister()

	/**
	 * The organisation references the merge used to miss are in the map.
	 *
	 * @return void
	 */
	public function testTheSixMissingOrganisationReferencesAreListed(): void {
		$map = CatalogueReferenceMap::referencesTo(schema: 'organization');
		$this->assertFalse($map['module']['provider']);
		$this->assertFalse($map['catalogService']['provider']);
		$this->assertFalse($map['usage']['provider']);
		$this->assertTrue($map['organization']['deelnames']);
		$this->assertTrue($map['organization']['participants']);
		$this->assertTrue($map['model']['organizations']);
	}//end testTheSixMissingOrganisationReferencesAreListed()

	/**
	 * A scalar, an array and an object-shaped reference move; other fields stay.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public function testRewriteMovesScalarArrayAndObjectReferences(): void {
		[$data, $moved] = CatalogueReferenceMap::rewrite(
			data: ['name' => 'Koppeling', 'moduleA' => 'dup', 'moduleB' => ['id' => 'dup'], 'realisedWithIntermediaryModule' => 'other'],
			fields: ['moduleA' => false, 'moduleB' => false, 'realisedWithIntermediaryModule' => false],
			from: 'dup',
			to: 'orig'
		);
		$this->assertSame(['name' => 'Koppeling', 'moduleA' => 'orig', 'moduleB' => 'orig', 'realisedWithIntermediaryModule' => 'other'], $data);
		$this->assertSame(['moduleA', 'moduleB'], $moved);
	}//end testRewriteMovesScalarArrayAndObjectReferences()

	/**
	 * An array that held both keeps the survivor once, in its first place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public function testAnArrayDoesNotGetTheSurvivorTwice(): void {
		[$data, $moved] = CatalogueReferenceMap::rewrite(
			data: ['modules' => ['orig', 'x', 'dup', ['id' => 'y']]],
			fields: ['modules' => true],
			from: 'dup',
			to: 'orig'
		);
		$this->assertSame(['orig', 'x', ['id' => 'y']], $data['modules']);
		$this->assertSame(['modules'], $moved);
	}//end testAnArrayDoesNotGetTheSurvivorTwice()

	/**
	 * Nothing to move leaves the data as it was.
	 *
	 * @return void
	 */
	public function testNoReferenceMovesNothing(): void {
		$data = ['modules' => ['x'], 'module' => 'y'];
		$this->assertSame([$data, []], CatalogueReferenceMap::rewrite(data: $data, fields: ['modules' => true, 'module' => false], from: 'dup', to: 'orig'));
	}//end testNoReferenceMovesNothing()
}//end class
