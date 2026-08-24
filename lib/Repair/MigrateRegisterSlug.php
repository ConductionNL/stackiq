<?php

/**
 * Renames this app's OpenRegister register SLUG in place, before the import.
 *
 * WHY A REPAIR STEP AT ALL. OpenRegister's ImportHandler resolves a register by
 * SLUG and by nothing else — `registerMapper->find(id: strtolower($data['slug']))`,
 * and `autoCreateRegisterIfApplication()` on `filters: ['slug' => $slug]` — and
 * the not-found branch is not an error path, it is the "create a new one" path.
 * So shipping a renamed slug in the register JSON without renaming the row
 * first does not rename anything: the import finds no match, CREATES A SECOND,
 * EMPTY REGISTER, and the app addresses that one from then on. Every stored
 * object stays behind on the old row, reachable by nothing. Nothing errors. The
 * app simply looks new.
 *
 * WHY IT DOES NOT TOUCH A SINGLE OBJECT. Measured against a live install rather
 * than assumed: an object is bound to its register by NUMERIC ID, not by slug.
 * Every shard table's `_register` column holds the id, and the tables themselves
 * are named `oc_openregister_table_<registerId>_<schemaId>` — OpenRegister
 * composes that name from `$register->getId()` at every call site. There is no
 * slug anywhere in the physical layout. Renaming a slug therefore re-points
 * nothing and can strand nothing: it is a one-column UPDATE on one row, and
 * every object, table, schema link and folder follows it untouched. On the dev
 * instance this register is id 11 and keeps that id.
 *
 * WHY `x-openregister.app` ALREADY SAYS `stackiq`. For a `type: application`
 * configuration, ImportHandler's autoCreateRegisterIfApplication() reads
 * `$slug = $xOpenregister['app'] ?? $appId` — that field IS a register slug, not
 * an attribution label. The app-id rename (#708) moved it to `stackiq` while the
 * register row still said `voorzieningen`, which is exactly the fork described
 * above. This step closes that gap by moving the row to meet it.
 *
 * ORDERING IS LOAD-BEARING. This runs FIRST among the register steps, ahead of
 * everything that resolves a register by slug (RenameDutchSchemaSlugs,
 * RenameDutchCatalogColumns) and ahead of the register import that
 * InitializeSettings triggers. It runs after the app-id steps, which move
 * `oc_appconfig` / `oc_preferences` rows and have nothing to do with registers.
 *
 * NON-DESTRUCTIVE AND IDEMPOTENT. It renames only when the old slug is present
 * and the new one is not; a second run finds nothing to do and says so. It
 * refuses rather than merges when both exist, because two rows sharing a slug
 * means the lower id silently wins every lookup, and choosing between them is a
 * decision about data. It never throws: under `<install>` an escaping exception
 * aborts the install and the app never enables at all.
 *
 * NOT IN THE MAP: `vng-gemma`. That register holds the VNG GEMMA reference
 * model imported from outside this app. It is not this app's own store and its
 * name is answered to elsewhere, so it does not move with this app's identity.
 *
 * All OCP service calls use POSITIONAL arguments (named args are FATAL on
 * `occ upgrade`).
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\AppInfo\Application;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Renames the register row the import will match against.
 *
 * @spec exclude No canonical spec covers the `voorzieningen` -> `stackiq`
 *  register-slug migration. Pointing this at an existing spec would report
 *  conformance to a requirement that says nothing about it.
 */
class MigrateRegisterSlug implements IRepairStep {

	/**
	 * Old register slug => new register slug.
	 *
	 * One entry, deliberately. This app owns TWO registers and only one of them
	 * moves. `vng-gemma` carries the VNG GEMMA reference data, is not this
	 * app's own store, and is addressed by that name outside this repository —
	 * so it is absent here and stays exactly as it is.
	 *
	 * @var array<string, string>
	 */
	public const SLUG_MAP = [
		'voorzieningen' => 'stackiq',
	];

