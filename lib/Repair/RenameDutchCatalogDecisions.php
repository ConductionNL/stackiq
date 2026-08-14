<?php

/**
 * The pure decisions behind RenameDutchCatalogColumns.
 *
 * A collaborator rather than static helpers, because the ruleset forbids static
 * access — and an object keeps these testable on their own. They take plain
 * arrays and touch no database and no logger, which is what makes the DECISION
 * unit-testable while the DDL that follows it is not.
 *
 * It also keeps the repair step under phpmd's class-complexity ceiling, which it
 * was already sitting on before the candidate-target support was added.
 *
 * @category Repair
 * @package  OCA\\SoftwareCatalog\\Repair
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Repair;

/**
 * Pure predicates for the Dutch-to-English column migration.
 *
 * @category Repair
 * @package  OCA\\SoftwareCatalog\\Repair
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.conduction.nl
 */
class RenameDutchCatalogDecisions {

	/**
	 * Convert a declared property name to the column MagicMapper materialises.
	 *
	 * Mirrors `MagicMapper::sanitizeColumnName()` step for step, because the
	 * comparison is only meaningful if both sides spell the name the same way:
	 * the schema declares `beschrijvingKort` and the column is
	 * `beschrijving_kort`, so comparing the raw property name against
	 * COLUMN_MAP's snake_case keys would never match and the guard would defer
	 * every rename forever — a guard that always says no is as useless as one
	 * that always says yes, and much harder to notice.
	 *
	 * Kept as a private copy rather than a dependency on openregister: this step
	 * must run during a repair pass whether or not that app's classes are
	 * loadable, and one `extends`/`use` of another app's class is enough to
	 * fatal the whole run.
	 *
	 * @param string $name A declared property name.
	 *
	 * @return string The snake_cased column name.
	 */
	public function sanitizeColumnName(string $name): string {
		$name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
		$name = strtolower($name);
		$name = preg_replace('/[^a-z0-9_]/', '_', $name);
		$name = preg_replace('/_+/', '_', $name);

		return rtrim($name, '_');
	}//end sanitizeColumnName()

	/**
	 * Whether moving `$old` to `$new` is safe for a schema declaring `$declared`.
	 *
	 * This is the softwarecatalog#492 guard, and it is deliberately BOTH halves:
	 *
	 *   - the destination MUST be declared — otherwise the data lands in a
	 *     column nothing reads, and MagicMapper will re-add the Dutch one empty;
	 *   - the source must NO LONGER be declared — otherwise the register still
	 *     considers the Dutch name live, MagicMapper will re-materialise it, and
	 *     writes and reads end up on different columns.
	 *
	 * Requiring only the first would still fire during the window where a
	 * register declares both names, which is the ambiguous state the collision
	 * check exists to refuse elsewhere. Requiring only the second would fire on
	 * a schema that has simply dropped the property.
	 *
	 * The predicate is pure — it takes the declared set rather than reading it —
	 * so the decision this step turns on is unit-testable without a database.
	 * The DDL that follows is not, which is precisely why the DECISION is.
	 *
	 * @param string $old The Dutch column name.
	 * @param string $new The English column name.
	 * @param array<int, string> $declared Snake_cased declared property names.
	 *
	 * @return bool True when the register has moved and the data should follow.
	 */
	public function renameIsSafe(string $old, string $new, array $declared): bool {
		if (in_array($new, $declared, true) === false) {
			return false;
		}

		return in_array($old, $declared, true) === false;
	}//end renameIsSafe()

	/**
	 * The first candidate target a schema is safely moving to.
	 *
	 * A map entry may carry SEVERAL candidates, because one Dutch name does not
	 * always become the same English one: `omschrijving` is the detailed
	 * description on most schemas and the BRIEF one on `organisatie`, which
	 * declares `description` separately. Candidate ORDER is authoritative, most
	 * specific first, and renameIsSafe() is the single rule about when a column
	 * may move.
	 *
	 * @param string $old The Dutch column name.
	 * @param array<int, string> $candidates Target names, most specific first.
	 * @param array<int, string> $declared Snake_cased declared property names.
	 *
	 * @return string|null The chosen target, or null when none is safe yet.
	 */
	public function firstSafeTarget(string $old, array $candidates, array $declared): ?string {
		foreach ($candidates as $candidate) {
			if ($this->renameIsSafe(old: $old, new: $candidate, declared: $declared) === true) {
				return $candidate;
			}
		}

		return null;
	}//end firstSafeTarget()

}//end class
