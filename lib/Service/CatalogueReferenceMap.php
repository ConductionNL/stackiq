<?php

/**
 * CatalogueReferenceMap
 *
 * Every place in the register that references an application (`module`), a
 * service (`catalogService`) or an organisation (`organization`), as one list
 * for the two merges that must follow them: the relinker that runs after
 * OpenRegister merges two applications or services, and stackiq's own
 * organisation merge (operations-record-reconciliation D3, D4).
 * `CatalogueReferenceMapTest` reads the merged register and fails when a
 * `$ref` to one of these schemas is missing here.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * The register's references per target schema.
 *
 * @spec openspec/changes/operations-record-reconciliation/tasks.md#task-2-share-one-reference-map-and-complete-the-organisation-merge
 */
final class CatalogueReferenceMap {

	/**
	 * Per target schema, the schemas and fields that reference it; `list`
	 * marks a field holding a list of uuids instead of one uuid.
	 *
	 * @var array<string, array<int, array{schema: string, field: string, list: bool}>>
	 */
	public const REFERENCES = [
		'module' => [
			['schema' => 'suite', 'field' => 'applications', 'list' => true],
			['schema' => 'catalogService', 'field' => 'modules', 'list' => true],
			['schema' => 'vulnerability', 'field' => 'modules', 'list' => true],
			['schema' => 'usage', 'field' => 'module', 'list' => false],
			['schema' => 'usage', 'field' => 'plannedReplacement', 'list' => false],
			['schema' => 'connection', 'field' => 'moduleA', 'list' => false],
			['schema' => 'connection', 'field' => 'moduleB', 'list' => false],
			['schema' => 'connection', 'field' => 'realisedWithIntermediaryModule', 'list' => false],
			['schema' => 'software-review', 'field' => 'modules', 'list' => true],
			['schema' => 'compliancy', 'field' => 'module', 'list' => false],
			['schema' => 'moduleVersion', 'field' => 'module', 'list' => false],
			['schema' => 'aiSystem', 'field' => 'module', 'list' => false],
			['schema' => 'maintenanceWindow', 'field' => 'module', 'list' => false],
		],
		'catalogService' => [
			['schema' => 'usage', 'field' => 'diensten', 'list' => true],
			['schema' => 'catalogContract', 'field' => 'service', 'list' => false],
			['schema' => 'connection', 'field' => 'service', 'list' => false],
			['schema' => 'software-review', 'field' => 'diensten', 'list' => true],
			['schema' => 'module', 'field' => 'diensten', 'list' => true],
		],
		'organization' => [
			['schema' => 'usage', 'field' => 'consumer', 'list' => false],
			['schema' => 'usage', 'field' => 'participants', 'list' => true],
			['schema' => 'contactPerson', 'field' => 'organization', 'list' => false],
			['schema' => 'connection', 'field' => 'provider', 'list' => false],
			['schema' => 'module', 'field' => 'provider', 'list' => false],
			['schema' => 'catalogService', 'field' => 'provider', 'list' => false],
			// Not walked by the organisation merge before this map.
			['schema' => 'usage', 'field' => 'provider', 'list' => false],
			['schema' => 'organization', 'field' => 'deelnames', 'list' => true],
			['schema' => 'organization', 'field' => 'participants', 'list' => true],
			['schema' => 'catalogContract', 'field' => 'supplier', 'list' => false],
			['schema' => 'model', 'field' => 'organizations', 'list' => true],
			['schema' => 'aiSystem', 'field' => 'provider', 'list' => false],
		],
	];

	/**
	 * The count key the organisation merge reports a reference under. The
	 * keys it reported before this map keep their names, so the merge's
	 * result keeps its shape; every other reference reports under its schema
	 * and field.
	 *
	 * @var array<string, string>
	 */
	private const ORGANISATION_COUNT_KEYS = [
		'usage.consumer' => 'usage',
		'usage.participants' => 'usage',
		'contactPerson.organization' => 'contactPerson',
		'connection.provider' => 'aanbod',
		'module.provider' => 'module',
		'catalogService.provider' => 'catalogService',
	];

	/**
	 * The references to one schema, or none for a schema the map does not hold.
	 *
	 * @param string $schema The referenced schema: module, catalogService or organization.
	 *
	 * @return array<int, array{schema: string, field: string, list: bool}>
	 *
	 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public static function referencesTo(string $schema): array {
		return (self::REFERENCES[$schema] ?? []);
	}//end referencesTo()

	/**
	 * The organisation references as the organisation merge walks them: per
	 * count key one schema with at most one scalar field and one list field.
	 *
	 * @return array<string, array{field: string, arrayField: string|null, schema?: string}>
	 *
	 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-005-the-organisation-merge-must-re-point-every-reference-to-the-merged-organisation
	 */
	public static function organisationRelationTypes(): array {
		$types = [];
		foreach (self::REFERENCES['organization'] as $reference) {
			$key = self::organisationCountKey(reference: $reference);
			$type = ($types[$key] ?? ['field' => '', 'arrayField' => null]);
			if ($reference['list'] === true) {
				$type['arrayField'] = $reference['field'];
			} else {
				$type['field'] = $reference['field'];
			}

			if ($reference['schema'] !== $key) {
				$type['schema'] = $reference['schema'];
			}

			$types[$key] = $type;
		}

		return $types;
	}//end organisationRelationTypes()

	/**
	 * The count key of one organisation reference.
	 *
	 * @param array{schema: string, field: string, list: bool} $reference The reference.
	 *
	 * @return string
	 */
	private static function organisationCountKey(array $reference): string {
		$path = $reference['schema'] . '.' . $reference['field'];

		return (self::ORGANISATION_COUNT_KEYS[$path] ?? ($reference['schema'] . ucfirst($reference['field'])));
	}//end organisationCountKey()
}//end class
