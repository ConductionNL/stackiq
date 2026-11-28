<?php

/**
 * Legacy catalogue candidates
 *
 * Reads, for the rows of one export from the previous catalogue, the
 * organisations, modules and versions stackiq holds that those rows can
 * match, so LegacyCatalogueMatcher decides without reading the whole catalogue.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\LegacyCatalogue
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

namespace OCA\Stackiq\Service\LegacyCatalogue;

use OCA\Stackiq\Service\SearchBounds;

/**
 * Finds the match candidates of a set of rows: organisations by exact name or KvK number, the modules those
 * organisations provide, and the versions of those modules.
 *
 * Every search is read with RBAC and multitenancy off: matching an existing supplier or product must see the whole
 * catalogue, and only ids, names and version numbers leave this class. A filter OpenRegister cannot apply never
 * turns into a match: every result is checked against its filter again.
 *
 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-2
 */
final class LegacyCatalogueCandidates {

	/**
	 * The most objects one search returns.
	 */
	private const LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param object             $objectService OpenRegister's object service, with `searchObjects`.
	 * @param int                $register      The stackiq register id.
	 * @param array<string, int> $schemas       Schema ids by key: `organization`, `module` and `moduleVersion`.
	 *
	 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly int $register,
		private readonly array $schemas,
	) {
	}//end __construct()

	/**
	 * The candidates for the rows, in the shape LegacyCatalogueMatcher's constructor takes.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows with `supplierKvk` and `supplierName`.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The `organisations`, `modules` and `versions`.
	 *
	 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function forRows(array $rows): array {
		$filters = array_merge(
			self::filters(field: 'name', values: self::distinct(rows: $rows, key: 'supplierName')),
			self::filters(field: 'registrationNumber', values: self::distinct(rows: $rows, key: 'supplierKvk'))
		);
		$organisations = $this->find(schemaKey: 'organization', filters: $filters);
		$modules = $this->find(schemaKey: 'module', filters: self::filters(field: 'provider', values: array_column($organisations, 'id')));
		$versions = $this->find(schemaKey: 'moduleVersion', filters: self::filters(field: 'module', values: array_column($modules, 'id')));

		return ['organisations' => $organisations, 'modules' => $modules, 'versions' => $versions];
	}//end forRows()

	/**
	 * One exact-field filter per value.
	 *
	 * @param string $field The field.
	 * @param array<int, mixed> $values The values.
	 *
	 * @return array<int, array{0: string, 1: string}> Field and value pairs.
	 *
	 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function filters(string $field, array $values): array {
		return array_map(static fn (mixed $value): array => [$field, (string)$value], $values);
	}//end filters()

	/**
	 * The objects of a schema that match any of the exact-field filters, each once.
	 *
	 * @param string $schemaKey The schema key.
	 * @param array<int, array{0: string, 1: string}> $filters Field and value pairs; one search each.
	 *
	 * @return array<int, array<string, mixed>> Each object's data with its uuid as `id`.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchBounds::bounded() is the pure query guard every search in lib/ shares.
	 *
	 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private function find(string $schemaKey, array $filters): array {
		$found = [];
		foreach ($filters as [$field, $value]) {
			$results = $this->objectService->searchObjects(
				query: SearchBounds::bounded(
					query: [
						'@self' => ['register' => $this->register, 'schema' => ($this->schemas[$schemaKey] ?? 0)],
						$field => $value,
						'_limit' => self::LIMIT,
					]
				),
				_rbac: false,
				_multitenancy: false
			);
			if (is_array($results) === false) {
				continue;
			}

			foreach ($results as $entity) {
				$object = self::data(entity: $entity);
				if ($object !== null && self::reference(value: $object[$field] ?? null) === $value) {
					$found[$object['id']] ??= $object;
				}
			}
		}

		return array_values($found);
	}//end find()

	/**
	 * An entity's data with its uuid as `id`, or null when it has neither.
	 *
	 * @param mixed $entity The entity OpenRegister returned (ObjectEntity), or an array.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function data(mixed $entity): ?array {
		$data = $entity;
		$uuid = null;
		if (is_object($entity) === true) {
			$data = null;
			if (method_exists($entity, 'getObject') === true) {
				$data = $entity->getObject();
			}

			if (method_exists($entity, 'getUuid') === true) {
				$uuid = $entity->getUuid();
			}
		}

		if (is_array($data) === false) {
			return null;
		}

		$uuid = ($uuid ?? $data['id'] ?? ($data['@self']['id'] ?? null));
		if (is_string($uuid) === false || $uuid === '') {
			return null;
		}

		$data['id'] = $uuid;
		return $data;
	}//end data()

	/**
	 * A reference or scalar as a trimmed string; a nested object by its `id`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-3
	 */
	public static function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? $value['uuid'] ?? null);
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end reference()

	/**
	 * The distinct non-empty values of one key of the rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param string $key The key.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function distinct(array $rows, string $key): array {
		$values = [];
		foreach ($rows as $row) {
			$value = self::reference(value: $row[$key] ?? null);
			if ($value !== '') {
				$values[$value] = true;
			}
		}

		return array_map('strval', array_keys($values));
	}//end distinct()
}//end class
