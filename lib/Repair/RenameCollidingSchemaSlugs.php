<?php

/**
 * Stackiq RenameCollidingSchemaSlugs repair step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Moves this app's share of the cross-app slug collisions onto namespaced slugs IN
 * PLACE, before the register import.
 *
 * A schema slug is global per organisation and `SchemaMapper::find()` matches
 * `LOWER(slug)`, so when two apps declare one slug the lookup answers with whichever
 * row it reached first.
 *
 * `contract` was claimed by three apps: shillinq, this one, and pipelinq. All three
 * carry `contractNumber`, so they describe one contract from billing, catalogue and
 * sales. shillinq owns the lifecycle (ADR-066); this schema is the catalogue facet and
 * points at it.
 *
 * This app has its own history with the slug: `RenameDutchSchemaSlugs` renamed `dienst`
 * to `service` and in doing so walked into a second collision, which is the same shape
 * of mistake and is still open.
 *
 * Why a repair step and why before the import. OpenRegister matches an existing schema
 * by (application, slug): `ImportHandler` calls `findByApplicationAndSlug()` and
 * creates a NEW schema when that misses. A slug rename in the shipped fragment
 * therefore does not rename anything, it CREATES a second schema and silently orphans
 * the first together with every object already written against it. The old schema keeps
 * its shard table and its rows; the app resolves the new id and reads an empty
 * collection. Nothing errors.
 *
 * Separate from {@see RenameDutchSchemaSlugs}, which is the Dutch-to-English vocabulary
 * pass. Folding this into that map would say they are the same migration, and the next
 * person reading it would have no way to tell why `contract` sits in a list of Dutch
 * translations.
 *
 * @category Repair
 * @package  OCA\Stackiq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://stackiq.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames this app's colliding schema slugs in place, ahead of the register import.
 *
 * @spec exclude No canonical spec covers the cross-app slug namespacing pass. Pointing
 *  this at an existing spec would report conformance to a requirement that says nothing
 *  about it.
 */
class RenameCollidingSchemaSlugs implements IRepairStep {
	/**
	 * Old slug => new slug, with the app it collided with.
	 *
	 * @var array<string, array{to: string, with: string}>
	 */
	private const RENAMES = [
		'contract' => ['to' => 'catalogContract', 'with' => 'shillinq'],
		// `service` reaches this step from RenameDutchSchemaSlugs, which renames
		// `dienst` to `service` — onto a slug shillinq and pipelinq already
		// claimed. The Dutch map cannot carry this itself: its planner forbids
		// two sources targeting one name, and `dienst` and `service` would both
		// have to point at `catalogService`. Ordering does it instead. The Dutch
		// pass runs first and lands on `service`; this pass then moves it.
		'service' => ['to' => 'catalogService', 'with' => 'shillinq, pipelinq'],
	];

	/**
	 * The owning application, as stored on the schema row.
	 *
	 * @var string
	 */
	private const APPLICATION = 'stackiq';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function getName(): string {
		return 'Namespace the stackiq schema slugs that collided with another app';
	}//end getName()

	/**
	 * Rename each slug, unless doing so would be ambiguous.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function run(IOutput $output): void {
		foreach (array_keys(self::RENAMES) as $from) {
			$this->renameOne(output: $output, from: $from);
		}
	}//end run()

	/**
	 * Rename one slug.
	 *
	 * @param IOutput $output Repair output.
	 * @param string  $from   The slug being moved away from (a key of RENAMES).
	 *
	 * @return void
	 */
	private function renameOne(IOutput $output, string $from): void {
		$rename = $this->planRename(from: $from);
		if ($this->reportNothingToRename(output: $output, rename: $rename) === true
			|| $this->refuseAmbiguousRename(output: $output, rename: $rename) === true
			|| $this->writeRename(output: $output, rename: $rename) === false
		) {
			return;
		}

		$output->info(
			'RenameCollidingSchemaSlugs: schema ' . $rename->old[0] . ' renamed `' . $rename->from . '` -> `' . $rename->to
			. '` (collided with ' . $rename->with . '); its objects stay attached.'
		);
	}//end renameOne()

	/**
	 * Look up this app's schemas under the old slug, then under the new one.
	 *
	 * @param string $from The slug being moved away from (a key of RENAMES).
	 *
	 * @return SchemaSlugRename The planned rename.
	 */
	private function planRename(string $from): SchemaSlugRename {
		$rename = new SchemaSlugRename();
		$rename->from = $from;
		$rename->to   = self::RENAMES[$from]['to'];
		$rename->with = self::RENAMES[$from]['with'];
		$rename->old  = $this->schemaIds(slug: $rename->from);
		$rename->new  = $this->schemaIds(slug: $rename->to);

		return $rename;
	}//end planRename()

