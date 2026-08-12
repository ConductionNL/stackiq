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
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is LEFT IN PLACE, so the step is
 *     reversible and a re-run is a no-op;
 *   - nothing is deleted.
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
class RenameDutchCatalogColumns implements IRepairStep
{
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
     * @var array<string, string>
     */
    private const COLUMN_MAP = [
        'naam'              => 'name',
        'beschrijving'      => 'description',
        'beschrijving_kort' => 'short_description',
        'beschrijving_lang' => 'description',
        'omschrijving'      => 'description',
        'contactpersoon'    => 'contact_person',
        'publicatiedatum'   => 'publication_date',
        'depublicatiedatum' => 'depublication_date',
    ];

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
     * Human-readable step name.
     *
     * @return string
     *
     * @spec openspec/specs/english-vocabulary-migration/spec.md
     */
    public function getName(): string
    {
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
    public function run(IOutput $output): void
    {
        $tables = $this->inScopeShardTables();
        if ($tables === []) {
            $output->info('RenameDutchCatalogColumns: no in-scope shard tables on this install; nothing to do.');
            return;
        }

        $renamed = 0;
        $copied  = 0;
        $refused = 0;

        foreach ($tables as $table) {
            $columns = $this->columnsOf(table: $table);
            $qTable  = $this->quote(identifier: $table);

            foreach (self::COLUMN_MAP as $old => $new) {
                if (in_array($old, $columns, true) === false) {
                    // Already migrated, or this schema never had the property.
                    continue;
                }

                if ($this->hasCollision(table: $table, columns: $columns, target: $new) === true) {
                    $refused++;
                    continue;
                }

                $qOld = $this->quote(identifier: $old);
                $qNew = $this->quote(identifier: $new);

                if (in_array($new, $columns, true) === false) {
                    $sql = 'ALTER TABLE '.$qTable.' RENAME COLUMN '.$qOld.' TO '.$qNew;
                    if ($this->exec(sql: $sql) === true) {
                        $renamed++;
                    }

                    continue;
                }

                // The mapper already added an empty English column: back-fill and
                // leave the Dutch one, so this stays reversible.
                $sql = 'UPDATE '.$qTable.' SET '.$qNew.' = '.$qOld
                    .' WHERE '.$qNew.' IS NULL AND '.$qOld.' IS NOT NULL';
                if ($this->exec(sql: $sql) === true) {
                    $copied++;
                }
            }//end foreach
        }//end foreach

        $output->info(
            'RenameDutchCatalogColumns: '.$renamed.' column(s) renamed, '
            .$copied.' back-filled, '.$refused.' refused for ambiguity, across '
            .count($tables).' shard table(s).'
        );

    }//end run()

    /**
     * Whether two Dutch columns in this table both target one English name.
     *
     * Merging them would silently destroy one of the two values, so the step
     * refuses both and leaves a log line for a human to resolve.
     *
     * @param string             $table   Table name.
     * @param array<int, string> $columns Its column names.
     * @param string             $target  The English destination name.
     *
     * @return bool True when the rename is ambiguous and must be skipped.
     */
    private function hasCollision(string $table, array $columns, string $target): bool
    {
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
     * @return array<int, string>
     */
    private function inScopeShardTables(): array
    {
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

        $marker = 'openregister_table_'.((int) $registerId).'_';

        $tables = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $name = (string) ($row['table_name'] ?? '');
            if ($this->isMigratableShard(table: $name, marker: $marker, excluded: $excluded) === true) {
                $tables[] = $name;
            }
        }

        return $tables;

    }//end inScopeShardTables()

    /**
     * Whether a table is a shard of this register that is NOT wire-exempt.
     *
     * @param string          $table    Table name from information_schema.
     * @param string          $marker   `openregister_table_<registerId>_`.
     * @param array<int, int> $excluded Schema ids exempt as external wire formats.
     *
     * @return bool
     */
    private function isMigratableShard(string $table, string $marker, array $excluded): bool
    {
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
        return in_array((int) $schemaId, $excluded, true) === false;

    }//end isMigratableShard()

    /**
     * Resolve the schema ids of the externally-standardised schemas.
     *
     * @return array<int, int>
     */
    private function wireSchemaIds(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::WIRE_SCHEMA_SLUGS), '?'));

        try {
            $ids = $this->db->executeQuery(
                'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug IN ('.$placeholders.')',
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
    private function columnsOf(string $table): array
    {
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
            $name = (string) ($row['column_name'] ?? '');
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
    private function exec(string $sql): bool
    {
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
    private function quote(string $identifier): string
    {
        return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);

    }//end quote()
}//end class
