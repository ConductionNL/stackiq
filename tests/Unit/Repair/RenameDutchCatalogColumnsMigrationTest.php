<?php

/**
 * Characterisation tests for the database paths of RenameDutchCatalogColumns.
 *
 * Pins, against a mocked connection, which statements the step runs per
 * column, what it counts, which shard tables it finds and which declared
 * columns it reads, including every failure it logs and swallows.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\RenameDutchCatalogColumns;
use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

/**
 * The statements, counts and logs of the column migration.
 */
class RenameDutchCatalogColumnsMigrationTest extends TestCase {
	/**
	 * @var IDBConnection|MockObject
	 */
	private IDBConnection|MockObject $db;

	/**
	 * Logger that records every call.
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * The statements executeStatement() received.
	 *
	 * @var array<int, string>
	 */
	private array $statements = [];

	/**
	 * Set up the connection and the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getDatabasePlatform')->willReturn(
			new class {
				/**
				 * @param string $identifier The identifier.
				 *
				 * @return string
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return '"' . $identifier . '"';
				}
			}
		);
		$this->logger = new class extends AbstractLogger {
			/**
			 * @var array<int, array{0: mixed, 1: string, 2: array}>
			 */
			public array $records = [];

			/**
			 * @param mixed $level   The level.
			 * @param mixed $message The message.
			 * @param array $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->records[] = [$level, (string)$message, $context];
			}
		};
	}//end setUp()

	/**
	 * Call a private method of the step.
	 *
	 * @param string       $name The method.
	 * @param array<mixed> $args The arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args): mixed {
		$method = new ReflectionMethod(RenameDutchCatalogColumns::class, $name);
		$method->setAccessible(true);
		return $method->invokeArgs(new RenameDutchCatalogColumns($this->db, $this->logger), $args);
	}//end call()

	/**
	 * A prepared statement that yields these rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return IPreparedStatement|MockObject
	 */
	private function statement(array $rows): IPreparedStatement|MockObject {
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		return $statement;
	}//end statement()

