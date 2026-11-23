<?php

/**
 * Legacy catalogue matcher
 *
 * Decides, for one row of an export from the previous catalogue, whether its
 * supplier, product and version match an object stackiq already holds or are
 * to be created as a concept.
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

/**
 * Matches a normalised export row against the organisations, modules and versions stackiq holds.
 *
 * A supplier matches on its KvK number (an organisation's `registrationNumber`), then on its exact
 * name; a product matches a module of the matched supplier on its exact name; a version matches a
 * version of the matched module on its version number. Nothing is matched by a fuzzy guess: whatever
 * does not match exactly is created, and everything below a created object is created too.
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
 */
final class LegacyCatalogueMatcher {

	/**
	 * The decision for an object stackiq already holds.
	 */
	public const MATCHED = 'matched';

	/**
	 * The decision for an object to be created as a concept.
	 */
	public const CREATE = 'create';

	/**
	 * Organisation ids by KvK number.
	 *
	 * @var array<string, string>
	 */
	private array $suppliersByKvk = [];

	/**
	 * Organisation ids by exact name; a name two organisations share is left out.
	 *
	 * @var array<string, string|null>
	 */
	private array $suppliersByName = [];

	/**
	 * Module ids by supplier id and exact name.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $modules = [];

	/**
	 * Version ids by module id and version number.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $versions = [];

	/**
	 * Constructor: indexes the objects a row can match.
	 *
	 * @param array<int, array<string, mixed>> $organisations Organisations with `id`, `name` and `registrationNumber`.
	 * @param array<int, array<string, mixed>> $modules       Modules with `id`, `name` and `provider`.
	 * @param array<int, array<string, mixed>> $versions      Module versions with `id`, `module` and `version`.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function __construct(array $organisations, array $modules, array $versions) {
		foreach ($organisations as $organisation) {
			$this->indexOrganisation(organisation: $organisation);
		}

		foreach ($modules as $module) {
			$moduleId   = self::reference(value: $module['id'] ?? null);
			$supplierId = self::reference(value: $module['provider'] ?? null);
			$name       = self::text(value: $module['name'] ?? null);
			if ($moduleId === '' || $supplierId === '' || $name === '') {
				continue;
			}

			$this->modules[$supplierId][$name] ??= $moduleId;
		}

		foreach ($versions as $version) {
			$versionId = self::reference(value: $version['id'] ?? null);
			$moduleId  = self::reference(value: $version['module'] ?? null);
			$number    = self::text(value: $version['version'] ?? null);
			if ($versionId === '' || $moduleId === '' || $number === '') {
				continue;
			}

			$this->versions[$moduleId][$number] ??= $versionId;
		}
	}//end __construct()

	/**
	 * The decisions for one row: supplier, product and version, each matched (with the id) or create.
	 *
	 * @param array<string, mixed> $row The normalised row: `supplierKvk`, `supplierName`, `product` and `version`.
	 *
	 * @return array{supplier: array{decision: string, id: string|null}, product: array{decision: string, id: string|null}, version: array{decision: string, id: string|null}}
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function decide(array $row): array {
		$supplierId = $this->matchSupplier(kvk: self::kvk(value: $row['supplierKvk'] ?? null), name: self::text(value: $row['supplierName'] ?? null));

		$productId = null;
		if ($supplierId !== null) {
			$productId = ($this->modules[$supplierId][self::text(value: $row['product'] ?? null)] ?? null);
		}

		$versionId = null;
		if ($productId !== null) {
			$versionId = ($this->versions[$productId][self::text(value: $row['version'] ?? null)] ?? null);
		}

		return [
			'supplier' => self::decision(id: $supplierId),
			'product'  => self::decision(id: $productId),
			'version'  => self::decision(id: $versionId),
		];
	}//end decide()

	/**
	 * Adds one organisation to the KvK and name indexes.
	 *
	 * @param array<string, mixed> $organisation The organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private function indexOrganisation(array $organisation): void {
		$organisationId = self::reference(value: $organisation['id'] ?? null);
		if ($organisationId === '') {
			return;
		}

		$kvk = self::kvk(value: $organisation['registrationNumber'] ?? null);
		if ($kvk !== '') {
			$this->suppliersByKvk[$kvk] ??= $organisationId;
		}

		$name = self::text(value: $organisation['name'] ?? null);
		if ($name === '') {
			return;
		}

		if (array_key_exists($name, $this->suppliersByName) === true && $this->suppliersByName[$name] !== $organisationId) {
			// Two organisations with this name: a name alone cannot say which one is meant.
			$this->suppliersByName[$name] = null;
			return;
		}

		$this->suppliersByName[$name] = $organisationId;
	}//end indexOrganisation()

	/**
	 * The id of the organisation a supplier matches: on KvK number first, then on exact name.
	 *
	 * @param string $kvk  The supplier's KvK number, or ''.
	 * @param string $name The supplier's name, or ''.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private function matchSupplier(string $kvk, string $name): ?string {
		if ($kvk !== '' && isset($this->suppliersByKvk[$kvk]) === true) {
			return $this->suppliersByKvk[$kvk];
		}

		if ($name === '') {
			return null;
		}

		return ($this->suppliersByName[$name] ?? null);
	}//end matchSupplier()

	/**
	 * One decision: matched with the id, or create.
	 *
	 * @param string|null $id The matched id, or null.
	 *
	 * @return array{decision: string, id: string|null}
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function decision(?string $id): array {
		if ($id === null) {
			return ['decision' => self::CREATE, 'id' => null];
		}

		return ['decision' => self::MATCHED, 'id' => $id];
	}//end decision()

	/**
	 * A related object's id, whether stored as a string or as an object with `id` or `uuid`.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? $value['uuid'] ?? null);
		}

		return self::text(value: $value);
	}//end reference()

	/**
	 * A KvK number: eight digits once spaces and dots are removed, else ''.
	 *
	 * @param mixed $value The stored or exported value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function kvk(mixed $value): string {
		$digits = str_replace([' ', '.'], '', self::text(value: $value));
		if (preg_match('/^\d{8}$/', $digits) !== 1) {
			return '';
		}

		return $digits;
	}//end kvk()

	/**
	 * A scalar as trimmed text; anything else as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function text(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (string) $value;
		}

		return '';
	}//end text()
}//end class
