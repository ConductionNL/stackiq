<?php

/**
 * Translates the Dutch ENUM VALUES stored in this app's shard tables.
 *
 * Renaming a value in the schema is only half the job, and the quieter half.
 * The declaration changes, but every row already written still holds the old
 * string — and a filter on the new one then returns NULL rather than an error,
 * so the feature reports "nothing found" instead of failing. That is the shape
 * of every value migration: the code looks right and the data disagrees.
 *
 * Scoped by COLUMN, never by value alone. `intern` is a connection's
 * integration type here and a statutory ZGW confidentiality value elsewhere;
 * `Concept` is a lifecycle state on one column and an ordinary word on the
 * next. A value migration that matches on the string alone corrupts every
 * column that happens to share it.
 *
 * Idempotent: an already-migrated row simply matches no WHERE clause.
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

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Migrates stored Dutch enum values to their English spelling.
 */
class RenameDutchCatalogValues implements IRepairStep {

	/**
	 * Property name => old value => new value.
	 *
	 * Keyed by the PROPERTY as declared in the schema; the column is derived
	 * with MagicMapper's own rule (see RenameDutchCatalogDecisions).
	 *
	 * `roles` is absent on purpose: its members mirror Nextcloud GROUP names
	 * that `ContactpersonenController` checks with `isInGroup()`, and renaming
	 * the stored value while the group keeps its name desynchronises the two.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const VALUE_MAP = [
		'type' => [
			'Functioneel beheer' => 'Functional management',
			'Applicatiebeheer' => 'Application management',
			'Technisch beheer' => 'Technical management',
			'Implementatieondersteuning' => 'Implementation support',
			'Opleidingen' => 'Training',
			'Licentiereseller' => 'Licence reseller',
			'Gemeente' => 'Municipality',
			'Leverancier' => 'Supplier',
			'Samenwerking' => 'Collaboration',
			'Applicatie' => 'Application',
			'Systeemsoftware' => 'System software',
			'n.v.t.' => 'n/a',
			'bestandsoverdracht' => 'file transfer',
			'upload naar portaal' => 'upload to portal',
		],
		'status' => [
			'Concept' => 'Draft',
			'Actief' => 'Active',
			'Deactief' => 'Inactive',
			'samengevoegd' => 'merged',
			'Verwerving' => 'Acquisition',
			'Gepland' => 'Planned',
			'In productie' => 'In production',
			'Uit te faseren' => 'To be phased out',
			'Uitgefaseerd' => 'Phased out',
			'Verlopen' => 'Expired',
			'In onderhandeling' => 'In negotiation',
			'in ontwikkeling' => 'in development',
			'in gebruik' => 'in use',
			'einde ondersteuning' => 'end of support',
			'teruggetrokken' => 'withdrawn',
		],
		'registeredBy' => [
			'Gemeente' => 'Municipality',
			'Applicatie' => 'Application',
			'Samenwerking' => 'Collaboration',
			'Leverancier' => 'Supplier',
		],
		'samenwerkingtype' => [
			'Uitvoeringsorganisatie' => 'Implementing organisation',
			'Sociaal Domein samenwerking' => 'Social Domain collaboration',
			'Omgevingsdienst' => 'Environmental agency',
			'ICT (bijvoorbeeld Shared Service Center)' => 'ICT (for example Shared Service Center)',
			'Gemeentelijke herindeling (gepland)' => 'Municipal reorganisation (planned)',
			'Gemeenschappelijke Regeling (samenwerking meerdere domeinen)' => 'Joint Arrangement (collaboration across multiple domains)',
			'Gemeenschappelijke Regeling' => 'Joint Arrangement',
			'Centrumgemeenteregeling' => 'Central municipality arrangement',
			'Belastingsamenwerking' => 'Tax collaboration',
			'Bedrijfsvoeringsorganisatie' => 'Operations organisation',
			'Archiefdienst (regionaal)' => 'Archive service (regional)',
			'Ambtelijke fusie' => 'Administrative merger',
		],
		'contractType' => [
			'Licentie' => 'Licence',
			'Onderhoud' => 'Maintenance',
		],
		'costPeriod' => [
			'Maandelijks' => 'Monthly',
			'Jaarlijks' => 'Annually',
			'Eenmalig' => 'One-off',
		],
		'dataExchangeDirection' => [
			'AnaarB' => 'AtoB',
			'BnaarA' => 'BtoA',
			'bi-directioneel' => 'bi-directional',
		],
		'integrationType' => [
			'extern' => 'external',
			'intern' => 'internal',
		],
		'hostingJurisdiction' => ['Elders' => 'Elsewhere'],
		'hostingLocation' => ['Elders' => 'Elsewhere'],
		'licence' => [
			'BSD Licentie (Berkeley Software Distribution)' => 'BSD License (Berkeley Software Distribution)',
			'European Union Public Licence (EUPL), versie 1.2' => 'European Union Public Licence (EUPL), version 1.2',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 * @param RenameDutchCatalogDecisions $decisions Column-name predicates.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly RenameDutchCatalogDecisions $decisions = new RenameDutchCatalogDecisions(),
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Translate stored Dutch Stackiq enum values';
	}//end getName()

	/**
	 * Rewrite the stored values, one column at a time.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchCatalogValues: no Stackiq shard tables on this install; nothing to do.');
			return;
		}

		$updated = 0;
		foreach ($tables as $table) {
			$planned = $this->decisions->plannedRewrites(
				valueMap: self::VALUE_MAP,
				columns: $this->columnsOf(table: $table)
			);

			foreach ($planned as $job) {
				$updated += $this->rewrite(
					table: $table,
					column: $job['column'],
					old: $job['old'],
					new: $job['new']
				);
			}
		}

		$output->info(sprintf('RenameDutchCatalogValues: %d row value(s) translated.', $updated));
	}//end run()

	/**
	 * Rewrite one value in one column.
	 *
	 * @param string $table Shard table.
	 * @param string $column Column name.
	 * @param string $old Stored Dutch value.
	 * @param string $new English replacement.
	 *
	 * @return int Rows affected.
	 */
	private function rewrite(string $table, string $column, string $old, string $new): int {
		$sql = 'UPDATE ' . $this->quote(identifier: $table)
			. ' SET ' . $this->quote(identifier: $column) . ' = ?'
			. ' WHERE ' . $this->quote(identifier: $column) . ' = ?';

		try {
			return $this->db->executeStatement($sql, [$new, $old]);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchCatalogValues: value rewrite failed.',
				['table' => $table, 'column' => $column, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end rewrite()

	/**
	 * Discover this app's shard tables.
	 *
	 * Anchors on the `openregister_table_` marker rather than a computed
	 * prefix: OCP\IDBConnection exposes neither getSchema() nor getPrefix(),
	 * and calling them is a runtime fatal that only phpstan catches.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
			$rows = $stmt->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchCatalogValues: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return array_map(static fn (array $r): string => (string)$r['table_name'], $rows);
	}//end shardTables()

	/**
	 * Read a table's column names.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
			$rows = $stmt->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchCatalogValues: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		return array_map(static fn (array $r): string => (string)$r['column_name'], $rows);
	}//end columnsOf()

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