	/**
	 * Say so, and answer true, when the table is unreadable or there is no old slug.
	 *
	 * @param IOutput          $output Repair output.
	 * @param SchemaSlugRename $rename The planned rename.
	 *
	 * @return bool True when there is nothing to rename.
	 */
	private function reportNothingToRename(IOutput $output, SchemaSlugRename $rename): bool {
		if ($rename->old === null || $rename->new === null) {
			$output->info('RenameCollidingSchemaSlugs: schema table unreadable; leaving `' . $rename->from . '` alone.');
			return true;
		}

		if ($rename->old === []) {
			$output->info('RenameCollidingSchemaSlugs: no stackiq-owned `' . $rename->from . '`; nothing to do.');
			return true;
		}

		return false;
	}//end reportNothingToRename()

	/**
	 * Warn, and answer true, when both slugs exist or the old slug is duplicated.
	 *
	 * @param IOutput          $output Repair output.
	 * @param SchemaSlugRename $rename The planned rename.
	 *
	 * @return bool True when the rename is refused.
	 */
	private function refuseAmbiguousRename(IOutput $output, SchemaSlugRename $rename): bool {
		if ($rename->new !== []) {
			// Both slugs present: each may own objects, and renaming would collide
			// with the new row. Abandoning either set is not a call a repair step
			// gets to make without being asked.
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: both slugs exist; refusing to merge them.',
				['from' => $rename->from, 'to' => $rename->to, 'old' => $rename->old, 'new' => $rename->new]
			);
			$output->warning(
				'RenameCollidingSchemaSlugs: both `' . $rename->from . '` and `' . $rename->to
				. '` exist; refusing to merge them. Resolve by hand.'
			);
			return true;
		}

		return $this->refuseDuplicateRename(output: $output, rename: $rename);
	}//end refuseAmbiguousRename()

	/**
	 * Warn, and answer true, when more than one schema carries the old slug.
	 *
	 * @param IOutput          $output Repair output.
	 * @param SchemaSlugRename $rename The planned rename.
	 *
	 * @return bool True when the rename is refused.
	 */
	private function refuseDuplicateRename(IOutput $output, SchemaSlugRename $rename): bool {
		if (count($rename->old) <= 1) {
			return false;
		}

		$this->logger->warning(
			'RenameCollidingSchemaSlugs: duplicate slugs; refusing to guess.',
			['from' => $rename->from, 'ids' => $rename->old]
		);
		$output->warning('RenameCollidingSchemaSlugs: duplicate `' . $rename->from . '` schemas; refusing to guess.');
		return true;
	}//end refuseDuplicateRename()

	/**
	 * Write the new slug onto the one old schema row.
	 *
	 * @param IOutput          $output Repair output.
	 * @param SchemaSlugRename $rename The planned rename.
	 *
	 * @return bool True when the row was renamed.
	 */
	private function writeRename(IOutput $output, SchemaSlugRename $rename): bool {
		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?',
				[$rename->to, $rename->old[0]]
			);
		} catch (Exception $e) {
			// Safe to fail: the import then creates a new schema rather than
			// updating this one, which is the pre-existing behaviour. Loud,
			// because the objects on the old schema stop being reachable.
			$this->logger->error(
				'RenameCollidingSchemaSlugs: slug rename failed; the import will create a second schema.',
				['from' => $rename->from, 'id' => $rename->old[0], 'exception' => $e->getMessage()]
			);
			$output->warning('RenameCollidingSchemaSlugs: renaming `' . $rename->from . '` failed; see the log.');
			return false;
		}

		return true;
	}//end writeRename()

	/**
	 * Ids of this application's schemas carrying the given slug.
	 *
	 * Scoped to THIS app's rows. Without the application filter the lookup would
	 * find shillinq's `Contract` too, and renaming that is precisely the damage
	 * this step exists to avoid.
	 *
	 * @param string $slug The schema slug to look for.
	 *
	 * @return array<int, mixed>|null The ids, or null when the table cannot be read.
	 */
	private function schemaIds(string $slug): ?array {
		try {
			$rows = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application = ?',
				[$slug, self::APPLICATION]
			)->fetchAll(\PDO::FETCH_COLUMN);

			return array_values((array)$rows);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: could not read the schema table; skipping.',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end schemaIds()
}//end class
