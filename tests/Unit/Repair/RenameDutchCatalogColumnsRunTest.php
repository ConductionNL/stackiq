<?php

/**
 * RenameDutchCatalogColumns::run() over a register with shard tables: the
 * summary it reports, the tables it skips, and its fail-closed exits.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\RenameDutchCatalogColumns;
use OCA\Stackiq\Repair\RenameDutchCatalogDecisions;
use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Tests for the whole run of the catalog column migration.
 */
#[CoversClass(RenameDutchCatalogColumns::class)]
#[UsesClass(RenameDutchCatalogDecisions::class)]
class RenameDutchCatalogColumnsRunTest extends TestCase {

	/**
	 * The connection double.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * A logger that keeps its records.
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * What the output was told.
	 *
	 * @var string[]
	 */
	private array $said = [];

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
	 * Run the step and return what the output was told.
	 *
	 * @return string[] The info lines.
	 */
	private function runStep(): array {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->said[] = $message;
			}
		);

		(new RenameDutchCatalogColumns($this->db, $this->logger))->run($output);

		return $this->said;
	}//end runStep()

	/**
	 * A result double answering one value and one list.
	 *
	 * @param mixed $one The fetchOne answer.
	 * @param array $all The fetchAll answer.
	 *
	 * @return IResult The result.
	 */
	private function answer(mixed $one, array $all=[]): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($one);
		$result->method('fetchAll')->willReturn($all);
		return $result;
	}//end answer()

	/**
	 * Without the register the step says there is nothing to do.
	 *
	 * @return void
	 */
	public function testNoRegisterMeansNothingToDo(): void {
		$this->db->method('executeQuery')->willReturn($this->answer(false));
		$this->db->expects($this->never())->method('prepare');

		$this->assertSame(
			expected: ['RenameDutchCatalogColumns: no in-scope shard tables on this install; nothing to do.'],
			actual: $this->runStep()
		);
	}//end testNoRegisterMeansNothingToDo()

	/**
	 * Tables whose schema cannot be read are skipped and counted; the rest are migrated.
	 *
	 * @return void
	 */
	public function testUnreadableSchemasAreSkippedAndCounted(): void {
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []): IResult {
				if (str_contains($sql, 'openregister_registers') === true) {
					return $this->answer('13');
				}

				if (str_contains($sql, 'slug IN') === true) {
					return $this->answer(false, ['50']);
				}

				$properties = ['7' => '', '8' => 'not json', '12' => '{"naam": {"type": "string"}}'];
				return $this->answer($properties[$params[0]] ?? null);
			}
		);
		$tables = $this->createMock(IPreparedStatement::class);
		$tables->method('fetch')->willReturnOnConsecutiveCalls(
			['table_name' => 'oc_openregister_table_13_7'],
			['table_name' => 'oc_openregister_table_13_8'],
			['table_name' => 'oc_openregister_table_13_50'],
			['table_name' => 'oc_openregister_table_13_12'],
			false
		);
		$calls = 0;
		$this->db->method('prepare')->willReturnCallback(
			function () use (&$calls, $tables): IPreparedStatement {
				$calls++;
				if ($calls === 1) {
					return $tables;
				}

				throw new \RuntimeException('no information_schema.columns');
			}
		);

		$said = $this->runStep();

		$this->assertSame(
			expected: [
				'RenameDutchCatalogColumns: 0 column(s) renamed, 0 back-filled, 0 refused for ambiguity, '
				. '0 deferred because the register still declares the Dutch name, '
				. '2 table(s) skipped for an unreadable schema, across 3 shard table(s).',
			],
			actual: $said
		);
		$this->assertSame(
			expected: [
				['warning', 'RenameDutchCatalogColumns: a schema\'s properties did not decode to an object; skipping its table.', ['schemaId' => 8]],
				['warning', 'RenameDutchCatalogColumns: could not read columns; skipping table.', ['table' => 'oc_openregister_table_13_12', 'exception' => 'no information_schema.columns']],
			],
			actual: $this->logger->records
		);
	}//end testUnreadableSchemasAreSkippedAndCounted()

	/**
	 * When the exempt wire schemas cannot be resolved the run refuses entirely.
	 *
	 * @return void
	 */
	public function testUnresolvableWireSchemasRefuseTheRun(): void {
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql): IResult {
				if (str_contains($sql, 'slug IN') === true) {
					throw new Exception('schemas table gone');
				}

				return $this->answer('13');
			}
		);
		$this->db->expects($this->never())->method('prepare');

		try {
			$this->runStep();
			$this->fail('the run must not continue without the exempt set');
		} catch (Exception $e) {
			$this->assertSame(expected: 'schemas table gone', actual: $e->getMessage());
		}

		$this->assertSame(
			expected: [
				['error', 'RenameDutchCatalogColumns: could not resolve the exempt GEMMA schemas; refusing to migrate anything.', ['exception' => 'schemas table gone']],
			],
			actual: $this->logger->records
		);
		$this->assertSame(expected: [], actual: $this->said);
	}//end testUnresolvableWireSchemasRefuseTheRun()
}//end class
