<?php

/**
 * Reads the columns a schema declares, for RenameDutchCatalogColumns.
 *
 * @category  Repair
 * @package   OCA\\Stackiq\\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The declared columns of one schema, read from the schemas table; null when they cannot be read.
 *
 * @category  Repair
 * @package   OCA\\Stackiq\\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * @spec openspec/specs/english-vocabulary-migration/spec.md
 */
final class RenameDutchCatalogSchemaColumns {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The snake_cased column names a schema currently declares.
	 *
	 * Read from `oc_openregister_schemas.properties`, which is the shape
	 * MagicMapper materialises columns from — verified first-hand against a live
	 * instance rather than inferred: the column is `json`, `jsonb_typeof` is
	 * `object` for all 21 stackiq schemas, and it is keyed by the
	 * camelCase property name (`properties::jsonb ? 'name'` is true on exactly
	 * the schemas the register JSON declares `naam` on).
	 *
	 * The register JSON in `lib/Settings/` would be the other candidate source
	 * and is the WRONG one: nothing imports it automatically, so it describes
	 * what an operator MAY import, not what this install has. The schemas table
	 * describes what MagicMapper will actually re-materialise, which is what
	 * decides whether a rename is orphaning.
	 *
	 * @param int                         $schemaId  The schema's database id.
	 * @param RenameDutchCatalogDecisions $decisions Spells a property as MagicMapper's column.
	 *
	 * @return array<int, string>|null Declared column names, or null when they
	 *                                 could not be read (caller must fail closed).
	 *
	 * @spec openspec/specs/english-vocabulary-migration/spec.md
	 */
	public function declaredColumns(int $schemaId, RenameDutchCatalogDecisions $decisions): ?array {
		$raw = $this->schemaPropertiesJson(schemaId: $schemaId);
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			$this->logger->warning(
				'RenameDutchCatalogColumns: a schema\'s properties did not decode to an object; skipping its table.',
				['schemaId' => $schemaId]
			);
			return null;
		}

		return self::sanitizedColumns(properties: $decoded, decisions: $decisions);
	}//end declaredColumns()

	/**
	 * The stored `properties` JSON of a schema, or null after a warning when it cannot be read.
	 *
	 * @param int $schemaId The schema's database id.
	 *
	 * @return mixed The stored value (false when there is no row), or null on a failed read.
	 */
	private function schemaPropertiesJson(int $schemaId): mixed {
		try {
			// Bound as a string, not an int: IDBConnection::executeQuery()
			// declares `array<string>` for its parameter list, and the driver
			// casts back for the numeric comparison.
			return $this->db->executeQuery(
				'SELECT properties FROM `*PREFIX*openregister_schemas` WHERE id = ?',
				[(string)$schemaId]
			)->fetchOne();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchCatalogColumns: could not read a schema\'s declared properties; skipping its table.',
				['schemaId' => $schemaId, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end schemaPropertiesJson()

	/**
	 * The snake_cased column name of every declared property.
	 *
	 * @param array<array-key, mixed>     $properties The decoded properties, keyed by property name.
	 * @param RenameDutchCatalogDecisions $decisions  Spells a property as MagicMapper's column.
	 *
	 * @return array<int, string>
	 */
	private static function sanitizedColumns(array $properties, RenameDutchCatalogDecisions $decisions): array {
		$columns = [];
		foreach (array_keys($properties) as $property) {
			$columns[] = $decisions->sanitizeColumnName(name: (string)$property);
		}

		return $columns;
	}//end sanitizedColumns()
}//end class
