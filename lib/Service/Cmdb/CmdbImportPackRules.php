<?php

/**
 * The rules of the CMDB import that a mapping pack must follow.
 *
 * OpenRegister's validator checks a pack's structure only. The import writes
 * with OpenRegister's RBAC and multitenancy off, so a pack an administrator
 * edits in OpenRegister may map any plain property of its target schema in
 * the register stackiq ships, fragments included (ADR-037), but never the
 * object's metadata, the keys and relations the import sets itself, any
 * other relation, a file, a field the register hides from forms or calculates
 * (other subsystems own those: the service desk exchange, the derivations),
 * the publication dates or the fields only an administrator or the catalogue
 * sets, and must keep the mappings the import matches records on
 * (REQ-CMDB-021).
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
	 * The register schema a target writes into; a field a pack maps must be a plain property of it.
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
	 * The fields a pack may map or default for a target that writes into no schema.
	 *
	 * The business owner is resolved in Nextcloud Contacts by name, with the
	 * role on the contact person, so it has no schema to read its fields from.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FIELDS_WITHOUT_SCHEMA = [
		'businessOwner' => ['name', 'role'],
	];

	/**
	 * Fields no pack may write: object metadata, and the keys and relations the import sets itself.
	 *
	 * @var array<int, string>
	 */
	private const MANAGED_FIELDS = ['@self', 'id', 'provider', 'consumer', 'module', 'externalKey', 'businessOwner'];

	/**
	 * Fields only the catalogue or an administrator sets: the publication dates and the admin fields.
	 *
	 * The pinned defaults below may still set some of them, as shipped.
	 *
	 * @var array<int, string>
	 */
	private const PROTECTED_FIELDS = [
		'publicationDate',
		'depublicationDate',
		'registeredBy',
		'registrationStatus',
		'mergedInto',
		'contactsUid',
		'pki',
		'xml',
	];

	/**
	 * Further protected fields per target: an organisation's approval status, a module's type and the version the service desk records as installed.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const PROTECTED_TARGET_FIELDS = [
		'module' => ['type'],
		'manufacturer' => ['status'],
		'municipality' => ['status'],
		'usage' => ['installedVersion'],
	];

	/**
	 * Further match keys per target: the import finds an organisation by name and type.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const MATCH_FIELDS = ['manufacturer' => ['type'], 'municipality' => ['type']];

	/**
	 * Defaults a pack must keep as shipped, per target.
	 *
	 * The organisation type is a match key; the status and who registered it
	 * are admin fields that a pack may set only to these values.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const PINNED_DEFAULTS = [
		'manufacturer' => ['type' => 'Supplier', 'status' => 'Active', 'registeredBy' => 'Supplier'],
		'municipality' => ['type' => 'Municipality', 'status' => 'Active'],
	];

	/**
	 * Per schema of the shipped register, once read: each property name and why no pack may write it.
	 *
	 * The reason is `relation` (a relation or a file), `system` (hidden from
	 * forms or calculated: another subsystem owns it) or an empty string
	 * (a plain, writable property).
	 *
	 * @var array<string, array<string, string>>|null
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

		$refused = [
			'managed' => array_merge(self::MANAGED_FIELDS, (self::MATCH_FIELDS[$target] ?? []), array_map('strval', $never)),
			'protected' => array_merge(self::PROTECTED_FIELDS, (self::PROTECTED_TARGET_FIELDS[$target] ?? [])),
			'relation' => $this->fieldsWithReason(target: $target, reason: 'relation'),
			'system' => $this->fieldsWithReason(target: $target, reason: 'system'),
		];
		$allowed = array_values(array_diff($this->targetFields(target: $target), ...array_values($refused)));

		return array_values(
			array_unique(
				array_merge(
					$this->requiredViolations(target: $target, pack: $pack, keyColumn: (string)($profile['keyColumn'] ?? 'APPID')),
					$this->mappingViolations(target: $target, pack: $pack, allowed: $allowed, refused: $refused),
					$this->defaultViolations(target: $target, pack: $pack, allowed: $allowed, refused: $refused)
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
	 * @param array<string, array<int, string>> $refused The refused fields, per reason.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function mappingViolations(string $target, array $pack, array $allowed, array $refused): array {
		$violations = [];
		foreach (($pack['fieldMappings'] ?? []) as $mapping) {
			$field = (string)($mapping['target'] ?? '');
			if (in_array($field, $allowed, true) === false) {
				$violations[] = self::fieldViolation(field: $field, refused: $refused, target: $target);
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
	 * @param array<string, array<int, string>> $refused The refused fields, per reason.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function defaultViolations(string $target, array $pack, array $allowed, array $refused): array {
		$defaults = $pack['defaults'] ?? [];
		if (is_array($defaults) === false) {
			$defaults = [];
		}

		$pinned = (self::PINNED_DEFAULTS[$target] ?? []);
		$violations = [];
		foreach (array_keys($defaults) as $field) {
			$field = (string)$field;
			if (array_key_exists($field, $pinned) === false && in_array($field, $allowed, true) === false) {
				$violations[] = 'default ' . self::fieldViolation(field: $field, refused: $refused, target: $target);
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
	 * @param array<string, array<int, string>> $refused The refused fields, per reason.
	 * @param string $target The target.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private static function fieldViolation(string $field, array $refused, string $target): string {
		if (in_array($field, $refused['managed'], true) === true) {
			return '"' . $field . '" is set by the import and cannot be mapped';
		}

		if (in_array($field, $refused['protected'], true) === true) {
			return '"' . $field . '" is set outside the import and cannot be mapped';
		}

		if (in_array($field, $refused['system'], true) === true) {
			return '"' . $field . '" is set outside the import and cannot be mapped';
		}

		if (in_array($field, $refused['relation'], true) === true) {
			return '"' . $field . '" is a relation or a file and cannot be mapped';
		}

		return '"' . $field . '" is not a field the ' . $target . ' pack may map';
	}//end fieldViolation()

	/**
	 * The properties of a target's schema, or the fixed fields of a target without one.
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
		$schema = (self::TARGET_SCHEMAS[$target] ?? null);
		if ($schema === null) {
			return (self::FIELDS_WITHOUT_SCHEMA[$target] ?? []);
		}

		return array_map('strval', array_keys(($this->schemaFields()[$schema] ?? [])));
	}//end targetFields()

	/**
	 * The properties of a target's schema that no pack may write for one reason.
	 *
	 * @param string $target The target.
	 * @param string $reason `relation` or `system`.
	 *
	 * @return array<int, string>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the register cannot be read.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function fieldsWithReason(string $target, string $reason): array {
		$schema = (self::TARGET_SCHEMAS[$target] ?? null);
		if ($schema === null) {
			return [];
		}

		$fields = array_filter(($this->schemaFields()[$schema] ?? []), static fn (string $why): bool => $why === $reason);
		return array_map('strval', array_keys($fields));
	}//end fieldsWithReason()

	/**
	 * Whether a property definition, or that of its items, points at another object or holds a file.
	 *
	 * A reference, an inverse or write-back relation, a uuid, an object
	 * configuration that relates or names a schema, or a file; a nested
	 * object with no schema is a plain value.
	 *
	 * @param mixed $definition The property definition.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private static function isRelationOrFile(mixed $definition): bool {
		if (is_array($definition) === false) {
			return false;
		}

		$configuration = $definition['objectConfiguration'] ?? [];
		if (is_array($configuration) === false) {
			$configuration = [];
		}

		$markers = array_filter(
			[
				($definition['$ref'] ?? null),
				($definition['inversedBy'] ?? null),
				($definition['writeBack'] ?? null),
				($configuration['schema'] ?? null),
				($definition['format'] ?? null) === 'uuid',
				($configuration['handling'] ?? null) === 'related-object',
				($definition['type'] ?? null) === 'file',
			]
		);
		if ($markers !== []) {
			return true;
		}

		return self::isRelationOrFile(definition: ($definition['items'] ?? null));
	}//end isRelationOrFile()

	/**
	 * Whether a property definition is hidden from forms or calculated.
	 *
	 * Such a field is written by another subsystem (the service desk
	 * exchange stamps, the calculated suggestions), not edited by people.
	 *
	 * @param mixed $definition The property definition.
	 * @param bool $calculated Whether the schema calculates the property.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private static function isSystemOwned(mixed $definition, bool $calculated): bool {
		if ($calculated === true) {
			return true;
		}

		return is_array($definition) === true && ($definition['hideOnForm'] ?? false) === true;
	}//end isSystemOwned()

	/**
	 * The properties per schema of the register stackiq ships, fragments included, each with why no pack may write it.
	 *
	 * A property is refused when any of its definitions makes it a relation
	 * or a file (`relation`), or hides it from forms or calculates it (`system`);
	 * a relation wins.
	 *
	 * @return array<string, array<string, string>>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the register file or one of its fragments cannot be read.
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
			throw new CmdbImportException(
				errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
				message: 'The stackiq register fragments cannot be listed, so the CMDB import packs cannot be checked'
			);
		}

		$decodedFiles = [$register];
		foreach ($fragments as $file) {
			$fragment = $this->decodeJson(file: $file);
			if ($fragment === null) {
				throw new CmdbImportException(
					errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
					message: 'The stackiq register fragment ' . basename($file) . ' cannot be read, so the CMDB import packs cannot be checked'
				);
			}

			$decodedFiles[] = $fragment;
		}

		$fields = [];
		foreach ($decodedFiles as $decoded) {
			foreach (($decoded['components']['schemas'] ?? []) as $schema => $definition) {
				$calculations = (array)($definition['configuration']['x-openregister-calculations'] ?? []);
				foreach ((array)($definition['properties'] ?? []) as $property => $propertyDefinition) {
					$reason = '';
					if (self::isRelationOrFile(definition: $propertyDefinition) === true) {
						$reason = 'relation';
					} elseif (self::isSystemOwned(definition: $propertyDefinition, calculated: array_key_exists((string)$property, $calculations)) === true) {
						$reason = 'system';
					}

					$before = ($fields[(string)$schema][(string)$property] ?? '');
					if ($before !== 'relation' && $reason !== '') {
						$fields[(string)$schema][(string)$property] = $reason;
						continue;
					}

					$fields[(string)$schema][(string)$property] = $before;
				}
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