	/**
	 * App-config keys that may hold a register SLUG rather than a numeric id.
	 *
	 * Renaming the register row is not enough on its own. A stored value still
	 * saying the old slug sends every reader to a register that no longer
	 * answers to that name — which OpenRegister resolves by CREATING an empty
	 * one. Same silent failure as the row rename exists to prevent, one layer
	 * up.
	 *
	 * The migration is guarded on the value: it rewrites a key ONLY when what is
	 * stored is exactly an old slug from the map above. An app that stores the
	 * numeric register id here (stackiq does, inside its `voorzieningen_config`
	 * blob) never matches, so the guard makes this safe to carry everywhere
	 * rather than something to remember per app.
	 *
	 * @var array<int, string>
	 */
	public const APPCONFIG_KEYS = [
		'register',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param IAppConfig $appConfig App configuration.
	 * @param LoggerInterface $logger Logger.
	 * @param MigrateRegisterSlugDecisions $decisions The pure predicates.
	 *
	 * @spec exclude No canonical spec covers the `voorzieningen` -> `stackiq`
	 *  register-slug migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly MigrateRegisterSlugDecisions $decisions = new MigrateRegisterSlugDecisions(),
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the `voorzieningen` -> `stackiq`
	 *  register-slug migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function getName(): string {
		return 'Rename Stackiq register slugs';
	}//end getName()

	/**
	 * Rename the slug on this app's existing register row.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the `voorzieningen` -> `stackiq`
	 *  register-slug migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function run(IOutput $output): void {
		$existing = $this->existingSlugs();
		$plan = $this->decisions->plan(map: self::SLUG_MAP, existing: $existing);

		foreach ($plan['refused'] as $old => $why) {
			$this->logger->warning(
				'MigrateRegisterSlug: ' . $why . '; renaming neither.',
				['old' => $old]
			);
		}

		$renamed = 0;
		foreach ($plan['renames'] as $old => $new) {
			if ($this->renameSlug(old: $old, new: $new) === true) {
				$renamed++;
			}
		}

		$rekeyed = $this->migrateStoredSlugValues();

		$output->info(
			sprintf(
				'MigrateRegisterSlug: %d register slug(s) renamed, %d refused, %d config value(s) re-pointed.',
				$renamed,
				count($plan['refused']),
				$rekeyed
			)
		);
	}//end run()

	/**
	 * Re-point app-config values that still name an old register slug.
	 *
	 * Runs unconditionally rather than only alongside a rename: on the install
	 * hook the row may already carry the new slug (a previous run, or a fresh
	 * install) while a stored config value copied over from the old app id still
	 * says the old one. Guarded on the VALUE, so a key holding anything else —
	 * a numeric register id, an admin's deliberate override — is left alone.
	 *
	 * @return int Number of values rewritten.
	 */
	private function migrateStoredSlugValues(): int {
		$rekeyed = 0;

		foreach (self::APPCONFIG_KEYS as $key) {
			try {
				$current = $this->appConfig->getValueString(Application::APP_ID, $key, '');
			} catch (Throwable $e) {
				$this->logger->warning(
					'MigrateRegisterSlug: could not read app config; leaving it alone.',
					['key' => $key, 'exception' => $e->getMessage()]
				);
				continue;
			}

			$new = self::SLUG_MAP[$current] ?? null;
			if ($new === null) {
				continue;
			}

			try {
				$this->appConfig->setValueString(Application::APP_ID, $key, $new);
				$rekeyed++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'MigrateRegisterSlug: could not re-point app config.',
					['key' => $key, 'old' => $current, 'new' => $new, 'exception' => $e->getMessage()]
				);
			}
		}

		return $rekeyed;
	}//end migrateStoredSlugValues()

	/**
	 * Read the slugs currently held by the registers on both sides of the map.
	 *
	 * A read failure yields an empty set, which plans no rename at all. That is
	 * the safe direction: this step must never turn a database hiccup into an
	 * aborted install.
	 *
	 * @return array<int, string>
	 */
	private function existingSlugs(): array {
		$slugs = $this->decisions->slugsToRead(map: self::SLUG_MAP);
		$placeholders = $this->decisions->placeholders(count: count($slugs));

		try {
			$rows = $this->db->executeQuery(
				'SELECT slug FROM `*PREFIX*openregister_registers` WHERE slug IN (' . $placeholders . ')',
				$slugs
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'MigrateRegisterSlug: could not read register slugs; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->decisions->slugsFrom(rows: $rows);
	}//end existingSlugs()

	/**
	 * Rename one register slug.
	 *
	 * Scoped to the old slug alone: the row's id, schemas, folder, application
	 * and every shard table it owns are keyed on the numeric id and are
	 * deliberately left untouched.
	 *
	 * @param string $old Current slug.
	 * @param string $new Replacement slug.
	 *
	 * @return bool True when the row was updated.
	 */
	private function renameSlug(string $old, string $new): bool {
		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_registers` SET slug = ? WHERE slug = ?',
				[$new, $old]
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'MigrateRegisterSlug: register slug rename failed.',
				['old' => $old, 'new' => $new, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end renameSlug()
}//end class
