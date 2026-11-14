<?php

/**
 * CatalogueReferenceMapTest
 *
 * operations-record-reconciliation D4: the reference map lists every `$ref`
 * in the merged register to an application, a service or an organisation, so
 * a new reference cannot be forgotten by the merges again.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-005-the-organisation-merge-must-re-point-every-reference-to-the-merged-organisation
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\CatalogueReferenceMap;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The reference map against the register.
 */
class CatalogueReferenceMapTest extends TestCase {
	/**
	 * The register with every fragment merged over it, the way the import
	 * reads it, keeping the highest version of each schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function mergedRegister(): array {
		$root = dirname(__DIR__, 3);
		$register = json_decode((string)file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);
		$highest = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		$highest->setAccessible(true);
		$fragments = glob($root.'/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			$register = $highest->invoke(null, $register, $merge->invoke(null, $register, $overlay));
		}

		return $register;
	}//end mergedRegister()

	/**
	 * Every property of the register that references the target, as
	 * `schema.field` => whether it holds a list.
	 *
	 * @param array<string, mixed> $schemas The register's schemas.
	 * @param string               $target  The referenced schema.
	 *
	 * @return array<string, bool>
	 */
	private static function registerReferences(array $schemas, string $target): array {
		$ref = '#/components/schemas/'.$target;
		$found = [];
		foreach ($schemas as $slug => $schema) {
			foreach (($schema['properties'] ?? []) as $field => $property) {
				$list = ($property['items']['$ref'] ?? null) === $ref;
				if ($list === true || ($property['$ref'] ?? null) === $ref) {
					$found[$slug.'.'.$field] = $list || ($property['type'] ?? null) === 'array';
				}
			}
		}

		ksort($found);
		return $found;
	}//end registerReferences()

	/**
	 * The map lists exactly the register's references, each with its shape.
	 *
	 * @return void
	 */
	public function testTheMapCoversEveryReferenceInTheRegister(): void {
		$schemas = self::mergedRegister()['components']['schemas'];
		foreach (['module', 'catalogService', 'organization'] as $target) {
			$mapped = [];
			foreach (CatalogueReferenceMap::referencesTo(schema: $target) as $reference) {
				$mapped[$reference['schema'].'.'.$reference['field']] = $reference['list'];
			}

			ksort($mapped);
			$this->assertSame(self::registerReferences(schemas: $schemas, target: $target), $mapped, 'references to '.$target);
		}
	}//end testTheMapCoversEveryReferenceInTheRegister()

	/**
	 * The organisation merge keeps the count keys and shapes it reported
	 * before the map, in the same order, and adds the references it missed.
	 *
	 * @return void
	 */
	public function testTheOrganisationMergeKeepsItsCountKeys(): void {
		$types = CatalogueReferenceMap::organisationRelationTypes();

		$this->assertSame(
			[
				'usage' => ['field' => 'consumer', 'arrayField' => 'participants'],
				'contactPerson' => ['field' => 'organization', 'arrayField' => null],
				'aanbod' => ['field' => 'provider', 'arrayField' => null, 'schema' => 'connection'],
				'module' => ['field' => 'provider', 'arrayField' => null],
				'catalogService' => ['field' => 'provider', 'arrayField' => null],
			],
			array_slice($types, 0, 5, true)
		);
		$this->assertSame(
			[
				'usageProvider' => ['field' => 'provider', 'arrayField' => null, 'schema' => 'usage'],
				'organizationDeelnames' => ['field' => '', 'arrayField' => 'deelnames', 'schema' => 'organization'],
				'organizationParticipants' => ['field' => '', 'arrayField' => 'participants', 'schema' => 'organization'],
				'catalogContractSupplier' => ['field' => 'supplier', 'arrayField' => null, 'schema' => 'catalogContract'],
				'modelOrganizations' => ['field' => '', 'arrayField' => 'organizations', 'schema' => 'model'],
				'aiSystemProvider' => ['field' => 'provider', 'arrayField' => null, 'schema' => 'aiSystem'],
			],
			array_slice($types, 5, null, true)
		);
	}//end testTheOrganisationMergeKeepsItsCountKeys()

	/**
	 * A schema the map does not hold has no references.
	 *
	 * @return void
	 */
	public function testAnUnmappedSchemaHasNoReferences(): void {
		$this->assertSame([], CatalogueReferenceMap::referencesTo(schema: 'usage'));
	}//end testAnUnmappedSchemaHasNoReferences()
}//end class
