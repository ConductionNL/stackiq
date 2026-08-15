<?php

/**
 * Renames this app's Dutch SCHEMA SLUGS in place, before the register import.
 *
 * OpenRegister's ImportHandler matches an incoming schema to an existing one by
 * SLUG (`SchemaMapper::findBySlugInIds()`). So changing a slug in the register
 * JSON does not rename anything: the import finds no match, CREATES a second
 * schema, and every object already stored keeps pointing at the old one. The
 * data is not lost — it is stranded behind a schema nothing reads any more,
 * and the app looks like it simply has no records.
 *
 * This step closes that gap by renaming the slug on the existing row first, so
 * the import that follows recognises the schema and updates it instead of
 * forking it. The shard table is named for the register and schema IDs, which
 * this step never touches, so the rows move with the schema untouched.
 *
 * ORDERING: this MUST run before `InitializeSettings`, which is what triggers
 * the import via `SettingsService::initialize()`. Registered first in
 * info.xml's post-migration block for that reason.
 *
 * The app-config keys derived from those slugs (`dienst_schema`) are NOT
 * migrated here. That is a separate change: the key family spans roughly forty
 * sites in SettingsService alone, including differently-prefixed
 * (`voorzieningen_contactpersoon_schema`) and compound (`koppeling_gebruik_schema`)
 * forms, and migrating a subset would resolve some object types while leaving
 * others silently "not configured". The objectType->key map keeps its existing
 * Dutch keys, so resolution is unaffected by this step.
 *
 * @category  Repair
 * @package   OCA\SoftwareCatalog\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames Dutch schema slugs on the rows the import will match against.
 */
class RenameDutchSchemaSlugs implements IRepairStep {

	/**
	 * Old slug => new slug, for schemas this app owns.
	 *
	 * Targets are read off each schema's own English `title`, not invented:
	 * `beoordeeling` was already titled "Assessment".
	 *
	 * `organisatie` is deliberately absent. This app declares BOTH an
	 * `organisatie` schema (the catalogue's own organisation, in the
	 * voorzieningen register) and an `organization` one (the ArchiMate entity,
	 * with OIN/RSIN/PKI). They share not one property. Renaming the first onto
	 * the second would collide on the flat `<slug>_schema` config key and
	 * silently leave one of the two unresolvable, so that pair is a change of
	 * its own.
	 *
	 * @var array<string, string>
	 */
	public const SLUG_MAP = [
		'beoordeeling' => 'assessment',
		'bioMaatregel' => 'bioMeasure',
		'contactpersoon' => 'contactPerson',
		'dienst' => 'service',
		'gebruik' => 'usage',
		'koppeling' => 'connection',
		'kwetsbaarheid' => 'vulnerability',
		'moduleVersie' => 'moduleVersion',
	];

	/**
	 * Registers whose schemas are in scope.
	 *
	 * @var array<int, string>
	 */
	private const REGISTER_SLUGS = ['voorzieningen', 'vng-gemma'];

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
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Rename Dutch SoftwareCatalog schema slugs';
	}//end getName()

	/**
	 * Rename the slugs on this app's existing schema rows.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		$schemaIds = $this->inScopeSchemaIds();
		if ($schemaIds === []) {
			$output->info('RenameDutchSchemaSlugs: no SoftwareCatalog registers on this install; nothing to do.');
			return;
		}

		$existing = $this->slugsOf(schemaIds: $schemaIds);

		$renamed = 0;
		$refused = 0;
		foreach (self::SLUG_MAP as $old => $new) {
			if (in_array($old, $existing, true) === false) {
				continue;
			}

			// Two schemas cannot share a slug. If the target is already present
			// the safe move is to leave both alone: merging them is a decision
			// about data, not a rename, and doing it here would be silent.
			if (in_array($new, $existing, true) === true) {
				$this->logger->warning(
					'RenameDutchSchemaSlugs: target slug already exists; renaming neither.',
					['old' => $old, 'new' => $new]
				);
				$refused++;
				continue;
			}

			if ($this->renameSlug(old: $old, new: $new, schemaIds: $schemaIds) === true) {
				$renamed++;
				// Keep the local view current so a later entry in the map sees
				// this rename when it checks for a collision.
				$existing[] = $new;
			}
		}

		$output->info(
			sprintf(
				'RenameDutchSchemaSlugs: %d slug(s) renamed, %d refused.',
				$renamed,
				$refused
			)
		);
	}//end run()

	/**
	 * Resolve the schema ids belonging to this app's registers.
	 *
	 * @return array<int, int>
	 */
	private function inScopeSchemaIds(): array {
		$placeholders = implode(',', array_fill(0, count(self::REGISTER_SLUGS), '?'));

		try {
			$rows = $this->db->executeQuery(
				'SELECT schemas FROM `*PREFIX*openregister_registers` WHERE slug IN (' . $placeholders . ')',
				self::REGISTER_SLUGS
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: could not resolve the registers; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

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
	}//end inScopeSchemaIds()

	/**
	 * Read the slugs currently held by the given schemas.
	 *
	 * @param array<int, int> $schemaIds Schema ids to read.
	 *
	 * @return array<int, string>
	 */
	private function slugsOf(array $schemaIds): array {
		$placeholders = implode(',', array_fill(0, count($schemaIds), '?'));

		try {
			$rows = $this->db->executeQuery(
				'SELECT slug FROM `*PREFIX*openregister_schemas` WHERE id IN (' . $placeholders . ')',
				array_map(static fn (int $id): string => (string)$id, $schemaIds)
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: could not read schema slugs; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return array_map(static fn (array $row): string => (string)$row['slug'], $rows);
	}//end slugsOf()

	/**
	 * Rename one slug, scoped to this app's schemas.
	 *
	 * @param string          $old       Current slug.
	 * @param string          $new       Replacement slug.
	 * @param array<int, int> $schemaIds Schema ids in scope.
	 *
	 * @return bool True when the row was updated.
	 */
	private function renameSlug(string $old, string $new, array $schemaIds): bool {
		$placeholders = implode(',', array_fill(0, count($schemaIds), '?'));

		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE slug = ? AND id IN (' . $placeholders . ')',
				array_merge([$new, $old], array_map(static fn (int $id): string => (string)$id, $schemaIds))
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: slug rename failed.',
				['old' => $old, 'new' => $new, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end renameSlug()

}//end class
