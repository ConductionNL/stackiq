<?php

/**
 * The pure decisions behind RenameDutchSchemaSlugs.
 *
 * A collaborator rather than static helpers, because the ruleset forbids static
 * access — and an object keeps these testable on their own. They take plain
 * arrays and scalars and touch neither database nor logger, which is what makes
 * the DECISION unit-testable while the DDL that follows it is not.
 *
 * Same split as RenameDutchCatalogDecisions, for the same reason: a repair step
 * that reaches the database cannot be exercised without one, so everything that
 * can be decided before touching it is decided here.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
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

/**
 * Pure predicates for the Dutch-to-English schema slug migration.
 */
class RenameDutchSchemaSlugDecisions {

	/**
	 * Decide which slugs may be renamed, given what the install actually holds.
	 *
	 * Returns the renames in the order they must be applied, plus the ones
	 * refused and why. Two schemas cannot share a slug, so a target that is
	 * already present means BOTH are left alone: merging them is a decision
	 * about data, not a rename.
	 *
	 * The `$existing` set is updated as it goes, so a rename earlier in the map
	 * is visible to the collision check of a later one — otherwise two entries
	 * targeting the same name would both look safe.
	 *
	 * @param array<string, string> $map      Old slug => new slug.
	 * @param array<int, string>    $existing Slugs currently present.
	 *
	 * @return array{renames: array<string, string>, refused: array<string, string>}
	 */
	public function plan(array $map, array $existing): array {
		$renames = [];
		$refused = [];

		foreach ($map as $old => $new) {
			if (in_array($old, $existing, true) === false) {
				// Not on this install — not a refusal, just nothing to do.
				continue;
			}

			if (in_array($new, $existing, true) === true) {
				$refused[$old] = sprintf("target slug '%s' already exists", $new);
				continue;
			}

			$renames[$old] = $new;
			$existing[] = $new;
		}

		return [
			'renames' => $renames,
			'refused' => $refused,
		];
	}//end plan()

	/**
	 * May the absorbed ArchiMate organisation schema be retired?
	 *
	 * Only when it holds no rows. A NEGATIVE count is the caller's signal that
	 * it could not look — an unreadable table must never be mistaken for an
	 * empty one, which is the whole failure mode this guard exists to prevent.
	 *
	 * @param int $rowCount Rows in the schema's shard tables, or a negative
	 *                      value when the count could not be taken.
	 *
	 * @return bool True when retiring is safe.
	 */
	public function mayRetire(int $rowCount): bool {
		return ($rowCount === 0);
	}//end mayRetire()

	/**
	 * Pull the schema ids out of the registers' `schemas` JSON column.
	 *
	 * The column is JSON, and a register row can carry null, a malformed value
	 * or a list with non-numeric entries. Every one of those must yield "no ids"
	 * rather than a fatal, because this runs inside a repair step where an
	 * exception aborts the upgrade.
	 *
	 * @param array<int, array<string, mixed>> $rows Register rows.
	 *
	 * @return array<int, int> Distinct schema ids.
	 */
	public function schemaIdsFrom(array $rows): array {
		$ids = [];

		foreach ($rows as $row) {
			$decoded = json_decode((string)($row['schemas'] ?? '[]'), true);
			if (is_array($decoded) === false) {
				continue;
			}

			foreach ($decoded as $id) {
				if (is_numeric($id) === true) {
					$ids[] = (int)$id;
				}
			}
		}

		return array_values(array_unique($ids));
	}//end schemaIdsFrom()

	/**
	 * Is this table the shard table for the given schema?
	 *
	 * The SQL `LIKE` used to find candidates cannot anchor on the id, so
	 * `%_table_%_3` also matches `..._table_3_13`. Confirming the suffix here
	 * keeps a row count from silently including another schema's rows — which
	 * would make an empty schema look occupied and refuse a safe merge.
	 *
	 * @param string $tableName The candidate table name.
	 * @param int    $schemaId  The schema id.
	 *
	 * @return bool True when the table belongs to that schema.
	 */
	public function isShardTableFor(string $tableName, int $schemaId): bool {
		return (preg_match('/_table_\d+_' . $schemaId . '$/', $tableName) === 1);
	}//end isShardTableFor()

	/**
	 * Pick the two organisation schemas out of the install's schema rows.
	 *
	 * Both must be present for the merge to mean anything: with only one, either
	 * it already ran or this install never had the second schema, and in both
	 * cases there is no name to free. Returning nulls rather than throwing keeps
	 * that an ordinary outcome instead of an error path.
	 *
	 * @param array<int, array<string, mixed>> $rows Schema rows with `id` and `slug`.
	 *
	 * @return array{archimate: array<string, mixed>|null, catalogue: array<string, mixed>|null}
	 */
	public function organisationPair(array $rows): array {
		$archimate = null;
		$catalogue = null;

		foreach ($rows as $row) {
			$slug = (string)($row['slug'] ?? '');
			if ($slug === 'organization') {
				$archimate = $row;
			}

			if ($slug === 'organisatie') {
				$catalogue = $row;
			}
		}

		return [
			'archimate' => $archimate,
			'catalogue' => $catalogue,
		];
	}//end organisationPair()
}//end class
