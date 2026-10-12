<?php

/**
 * The rules of the CMDB import that a mapping pack must follow.
 *
 * OpenRegister's validator checks a pack's structure only. The import writes
 * with OpenRegister's RBAC and multitenancy off, so a pack an administrator
 * edits in OpenRegister may only map the fields of its target schema, never
 * the object's metadata or the keys and relations the import sets itself,
 * and must keep the mappings the import matches records on (REQ-CMDB-021).
 * The fields of a schema are read from the register stackiq ships,
 * fragments included (ADR-037).
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

use OCA\Stackiq\Exception\CmdbImportException;

/**
 * Lists the import rules a pack breaks.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 */
class CmdbImportPackRules {
	/**
	 * The fields a pack must map with `required: true`, per target.
	 *
	 * The import keys a module on its APPID and recognises a manufacturer,
	 * a municipality and an owner by name; without them it cannot match.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const REQUIRED_TARGETS = [
		'module' => ['name', 'externalNumber'],
		'manufacturer' => ['name'],
		'municipality' => ['name'],
		'usage' => [],
		'businessOwner' => ['name'],
	];

	/**
	 * The register schema a target writes into; its properties are the fields a pack may map.
	 *
	 * @var array<string, string>
	 */
	private const TARGET_SCHEMAS = [
		'module' => 'module',
		'manufacturer' => 'organization',
		'municipality' => 'organization',
		'usage' => 'usage',
	];

	/**
	 * Targets that are no schema: the fields such a pack may map.
	 *
	 * The business owner is resolved in Nextcloud Contacts by name, with the role on the contact person.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FIXED_FIELDS = ['businessOwner' => ['name', 'role']];

	/**
	 * Fields no pack may write: object metadata, and the keys and relations the import sets itself.
	 *
	 * @var array<int, string>
	 */
	private const MANAGED_FIELDS = ['@self', 'id', 'provider', 'consumer', 'module', 'externalKey', 'businessOwner'];

	/**
	 * Further match keys per target: the import finds an organisation by name and type.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const MATCH_FIELDS = ['manufacturer' => ['type'], 'municipality' => ['type']];

	/**
	 * Defaults a pack must keep as shipped, per target.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const PINNED_DEFAULTS = [
		'manufacturer' => ['type' => 'Supplier'],
		'municipality' => ['type' => 'Municipality'],
	];

	/**
	 * Property names per schema of the shipped register, once read.
	 *
	 * @var array<string, array<int, string>>|null
	 */
	private ?array $schemaFields = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $settingsDirectory Directory of the register file and its fragments; null is the shipped one.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function __construct(
		private ?string $settingsDirectory = null,
	) {
		if ($this->settingsDirectory === null) {
			$this->settingsDirectory = __DIR__ . '/../../Settings';
		}
	}//end __construct()

