<?php

/**
 * The properties of the register stackiq ships, per schema, with why no import pack may write each one.
 *
 * Read from the register file and every register.d fragment (ADR-037). A
 * property is refused when any of its definitions makes it a relation or a
 * file (`relation`), or hides it from forms or calculates it (`system`); a
 * relation wins. Split out of CmdbImportPackRules so the rules stay readable.
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
 * Reads the register's properties per schema and why no pack may write them.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 */
class CmdbRegisterSchemaFields {

	/**
	 * Per schema, once read: each property name and why no pack may write it.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private ?array $fields = null;

	/**
	 * Constructor.
	 *
	 * @param string $settingsDirectory Directory of the register file and its fragments.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function __construct(
		private string $settingsDirectory,
	) {
	}//end __construct()

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
	public function fields(): array {
		if ($this->fields !== null) {
			return $this->fields;
		}

		$decodedFiles = $this->registerDocuments();

		$fields = [];
		foreach ($decodedFiles as $decoded) {
			foreach (($decoded['components']['schemas'] ?? []) as $schema => $definition) {
				$calculations = (array)($definition['configuration']['x-openregister-calculations'] ?? []);
				foreach ((array)($definition['properties'] ?? []) as $property => $propertyDefinition) {
					$reason = self::reasonOf(definition: $propertyDefinition, calculated: array_key_exists((string)$property, $calculations));
					$before = ($fields[(string)$schema][(string)$property] ?? '');
					if ($before !== 'relation' && $reason !== '') {
						$before = $reason;
					}

					$fields[(string)$schema][(string)$property] = $before;
				}
			}
		}

		$this->fields = $fields;
		return $fields;
	}//end fields()

	/**
	 * The register file and every fragment, decoded; fails closed when one cannot be read.
	 *
	 * A fragment carries the same protection as the register (a hidden or
	 * calculated field), so a skipped fragment would let a pack write it.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the register file or one of its fragments cannot be read.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function registerDocuments(): array {
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

		return $decodedFiles;
	}//end registerDocuments()

	/**
	 * Why no pack may write a property: `relation`, `system`, or '' for a plain, writable one.
	 *
	 * @param mixed $definition The property definition.
	 * @param bool $calculated Whether the schema calculates the property.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private static function reasonOf(mixed $definition, bool $calculated): string {
		if (self::isRelationOrFile(definition: $definition) === true) {
			return 'relation';
		}

		if (self::isSystemOwned(definition: $definition, calculated: $calculated) === true) {
			return 'system';
		}

		return '';
	}//end reasonOf()

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