	/**
	 * Every column decision: a rename, a back-fill, a deferral, a refused collision, and failed statements.
	 *
	 * @return void
	 */
	public function testMigrateTableRunsOneStatementPerMigratableColumn(): void {
		$columns = ['id', 'naam', 'beschrijving', 'beschrijving_lang', 'afkorting', 'abbreviation', 'groepering', 'grouping', 'detailniveau', 'gebruiken'];
		$this->db->method('prepare')->willReturn($this->statement(array_map(static fn (string $name): array => ['column_name' => $name], $columns)));
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->statements[] = $sql;
				if (str_contains($sql, '"abbreviation"') === true || str_contains($sql, '"usages"') === true) {
					throw new Exception('refused');
				}

				return 1;
			}
		);

		$counts = $this->call('migrateTable', ['oc_openregister_table_13_7', ['name', 'description', 'abbreviation', 'grouping', 'usages']]);

		$this->assertSame(['renamed' => 1, 'copied' => 1, 'refused' => 2, 'deferred' => 1], $counts);
		$this->assertEqualsCanonicalizing(
			[
				'ALTER TABLE "oc_openregister_table_13_7" RENAME COLUMN "naam" TO "name"',
				'UPDATE "oc_openregister_table_13_7" SET "abbreviation" = "afkorting" WHERE "abbreviation" IS NULL AND "afkorting" IS NOT NULL',
				'UPDATE "oc_openregister_table_13_7" SET "grouping" = "groepering" WHERE "grouping" IS NULL AND "groepering" IS NOT NULL',
				'ALTER TABLE "oc_openregister_table_13_7" RENAME COLUMN "gebruiken" TO "usages"',
			],
			$this->statements
		);
		$levels = array_count_values(array_column($this->logger->records, 1));
		$this->assertSame(2, $levels['RenameDutchCatalogColumns: refusing an ambiguous rename; two source columns target one destination.']);
		$this->assertSame(2, $levels['RenameDutchCatalogColumns: statement failed; leaving the column as it was.']);
	}//end testMigrateTableRunsOneStatementPerMigratableColumn()

	/**
	 * A table whose columns cannot be read migrates nothing.
	 *
	 * @return void
	 */
	public function testMigrateTableWithUnreadableColumnsDoesNothing(): void {
		$this->db->method('prepare')->willThrowException(new Exception('no information_schema'));
		$this->db->expects($this->never())->method('executeStatement');

		$this->assertSame(['renamed' => 0, 'copied' => 0, 'refused' => 0, 'deferred' => 0], $this->call('migrateTable', ['t', ['name']]));
		$this->assertSame(
			[['warning', 'RenameDutchCatalogColumns: could not read columns; skipping table.', ['table' => 't', 'exception' => 'no information_schema']]],
			$this->logger->records
		);
	}//end testMigrateTableWithUnreadableColumnsDoesNothing()

	/**
	 * The declared columns of a schema, snake_cased; null when they cannot be read or decoded.
	 *
	 * @return void
	 */
	public function testDeclaredColumnsOfEveryAnswer(): void {
		$answers = ['{"beschrijvingKort":{},"name":{},"7":{}}', false, '', 'not json', '"a string"'];
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) use (&$answers): IResult {
				$this->assertSame('SELECT properties FROM `*PREFIX*openregister_schemas` WHERE id = ?', $sql);
				$this->assertSame(['42'], $params);
				$result = $this->createMock(IResult::class);
				$result->method('fetchOne')->willReturn(array_shift($answers));
				return $result;
			}
		);

		$this->assertSame(['beschrijving_kort', 'name', '7'], $this->call('declaredColumnsOf', [42]));
		$this->assertNull($this->call('declaredColumnsOf', [42]));
		$this->assertNull($this->call('declaredColumnsOf', [42]));
		$this->assertNull($this->call('declaredColumnsOf', [42]));
		$this->assertNull($this->call('declaredColumnsOf', [42]));
		$this->assertSame(
			[
				['warning', 'RenameDutchCatalogColumns: a schema\'s properties did not decode to an object; skipping its table.', ['schemaId' => 42]],
				['warning', 'RenameDutchCatalogColumns: a schema\'s properties did not decode to an object; skipping its table.', ['schemaId' => 42]],
			],
			$this->logger->records
		);
	}//end testDeclaredColumnsOfEveryAnswer()

	/**
	 * A failing read of the declared columns is logged and gives null.
	 *
	 * @return void
	 */
	public function testDeclaredColumnsOfAFailingRead(): void {
		$this->db->method('executeQuery')->willThrowException(new Exception('gone'));

		$this->assertNull($this->call('declaredColumnsOf', [5]));
		$this->assertSame(
			[['warning', 'RenameDutchCatalogColumns: could not read a schema\'s declared properties; skipping its table.', ['schemaId' => 5, 'exception' => 'gone']]],
			$this->logger->records
		);
	}//end testDeclaredColumnsOfAFailingRead()

	/**
	 * The shard tables of the register by schema id, without the wire schemas and without derived tables.
	 *
	 * @return void
	 */
	public function testInScopeShardTables(): void {
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetchOne')->willReturn('13');
				$result->method('fetchAll')->willReturn(['50', '51']);
				return $result;
			}
		);
		$rows = [
			['table_name' => 'oc_openregister_table_13_7'],
			['table_name' => 'oc_openregister_table_13_50'],
			['table_name' => 'oc_openregister_table_13_8_backup'],
			['table_name' => 'oc_openregister_table_130_9'],
			['table_name' => 'oc_openregister_table_13_12'],
			[],
		];
		$this->db->method('prepare')->willReturn($this->statement($rows));

		$this->assertSame(['oc_openregister_table_13_7' => 7, 'oc_openregister_table_13_12' => 12], $this->call('inScopeShardTables', []));
		$this->assertSame([], $this->logger->records);
	}//end testInScopeShardTables()

	/**
	 * Without the register, or when the register or the tables cannot be read, no table is in scope.
	 *
	 * @return void
	 */
	public function testInScopeShardTablesFailClosed(): void {
		$registerIds = [false, null];
		$this->db->method('executeQuery')->willReturnCallback(
			function () use (&$registerIds): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetchOne')->willReturn(array_shift($registerIds));
				return $result;
			}
		);
		$this->db->expects($this->never())->method('prepare');

		$this->assertSame([], $this->call('inScopeShardTables', []));
		$this->assertSame([], $this->call('inScopeShardTables', []));
		$this->assertSame([], $this->logger->records);
	}//end testInScopeShardTablesFailClosed()

	/**
	 * A register that cannot be resolved, or tables that cannot be listed, are logged and leave nothing in scope.
	 *
	 * @return void
	 */
	public function testInScopeShardTablesLogFailures(): void {
		$calls = 0;
		$this->db->method('executeQuery')->willReturnCallback(
			function () use (&$calls): IResult {
				$calls++;
				if ($calls === 1) {
					throw new Exception('no registers table');
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchOne')->willReturn('13');
				$result->method('fetchAll')->willReturn([]);
				return $result;
			}
		);
		$this->db->method('prepare')->willThrowException(new \RuntimeException('no information_schema'));

		$this->assertSame([], $this->call('inScopeShardTables', []));
		$this->assertSame([], $this->call('inScopeShardTables', []));
		$this->assertSame(
			[
				['warning', 'RenameDutchCatalogColumns: could not resolve the register; skipping.', ['exception' => 'no registers table']],
				['warning', 'RenameDutchCatalogColumns: could not list tables; skipping.', ['exception' => 'no information_schema']],
			],
			$this->logger->records
		);
	}//end testInScopeShardTablesLogFailures()
}//end class
