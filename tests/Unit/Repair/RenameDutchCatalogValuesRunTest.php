<?php

/**
 * Characterisation tests for RenameDutchCatalogValues::run().
 *
 * Runs the step against a scripted database double and pins every prepared
 * query, bind, statement, log line and output line, so the step can be
 * split into named steps without changing what it does.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
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

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\RenameDutchCatalogValues;
use OCP\DB\Exception as DbException;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RenameDutchCatalogValues::run().
 */
class RenameDutchCatalogValuesRunTest extends TestCase {

	/**
	 * Every database, log and output call, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * Run the step on a scripted database.
	 *
	 * @param array $script tables, columns per table, affected rows per statement, failures
	 *
	 * @return void
	 */
	private function runStep(array $script): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($script): IPreparedStatement {
				$bound = [];
				$stmt  = $this->createMock(IPreparedStatement::class);
				$stmt->method('bindValue')->willReturnCallback(
					function ($param, $value) use ($sql, &$bound): bool {
						$this->calls[] = ['bind', $sql, [$param => $value]];
						$bound[$param] = $value;
						return true;
					}
				);
				$stmt->method('fetchAll')->willReturnCallback(
					function () use ($sql, $script, &$bound): array {
						if (str_contains($sql, 'information_schema.tables') === true) {
							if (($script['failTables'] ?? false) === true) {
								throw new \RuntimeException('no tables view');
							}

							return array_map(static fn (string $t): array => ['table_name' => $t], $script['tables']);
						}

						$table = $bound['table'];
						if (in_array($table, $script['failColumns'] ?? [], true) === true) {
							throw new \RuntimeException('no columns for ' . $table);
						}

						return array_map(static fn (string $c): array => ['column_name' => $c], $script['columns'][$table] ?? []);
					}
				);
				return $stmt;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []) use ($script): int {
				$this->calls[] = ['statement', $sql, $params];
				if (in_array($params[1], $script['failValues'] ?? [], true) === true) {
					throw new DbException('rewrite refused');
				}

				return $script['affected'][$params[1]] ?? 0;
			}
		);
		$db->method('getDatabasePlatform')->willReturn(
			new class {
				/**
				 * @param string $identifier Identifier
				 *
				 * @return string
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return '"' . $identifier . '"';
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['log.warning', $message, $context];
			}
		);
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['out.info', $message];
			}
		);

		(new RenameDutchCatalogValues($db, $logger))->run($output);

	}//end runStep()

	/**
	 * No shard tables (or no way to list them): one info line.
	 *
	 * @return void
	 */
	public function testNoTables(): void {
		$this->runStep(['tables' => []]);
		$this->assertSame(
			[
				['bind', 'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern', ['pattern' => '%openregister\_table\_%']],
				['out.info', 'RenameDutchCatalogValues: no Stackiq shard tables on this install; nothing to do.'],
			],
			$this->calls
		);

		$this->calls = [];
		$this->runStep(['tables' => [], 'failTables' => true]);
		$this->assertSame(
			[
				['bind', 'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern', ['pattern' => '%openregister\_table\_%']],
				['log.warning', 'RenameDutchCatalogValues: could not list tables; skipping.', ['exception' => 'no tables view']],
				['out.info', 'RenameDutchCatalogValues: no Stackiq shard tables on this install; nothing to do.'],
			],
			$this->calls
		);

	}//end testNoTables()

	/**
	 * Values are rewritten per table and column; failures are warned and counted as 0.
	 *
	 * @return void
	 */
	public function testRewritesAndCounts(): void {
		$this->runStep(
			[
				'tables' => ['oc_openregister_table_1_2', 'oc_openregister_table_1_3', 'oc_openregister_table_1_4'],
				'columns' => [
					'oc_openregister_table_1_2' => ['id', 'cost_period'],
					'oc_openregister_table_1_4' => ['integration_type'],
				],
				'failColumns' => ['oc_openregister_table_1_3'],
				'affected' => ['Maandelijks' => 3, 'Jaarlijks' => 2, 'intern' => 5],
				'failValues' => ['extern'],
			]
		);

		$warnings = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'log.warning'));
		$this->assertSame(
			[
				['log.warning', 'RenameDutchCatalogValues: could not read columns; skipping table.', ['table' => 'oc_openregister_table_1_3', 'exception' => 'no columns for oc_openregister_table_1_3']],
				['log.warning', 'RenameDutchCatalogValues: value rewrite failed.', ['table' => 'oc_openregister_table_1_4', 'column' => 'integration_type', 'exception' => 'rewrite refused']],
			],
			$warnings
		);
		$this->assertSame(['out.info', 'RenameDutchCatalogValues: 10 row value(s) translated.'], end($this->calls));
		$this->assertContains(
			['statement', 'UPDATE "oc_openregister_table_1_2" SET "cost_period" = ? WHERE "cost_period" = ?', ['Monthly', 'Maandelijks']],
			$this->calls
		);
		$this->assertSame('94a2b546d22030023338c17d6d0b41e9410e99ae', sha1(serialize($this->calls)));

	}//end testRewritesAndCounts()
}//end class