	/**
	 * The rules a pack breaks, as readable sentences; empty when it follows them all.
	 *
	 * @param string $target The profile target.
	 * @param array<string, mixed> $pack The pack, validated by OpenRegister.
	 * @param array<string, mixed> $profile The decoded import profile.
	 *
	 * @return array<int, string>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the register cannot be read.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function violations(string $target, array $pack, array $profile): array {
		$never = $profile['neverWritten'][$target] ?? [];
		if (is_array($never) === false) {
			$never = [];
		}

		$forbidden = array_merge(self::MANAGED_FIELDS, (self::MATCH_FIELDS[$target] ?? []), array_map('strval', $never));
		$allowed = array_values(array_diff($this->targetFields(target: $target), $forbidden));

		return array_values(
			array_unique(
				array_merge(
					$this->requiredViolations(target: $target, pack: $pack, keyColumn: (string)($profile['keyColumn'] ?? 'APPID')),
					$this->mappingViolations(target: $target, pack: $pack, allowed: $allowed, forbidden: $forbidden),
					$this->defaultViolations(target: $target, pack: $pack, allowed: $allowed, forbidden: $forbidden)
				)
			)
		);
	}//end violations()

	/**
	 * The required mappings a pack lacks.
	 *
	 * @param string $target The target.
	 * @param array<string, mixed> $pack The pack.
	 * @param string $keyColumn The match key column.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function requiredViolations(string $target, array $pack, string $keyColumn): array {
		$violations = [];
		foreach ((self::REQUIRED_TARGETS[$target] ?? []) as $field) {
			if ($this->hasRequiredMapping(pack: $pack, field: $field, keyColumn: $keyColumn) === true) {
				continue;
			}

			if ($field === 'externalNumber') {
				$violations[] = 'the key column "' . $keyColumn . '" must be mapped to "externalNumber" as required';
				continue;
			}

			$violations[] = '"' . $field . '" must be mapped as required';
		}

		return $violations;
	}//end requiredViolations()

	/**
	 * Whether a pack maps a field as required; `externalNumber` only from the key column.
	 *
	 * @param array<string, mixed> $pack The pack.
	 * @param string $field The field.
	 * @param string $keyColumn The match key column.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function hasRequiredMapping(array $pack, string $field, string $keyColumn): bool {
		foreach (($pack['fieldMappings'] ?? []) as $mapping) {
			if (is_array($mapping) === false || ($mapping['target'] ?? null) !== $field || ($mapping['required'] ?? false) !== true) {
				continue;
			}

			if ($field !== 'externalNumber' || ($mapping['source'] ?? null) === $keyColumn) {
				return true;
			}
		}

		return false;
	}//end hasRequiredMapping()

	/**
	 * The mapped targets a pack may not write.
	 *
	 * @param string $target The target.
	 * @param array<string, mixed> $pack The pack.
	 * @param array<int, string> $allowed The fields it may write.
	 * @param array<int, string> $forbidden The fields the import manages.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function mappingViolations(string $target, array $pack, array $allowed, array $forbidden): array {
		$violations = [];
		foreach (($pack['fieldMappings'] ?? []) as $mapping) {
			$field = (string)($mapping['target'] ?? '');
			if (in_array($field, $allowed, true) === false) {
				$violations[] = self::fieldViolation(field: $field, forbidden: in_array($field, $forbidden, true), target: $target);
			}
		}

		return $violations;
	}//end mappingViolations()

	/**
	 * The defaults a pack may not set, and the pinned defaults it changed.
	 *
	 * @param string $target The target.
	 * @param array<string, mixed> $pack The pack.
	 * @param array<int, string> $allowed The fields it may write.
	 * @param array<int, string> $forbidden The fields the import manages.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function defaultViolations(string $target, array $pack, array $allowed, array $forbidden): array {
		$defaults = $pack['defaults'] ?? [];
		if (is_array($defaults) === false) {
			$defaults = [];
		}

		$pinned = (self::PINNED_DEFAULTS[$target] ?? []);
		$violations = [];
		foreach (array_keys($defaults) as $field) {
			$field = (string)$field;
			if (array_key_exists($field, $pinned) === false && in_array($field, $allowed, true) === false) {
				$violations[] = 'default ' . self::fieldViolation(field: $field, forbidden: in_array($field, $forbidden, true), target: $target);
			}
		}

		foreach ($pinned as $field => $value) {
			if (($defaults[$field] ?? null) !== $value) {
				$violations[] = 'default "' . $field . '" must stay "' . $value . '"';
			}
		}

		return $violations;
	}//end defaultViolations()

	/**
	 * Why a field may not be written.
	 *
	 * @param string $field The field.
	 * @param bool $forbidden Whether the import manages it.
	 * @param string $target The target.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private static function fieldViolation(string $field, bool $forbidden, string $target): string {
		if ($forbidden === true) {
			return '"' . $field . '" is set by the import and cannot be mapped';
		}

		return '"' . $field . '" is not a field the ' . $target . ' pack may map';
	}//end fieldViolation()

	/**
	 * The fields a target's pack may map before the forbidden ones are taken out.
	 *
	 * @param string $target The target.
	 *
	 * @return array<int, string>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the register cannot be read.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function targetFields(string $target): array {
		if (isset(self::FIXED_FIELDS[$target]) === true) {
			return self::FIXED_FIELDS[$target];
		}

		$schema = (self::TARGET_SCHEMAS[$target] ?? null);
		if ($schema === null) {
			return [];
		}

		return ($this->schemaFields()[$schema] ?? []);
	}//end targetFields()

	/**
	 * The property names per schema of the register stackiq ships, fragments included.
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the register file cannot be read.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function schemaFields(): array {
		if ($this->schemaFields !== null) {
			return $this->schemaFields;
		}

		$register = $this->decodeJson(file: $this->settingsDirectory . '/softwarecatalogus_register.json');
		if ($register === null) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
				message: 'The stackiq register definition cannot be read, so the CMDB import packs cannot be checked'
			);
		}

		$fragments = glob($this->settingsDirectory . '/register.d/*.json');
		if ($fragments === false) {
			$fragments = [];
		}

		$fields = [];
		foreach (array_merge([$register], array_map(fn (string $file): ?array => $this->decodeJson(file: $file), $fragments)) as $decoded) {
			foreach (($decoded['components']['schemas'] ?? []) as $schema => $definition) {
				$properties = array_keys((array)($definition['properties'] ?? []));
				$fields[(string)$schema] = array_values(array_unique(array_merge(($fields[(string)$schema] ?? []), array_map('strval', $properties))));
			}
		}

		$this->schemaFields = $fields;
		return $fields;
	}//end schemaFields()

	/**
	 * A JSON file decoded to an array, or null when it cannot be read.
	 *
	 * @param string $file The file.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function decodeJson(string $file): ?array {
		$content = false;
		if (is_readable($file) === true) {
			$content = file_get_contents($file);
		}

		if (is_string($content) === false) {
			return null;
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end decodeJson()
}//end class
