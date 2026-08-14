<?php

/**
 * SoftwareCatalog RenameDutchCatalogColumns Repair Step
 *
 * Moves stored catalog data from the Dutch column names to the English ones
 * the register declares.
 *
 * WHY THIS IS NEEDED AT ALL. OpenRegister does not store an object as a JSON
 * blob keyed by property name — each schema property is a real, snake_cased
 * COLUMN in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync, MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. Its only DROP path removes a camelCase duplicate whose
 * snake_case twin already exists.
 *
 * Renaming `naam` to `name` in the register therefore leaves the data in
 * `naam` while every read looks at `name` and finds null. No error, no data
 * loss, and invisible to the suites, which assert against fixtures rather than
 * migrated rows.
 *
 * WHY IT IS SCOPED BY SCHEMA AND NOT BY REGISTER. The sibling steps in
 * opencatalogi and decidesk scope by register, because everything under those
 * registers is ours to rename. That is NOT true here. Three schemas in this
 * register — `element`, `relation` and `view` — hold the GEMMA/GGM architecture
 * model imported from VNG, and their property names ARE the wire format of that
 * import: `ggm-naam`, `gemma-toelichting`, `toelichting`, `bron`. Under the
 * fleet vocabulary rule those are exempt, being external standard field names
 * inside the adapter layer.
 *
 * MEASURED, not assumed: of the fourteen materialised shard tables in this
 * register, the only two carrying `toelichting` and `bron` are schema ids 44
 * (`element`) and 49 (`relation`) — precisely the GEMMA pair. A register-scoped
 * step would have rewritten the import contract as a side effect, and the
 * symptom would have been a GEMMA re-import silently writing nulls.
 *
 * COLLISIONS ARE REFUSED, NOT MERGED. Several Dutch names collapse onto the
 * same English one — `beschrijving`, `beschrijving_lang` and `omschrijving` all
 * mean `description`. They do not co-occur in any schema today, but a future
 * fragment could introduce a pair. Rather than concatenating or letting the
 * last write win, the step detects two source columns targeting one destination
 * in the same table, migrates NEITHER, and logs. Silent data merging is worse
 * than a column left in Dutch.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is moved only when the SCHEMA ITSELF declares the English name
 *     and no longer declares the Dutch one (see THE ORDERING GUARD below);
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is LEFT IN PLACE, so the step is
 *     reversible and a re-run is a no-op;
 *   - nothing is deleted.
 *
 * THE ORDERING GUARD (softwarecatalog#492). Everything above is only safe in
 * ONE merge order, and until this guard existed nothing enforced it.
 *
 * `appinfo/info.xml` states the precondition in prose — "Must run AFTER the
 * register sync that adds the English columns" — and NOTHING PERFORMS THAT
 * SYNC. Measured on `development`: no file under `lib/` reads
 * `lib/Settings/softwarecatalogus_register.json` at all; the only references
 * are tests, root-level debug scripts and comments. `InitializeSettings`, the
 * step ordered immediately before this one, writes config keys and imports
 * nothing. The register is imported by a human through OpenRegister's
 * configuration UI, on their own schedule.
 *
 * So the precondition was not merely unenforced — it was not automated either,
 * and the step could not tell the two states apart. `run()` renamed precisely
 * when the English column was ABSENT, which is exactly the state produced by
 * "the register has NOT been renamed yet". Measured on the register this repo
 * ships (220 distinct property keys as a positive control, so the extraction
 * finds things): every in-scope schema still declares `naam`,
 * `beschrijvingKort`, `contactpersoon`, `publicatiedatum` and friends, and the
 * destinations `short_description` / `publication_date` / `contact_person` are
 * declared NOWHERE in scope. Renaming under that state moves the data to a
 * column nothing reads, MagicMapper re-adds an empty `naam` on the next sync,
 * and every read returns null — the step's own header warning with the two
 * halves swapped.
 *
 * The old `$columns`-only test could not distinguish "renamed, mapper behind"
 * from "not renamed at all": both look identical in a column list. The schema's
 * DECLARED properties are what tells them apart, so that is what is consulted
 * now. `renameIsSafe()` requires BOTH halves — the destination declared AND the
 * source no longer declared — which makes the step correct in EITHER merge
 * order and a genuine no-op until the register moves.
 *
 * Fails CLOSED: a schema whose declared properties cannot be read is skipped
 * entirely rather than migrated on an assumption.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\SoftwareCatalog\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename the catalog's Dutch columns to their English equivalents.
 *
 * @spec openspec/specs/english-vocabulary-migration/spec.md
 */
