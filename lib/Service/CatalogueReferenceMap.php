<?php

/**
 * Catalogue Reference Map.
 *
 * Which fields in the catalogue register point at an application, a service
 * or an organisation. One list for the two merges that must re-point them:
 * the relinker after OpenRegister merges two applications or services, and
 * stackiq's own organisation merge. tests/Unit/Service/CatalogueReferenceMapTest.php
 * reads the merged register and fails on a reference this map does not list.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-005-the-organisation-merge-must-re-point-every-reference-to-the-merged-organisation
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * The references per target schema, as referencing schema => field => whether
 * the field holds a list.
 */
final class CatalogueReferenceMap {

	/**
	 * The references per target schema.
	 *
	 * @var array<string, array<string, array<string, bool>>>
	 */
	private const REFERENCES = [
		'module' => [
			'suite' => ['applications' => true],
			'catalogService' => ['modules' => true],
			'vulnerability' => ['modules' => true],
			'usage' => ['module' => false, 'plannedReplacement' => false],
			'connection' => ['moduleA' => false, 'moduleB' => false, 'realisedWithIntermediaryModule' => false],
			'software-review' => ['modules' => true],
			'compliancy' => ['module' => false],
			'moduleVersion' => ['module' => false],
			'aiSystem' => ['module' => false],
			'maintenanceWindow' => ['module' => false],
		],
		'catalogService' => [
			'usage' => ['diensten' => true],
			'catalogContract' => ['service' => false],
			'connection' => ['service' => false],
			'software-review' => ['diensten' => true],
			'module' => ['diensten' => true],
		],
		'organization' => [
			'usage' => ['consumer' => false, 'provider' => false, 'participants' => true],
			'contactPerson' => ['organization' => false],
			'connection' => ['provider' => false],
			'module' => ['provider' => false],
			'catalogService' => ['provider' => false],
			'organization' => ['deelnames' => true, 'participants' => true],
			'model' => ['organizations' => true],
			'aiSystem' => ['provider' => false],
		],
	];

	/**
	 * The references to one schema.
	 *
	 * @param string $schema The referenced schema slug (module, catalogService or organization).
	 *
	 * @return array<string, array<string, bool>> Referencing schema => field => holds a list.
	 *
	 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-005-the-organisation-merge-must-re-point-every-reference-to-the-merged-organisation
	 */
	public static function referencesTo(string $schema): array {
		return (self::REFERENCES[$schema] ?? []);
	}//end referencesTo()

	/**
	 * Replace one referenced uuid by another in the given fields. A reference
	 * may be a uuid or an object with an id or uuid; a moved one becomes the
	 * new uuid. A list that already holds the new uuid keeps it once.
	 *
	 * @param array<string, mixed> $data   The object data.
	 * @param array<string, bool>  $fields Field => holds a list.
	 * @param string               $from   The uuid to replace.
	 * @param string               $to     The uuid that replaces it.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, string>} The data and the fields that moved.
	 *
	 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public static function rewrite(array $data, array $fields, string $from, string $to): array {
		$moved = [];
		foreach ($fields as $field => $isList) {
			$value = ($data[$field] ?? null);
			if ($isList === true && is_array($value) === true && array_is_list($value) === true) {
				$list = self::rewriteList(list: $value, from: $from, to: $to);
				if ($list !== null) {
					$data[$field] = $list;
					$moved[]       = $field;
				}

				continue;
			}

			if (self::referenceId(value: $value) === $from) {
				$data[$field] = $to;
				$moved[]       = $field;
			}
		}//end foreach

		return [$data, $moved];
	}//end rewrite()

	/**
	 * Replace a uuid in a list, keeping the new uuid once.
	 *
	 * @param array<int, mixed> $list The list.
	 * @param string            $from The uuid to replace.
	 * @param string            $to   The uuid that replaces it.
	 *
	 * @return array<int, mixed>|null The new list, or null when the uuid is not in it.
	 *
	 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	private static function rewriteList(array $list, string $from, string $to): ?array {
		$ids = array_map(static fn (mixed $entry): ?string => self::referenceId(value: $entry), $list);
		if (in_array($from, $ids, true) === false) {
			return null;
		}

		$result = [];
		$hasTo  = false;
		foreach ($list as $index => $entry) {
			$id = $ids[$index];
			if ($id === $from || $id === $to) {
				if ($hasTo === false) {
					$result[] = $to;
					$hasTo    = true;
				}

				continue;
			}

			$result[] = $entry;
		}

		return $result;
	}//end rewriteList()

	/**
	 * The uuid of a reference: a string, or an object's id or uuid.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string|null The uuid.
	 *
	 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public static function referenceId(mixed $value): ?string {
		return MaintenanceRecipientService::referenceId(value: $value);
	}//end referenceId()
}//end class
