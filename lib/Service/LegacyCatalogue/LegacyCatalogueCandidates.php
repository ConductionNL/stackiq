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
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
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
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
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
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
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
	 * @return array{organisations: array<int, array<string, mixed>>, modules: array<int, array<string, mixed>>, versions: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function forRows(array $rows): array {
		$organisations = [];
		foreach (self::distinct(rows: $rows, key: 'supplierName') as $name) {
			$organisations = array_merge($organisations, $this->find(schemaKey: 'organization', field: 'name', value: $name));
		}

		foreach (self::distinct(rows: $rows, key: 'supplierKvk') as $kvk) {
			$organisations = array_merge($organisations, $this->find(schemaKey: 'organization', field: 'registrationNumber', value: $kvk));
		}

		$organisations = self::uniqueById(objects: $organisations);
		$modules = $this->findFor(schemaKey: 'module', field: 'provider', owners: $organisations);
		$versions = $this->findFor(schemaKey: 'moduleVersion', field: 'module', owners: $modules);

		return ['organisations' => $organisations, 'modules' => $modules, 'versions' => $versions];
	}//end forRows()

	/**
	 * The objects of a schema whose field points at one of the owners.
	 *
	 * @param string $schemaKey The schema key.
	 * @param string $field The reference field.
	 * @param array<int, array<string, mixed>> $owners The objects referred to, each with an `id`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private function findFor(string $schemaKey, string $field, array $owners): array {
		$found = [];
		foreach ($owners as $owner) {
			$found = array_merge($found, $this->find(schemaKey: $schemaKey, field: $field, value: (string)$owner['id']));
		}

		return self::uniqueById(objects: $found);
	}//end findFor()

	/**
	 * The objects of a schema whose field holds exactly the value.
	 *
	 * @param string $schemaKey The schema key.
	 * @param string $field The field.
	 * @param string $value The exact value.
	 *
	 * @return array<int, array<string, mixed>> Each object's data with its uuid as `id`.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchBounds::bounded() is the pure query guard every search in lib/ shares.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private function find(string $schemaKey, string $field, string $value): array {
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
			return [];
		}

		$found = [];
		foreach ($results as $entity) {
			$object = self::data(entity: $entity);
			if ($object !== null && self::reference(value: $object[$field] ?? null) === $value) {
				$found[] = $object;
			}
		}

		return $found;
	}//end find()

	/**
	 * An entity's data with its uuid as `id`, or null when it has neither.
	 *
	 * @param mixed $entity The entity OpenRegister returned (ObjectEntity), or an array.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function data(mixed $entity): ?array {
		$data = $entity;
		$uuid = null;
		if (is_object($entity) === true) {
			$data = method_exists($entity, 'getObject') === true ? $entity->getObject() : null;
			$uuid = method_exists($entity, 'getUuid') === true ? $entity->getUuid() : null;
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
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function reference(mixed $value): string {
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
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
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

	/**
	 * The objects without repeats, the first of each id kept.
	 *
	 * @param array<int, array<string, mixed>> $objects The objects, each with an `id`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function uniqueById(array $objects): array {
		$unique = [];
		foreach ($objects as $object) {
			$unique[(string)$object['id']] ??= $object;
		}

		return array_values($unique);
	}//end uniqueById()
}//end class