class RenameDutchCatalogColumns implements IRepairStep {
	/**
	 * The register slug whose shard tables are in scope.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'softwarecatalog';

	/**
	 * Schema slugs holding externally-standardised field names, which are
	 * exempt from the vocabulary rule and must NOT be migrated.
	 *
	 * `element`, `relation` and `view` carry the GEMMA/GGM architecture model
	 * imported from VNG. Their property names are the import's wire format:
	 * `view` alone holds gemma_status, gemma_thema, gemma_type, gemma_url,
	 * detailniveau, publiceren and titel_view_swc.
	 *
	 * `model` and `property-definition` are the ArchiMate Open Exchange File
	 * Format containers — `model` carries xmlns, xsi, schema_location and
	 * identifier straight off the exchange root element. Neither holds a column
	 * this step's map targets today, so listing them changes nothing now; they
	 * are here so that a property added later is exempt by default rather than
	 * migrated by omission.
	 *
	 * @var array<int, string>
	 */
	private const WIRE_SCHEMA_SLUGS = [
		'element',
		'relation',
		'view',
		'model',
		'property-definition',
	];

	/**
	 * Old snake_case column name => new snake_case column name.
	 *
	 * Snake_case, not camelCase: MagicMapper stores `shortDescription` as
	 * `short_description`, and a camelCase column is exactly what its
	 * de-duplication path then drops.
	 *
	 * A value may be a single target or a LIST of candidates.
	 *
	 * @var array<string, string|array<int, string>>
	 */
	private const COLUMN_MAP = [
		'name' => 'name',
		'description' => 'description',
		'beschrijving_kort' => 'short_description',
		'beschrijving_lang' => 'description',
		// TWO candidates — see firstSafeTarget(). On `organisatie` this is the
		// BRIEF description and that schema declares `description` separately.
		'omschrijving' => ['summary', 'description'],
		'contactpersoon' => 'contact_person',
		'publicationDate' => 'publication_date',
		'depublicationDate' => 'depublication_date',
		// Fleet vocabulary batch — snake_case, no rename chains, ambiguous
		// pairs refused rather than merged.
		'aanbevolen_standaarden' => 'recommended_standards',
		'aanbevolen_voor_referentiecomponent' => 'recommended_for_reference_component',
		'aanbieder' => 'provider',
		'afnemer' => 'consumer',
		'api-portaal' => 'api_portal',
		'api-portaal(url)' => 'api_portal_url',
		'applicaties' => 'applications',
		'architectuurlaag' => 'architecture_layer',
		'architectuurtool' => 'architecture_tool',
		'beheerder' => 'maintainer',
		'beschikbaarheid' => 'availability',
		'beschikbaarheid(belangrijkste_reden)' => 'availability_primary_reason',
		'beschrijving' => 'description',
		'bewijs' => 'evidence',
		'bewijs_referentie' => 'evidence_reference',
		'bio_versie' => 'bio_version',
		'buitengemeentelijk_voorziening' => 'non_municipal_provision',
		'contactpersoon_aanbieder' => 'contact_person_provider',
		'contactpersoon_gebruiker' => 'contact_person_user',
		'contract_nummer' => 'contract_number',
		'datum_einde_ondersteuning' => 'date_end_support',
		'datum_in_gebruik' => 'date_in_use',
		'datum_in_ontwikkeling' => 'date_in_development',
		'datum_teruggetrokken' => 'date_withdrawn',
		'deelnemers' => 'participants',
		'depublicatiedatum' => 'depublication_date',
		'document_referentie' => 'document_reference',
		'documentation-lang' => 'documentation_long',
		'dpia_volgende_beoordeling' => 'dpia_next_assessment',
		'eigenaar' => 'owner',
		'eind_datum' => 'end_date',
		'eol_bijgewerkt_op' => 'eol_updated_on',
		'eol_bron' => 'eol_source',
		'functie' => 'role',
		'gebruikt_voor_referentiecomponenten' => 'used_for_reference_components',
		'gegevensuitwisseling_richting' => 'data_exchange_direction',
		'gekoppelde_standaard_versies' => 'linked_standard_versions',
		'gemma-ggm_status' => 'gemma_ggm_status',
		'gemma-toelichting' => 'gemma_notes',
		'geplande_vervanging' => 'planned_replacement',
		'geplande_vervangings_datum' => 'planned_replacement_date',
		'gerealiseerd_met_intermediair_module' => 'realised_with_intermediary_module',
		'geregistreerd_door' => 'registered_by',
		'ggm-bron' => 'ggm_source',
		'ggm-datum-tijd-export' => 'ggm_date_time_export',
		'ggm-definitie' => 'ggm_definition',
		'ggm-naam' => 'ggm_name',
		'ggm-specialisaties' => 'ggm_specialisations',
		'ggm-toelichting' => 'ggm_notes',
		'heeft_bron' => 'has_source',
		'hosting_jurisdictie' => 'hosting_jurisdiction',
		'hosting_locatie' => 'hosting_location',
		'implicaties' => 'implications',
		'integriteit' => 'integrity',
		'integriteit(belangrijkste_reden)' => 'integrity_primary_reason',
		'interne_aantekening' => 'interne_annotation',
		'koppeling_type' => 'integration_type',
		'kosten' => 'cost',
		'kosten_periode' => 'cost_period',
		'let_op' => 'let_on',
		'licentie' => 'licence',
		'module_versies' => 'module_versions',
		'naam' => 'name',
		'name-lang' => 'name_long',
		'nora_kernwaarde' => 'nora_core_value',
		'nora_kwaliteitsdoel' => 'nora_quality_goal',
		'notificaties' => 'notifications',
		'publicatiedatum' => 'publication_date',
		'referentie_componenten' => 'reference_components',
		'registratiestatus' => 'registration_status',
		'rollen' => 'roles',
		'standaard' => 'standard',
		'standaard_gemma' => 'standard_gemma',
		'standaard_versies' => 'standard_versions',
		'standaarden' => 'standards',
		'standaarden_gemma' => 'standards_gemma',
		'standaardversie' => 'standard_version',
		'standaardversies' => 'standard_versions',
		'start_datum' => 'start_date',
		'start_datum_gepland' => 'start_date_planned',
		'start_datum_in_productie' => 'start_date_in_production',
		'start_datum_uit_gefaseerd' => 'start_date_out_phased',
		'start_datum_uit_te_faseren' => 'start_date_out_phasing',
		'start_datum_verwerving' => 'start_date_acquisition',
		'toelichting' => 'notes',
		'type_voorziening' => 'type_provision',
		'verplichte_standaarden' => 'mandatory_standards',
		'verplichte_voor_referentiecomponent' => 'mandatory_for_reference_component',
		'versie' => 'version',
		'versieaanduiding' => 'version_designation',
		'vertrouwelijkheid' => 'confidentiality',
		'vertrouwelijkheid(belangrijkste_reden)' => 'confidentiality_primary_reason',
		'waardering' => 'rating',
		'afkorting' => 'abbreviation',
		'bbn_niveau' => 'bbn_level',
		'beleidsdomein' => 'policy_domain',
		'detailniveau' => 'detail_level',
		'gebruiken' => 'usages',
		'gemma_sortering' => 'gemma_sorting',
		'groepering' => 'grouping',
		'pakketversie_beschrijving' => 'package_version_description',
		'publiceren' => 'publish',
		'titel_view_swc' => 'title_view_swc',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly RenameDutchCatalogDecisions $decisions = new RenameDutchCatalogDecisions(),
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/english-vocabulary-migration/spec.md
	 */
	public function getName(): string {
		return 'Move catalog data from the Dutch columns to the English ones';
	}//end getName()

	/**
	 * Run the column migration across every in-scope shard table.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/english-vocabulary-migration/spec.md
	 */
	public function run(IOutput $output): void {
		$tables = $this->inScopeShardTables();
		if ($tables === []) {
			$output->info('RenameDutchCatalogColumns: no in-scope shard tables on this install; nothing to do.');
			return;
		}

		$totals = [
			'renamed' => 0,
			'copied' => 0,
			'refused' => 0,
			'deferred' => 0,
			'skippedTables' => 0,
		];

		foreach ($tables as $table => $schemaId) {
			$declared = $this->declaredColumnsOf(schemaId: $schemaId);
			if ($declared === null) {
				// Fail closed: without the schema's declared properties there is
				// no way to tell "renamed, mapper behind" from "not renamed at
				// all", and those need opposite actions.
				$totals['skippedTables']++;
				continue;
			}

			foreach ($this->migrateTable(table: $table, declared: $declared) as $key => $count) {
				$totals[$key] += $count;
			}
		}

		$output->info(
			'RenameDutchCatalogColumns: ' . $totals['renamed'] . ' column(s) renamed, '
			. $totals['copied'] . ' back-filled, ' . $totals['refused'] . ' refused for ambiguity, '
			. $totals['deferred'] . ' deferred because the register still declares the Dutch name, '
			. $totals['skippedTables'] . ' table(s) skipped for an unreadable schema, across '
			. count($tables) . ' shard table(s).'
		);

	}//end run()

	/**
	 * Move every migratable column of one shard table.
	 *
	 * Split out of `run()` so that method stays a readable table loop and this
	 * one carries the per-column decision. Returns counters rather than
	 * mutating shared state, so the two levels cannot disagree about what was
	 * done.
	 *
	 * @param string $table The shard table name.
	 * @param array<int, string> $declared Snake_cased declared property names
	 *                                     of that table's schema.
	 *
	 * @return array<string, int> Counts keyed `renamed`/`copied`/`refused`/`deferred`.
	 */
	private function migrateTable(string $table, array $declared): array {
		$counts = ['renamed' => 0, 'copied' => 0, 'refused' => 0, 'deferred' => 0];

		$columns = $this->columnsOf(table: $table);
		$qTable = $this->quote(identifier: $table);

		foreach (self::COLUMN_MAP as $old => $target) {
			if (in_array($old, $columns, true) === false) {
				// Already migrated, or this schema never had the property.
				continue;
			}

			// A map entry may carry SEVERAL candidate targets; the schema's own
			// declared columns decide which applies. See firstSafeTarget().
			$new = $this->decisions->firstSafeTarget(
				old: $old,
				candidates: (array)$target,
				declared: $declared
			);
			if ($new === null) {
				// No candidate is declared here yet, or the Dutch name still is.
				// Moving the data now would orphan it.
				$counts['deferred']++;
				continue;
			}

			if ($this->hasCollision(table: $table, columns: $columns, target: $new) === true) {
				$counts['refused']++;
				continue;
			}

			$qOld = $this->quote(identifier: $old);
			$qNew = $this->quote(identifier: $new);

			if (in_array($new, $columns, true) === false) {
				$sql = 'ALTER TABLE ' . $qTable . ' RENAME COLUMN ' . $qOld . ' TO ' . $qNew;
				if ($this->exec(sql: $sql) === true) {
					$counts['renamed']++;
				}

				continue;
			}

			// The mapper already added an empty English column: back-fill and
			// leave the Dutch one, so this stays reversible.
			$sql = 'UPDATE ' . $qTable . ' SET ' . $qNew . ' = ' . $qOld
				. ' WHERE ' . $qNew . ' IS NULL AND ' . $qOld . ' IS NOT NULL';
			if ($this->exec(sql: $sql) === true) {
				$counts['copied']++;
			}
		}//end foreach

		return $counts;
	}//end migrateTable()

	/**
	 * The snake_cased column names a schema currently declares.
	 *
	 * Read from `oc_openregister_schemas.properties`, which is the shape
	 * MagicMapper materialises columns from — verified first-hand against a live
	 * instance rather than inferred: the column is `json`, `jsonb_typeof` is
	 * `object` for all 21 softwarecatalog schemas, and it is keyed by the
	 * camelCase property name (`properties::jsonb ? 'name'` is true on exactly
	 * the schemas the register JSON declares `naam` on).
	 *
	 * The register JSON in `lib/Settings/` would be the other candidate source
	 * and is the WRONG one: nothing imports it automatically, so it describes
	 * what an operator MAY import, not what this install has. The schemas table
	 * describes what MagicMapper will actually re-materialise, which is what
	 * decides whether a rename is orphaning.
	 *
	 * @param int $schemaId The schema's database id.
	 *
	 * @return array<int, string>|null Declared column names, or null when they
	 *                                 could not be read (caller must fail closed).
	 */
	private function declaredColumnsOf(int $schemaId): ?array {
		try {
			// Bound as a string, not an int: IDBConnection::executeQuery()
			// declares `array<string>` for its parameter list, and the driver
			// casts back for the numeric comparison.
			$raw = $this->db->executeQuery(
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

		$columns = [];
		foreach (array_keys($decoded) as $property) {
			$columns[] = $this->decisions->sanitizeColumnName(name: (string)$property);
		}

		return $columns;
	}//end declaredColumnsOf()

	/**
	 * Whether two Dutch columns in this table both target one English name.
	 *
	 * Merging them would silently destroy one of the two values, so the step
	 * refuses both and leaves a log line for a human to resolve.
	 *
	 * @param string $table Table name.
	 * @param array<int, string> $columns Its column names.
	 * @param string $target The English destination name.
	 *
	 * @return bool True when the rename is ambiguous and must be skipped.
	 */
	private function hasCollision(string $table, array $columns, string $target): bool {
		$sources = [];
		foreach (self::COLUMN_MAP as $old => $new) {
			if ($new === $target && in_array($old, $columns, true) === true) {
				$sources[] = $old;
			}
		}

		if (count($sources) < 2) {
			return false;
		}

		$this->logger->warning(
			'RenameDutchCatalogColumns: refusing an ambiguous rename; two source columns target one destination.',
			['table' => $table, 'sources' => $sources, 'target' => $target]
		);

		return true;
	}//end hasCollision()

	/**
	 * Resolve the shard tables in scope: this register, minus the wire schemas.
	 *
	 * Ids are looked up at runtime — both the register id and the schema ids
	 * differ per install.
	 *
	 * Returns table name => schema id. The schema id is carried through rather
	 * than re-parsed in `run()` because the ordering guard needs it to read that
	 * schema's declared properties, and re-deriving it from the table name in a
	 * second place is one more thing that can drift.
	 *
	 * @return array<string, int>
	 */
	private function inScopeShardTables(): array {
		try {
			$registerId = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug = ?',
				[self::REGISTER_SLUG]
			)->fetchOne();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchCatalogColumns: could not resolve the register; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		if ($registerId === false || $registerId === null) {
			return [];
		}

		$excluded = $this->wireSchemaIds();

		// Table discovery goes through information_schema, NOT IDBConnection.
		// OCP\IDBConnection exposes neither getSchema() nor getPrefix(); both
		// exist only on the concrete OC\DB\Connection. Calling them is a runtime
		// fatal that `php -l` and phpcs both report as clean — only phpstan
		// catches it. Pattern follows openregister's own RegisterService: anchor
		// on the `openregister_table_` MARKER, never on a computed prefix.
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchCatalogColumns: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$marker = 'openregister_table_' . ((int)$registerId) . '_';

		$tables = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['table_name'] ?? '');
			if ($this->isMigratableShard(table: $name, marker: $marker, excluded: $excluded) === true) {
				$tables[$name] = (int)substr($name, (strpos($name, $marker) + strlen($marker)));
			}
		}

		return $tables;
	}//end inScopeShardTables()

	/**
	 * Whether a table is a shard of this register that is NOT wire-exempt.
	 *
	 * @param string $table Table name from information_schema.
	 * @param string $marker `openregister_table_<registerId>_`.
	 * @param array<int, int> $excluded Schema ids exempt as external wire formats.
	 *
	 * @return bool
	 */
	private function isMigratableShard(string $table, string $marker, array $excluded): bool {
		$offset = strpos($table, $marker);
		if ($offset === false) {
			return false;
		}

		// Everything after the marker must be the numeric schema id, so a
		// derived table (…_13_50_backup) or a non-shard (…_13_audit) is left
		// alone. Note this is NOT what stops register 13 matching register
		// 130's tables — the marker already ends in '_', so `…_table_13_` is
		// not a substring of `…_table_130_50` in the first place.
		$schemaId = substr($table, ($offset + strlen($marker)));
		if (ctype_digit($schemaId) === false) {
			return false;
		}

		// GEMMA/ArchiMate schemas carry an external wire format and are exempt.
		return in_array((int)$schemaId, $excluded, true) === false;
	}//end isMigratableShard()

	/**
	 * Resolve the schema ids of the externally-standardised schemas.
	 *
	 * @return array<int, int>
	 */
	private function wireSchemaIds(): array {
		$placeholders = implode(',', array_fill(0, count(self::WIRE_SCHEMA_SLUGS), '?'));

		try {
			$ids = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug IN (' . $placeholders . ')',
				self::WIRE_SCHEMA_SLUGS
			)->fetchAll(\PDO::FETCH_COLUMN);
		} catch (Exception $e) {
			// Fail CLOSED: if the exempt set cannot be resolved, migrate nothing
			// rather than risk rewriting the GEMMA import contract.
			$this->logger->error(
				'RenameDutchCatalogColumns: could not resolve the exempt GEMMA schemas; refusing to migrate anything.',
				['exception' => $e->getMessage()]
			);
			throw $e;
		}

		return array_map('intval', $ids);
	}//end wireSchemaIds()

	/**
	 * List the column names of a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		// Queried from information_schema — IDBConnection has no getSchema().
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchCatalogColumns: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$columns = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['column_name'] ?? '');
			if ($name !== '') {
				$columns[] = $name;
			}
		}

		return $columns;
	}//end columnsOf()

	/**
	 * Execute one DDL/DML statement, logging and swallowing failure.
	 *
	 * A failure must not abort the repair run: the remaining tables are
	 * independent, and an un-migrated column is still readable.
	 *
	 * @param string $sql The statement.
	 *
	 * @return bool Whether it succeeded.
	 */
	private function exec(string $sql): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchCatalogColumns: statement failed; leaving the column as it was.',
				['sql' => $sql, 'exception' => $e->getMessage()]
			);
			return false;
		}

	}//end exec()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()
}//end class
