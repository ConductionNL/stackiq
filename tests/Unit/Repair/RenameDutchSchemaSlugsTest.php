<?php

/**
 * Characterisation tests for the RenameDutchSchemaSlugs repair step.
 *
 * Runs the step against a scripted database double and pins every query,
 * statement, log line and output line, so the step can be split into named
 * steps without changing what it does.
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

use OCA\Stackiq\Repair\RenameDutchSchemaSlugs;
use OCP\DB\Exception as DbException;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RenameDutchSchemaSlugs::run().
 */
class RenameDutchSchemaSlugsTest extends TestCase {

	/**
	 * Every database, log and output call, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * Scripted answers: register rows, schema rows, shard tables, counts, failures.
	 *
	 * @var array<string, mixed>
	 */
	private array $script = [];

	/**
	 * A result double answering fetchAll / fetchOne.
	 *
	 * @param array $rows  Rows for fetchAll
	 * @param mixed $value Value for fetchOne
	 *
	 * @return IResult
	 */
	private function fakeResult(array $rows, mixed $value = null): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('fetchOne')->willReturn($value);
		return $result;

	}//end fakeResult()

	/**
	 * Answer an executeQuery call from the script.
	 *
	 * @param string $sql    The SQL
	 * @param array  $params The parameters
	 *
	 * @return IResult
	 */
	private function answer(string $sql, array $params): IResult {
		$this->calls[] = ['query', $sql, $params];
		foreach (($this->script['fail'] ?? []) as $needle) {
			if (str_contains($sql, $needle) === true) {
				throw new DbException('failed: ' . $needle);
			}
		}

		if (str_contains($sql, 'openregister_registers') === true) {
			return $this->fakeResult($this->script['registers'] ?? []);
		}

		if (str_starts_with($sql, 'SELECT COUNT(*)') === true) {
			return $this->fakeResult([], $this->script['counts'][$sql] ?? 0);
		}

		if (str_starts_with($sql, 'SELECT id, slug') === true) {
			return $this->fakeResult($this->script['schemas'] ?? []);
		}

		return $this->fakeResult(array_map(static fn (array $r): array => ['slug' => $r['slug']], $this->script['schemas'] ?? []));

	}//end answer()

	/**
	 * Build the step on a scripted database and run it.
	 *
	 * @param array $script The script
	 *
	 * @return void
	 */
	private function runStep(array $script): void {
		$this->script = $script;
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(fn (string $sql, array $params = []): IResult => $this->answer($sql, $params));
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				$this->calls[] = ['statement', $sql, $params];
				foreach (($this->script['failStatement'] ?? []) as $needle) {
					if (in_array($needle, $params, true) === true) {
						throw new DbException('statement failed');
					}
				}

				return 1;
			}
		);
		$db->method('prepare')->willReturnCallback(
			function (string $sql): IPreparedStatement {
				if (($this->script['failPrepare'] ?? false) === true) {
					throw new \RuntimeException('no information_schema');
				}

				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('bindValue')->willReturnCallback(
					function ($param, $value) use ($sql): bool {
						$this->calls[] = ['bind', $sql, [$param => $value]];
						return true;
					}
				);
				$stmt->method('fetchAll')->willReturn($this->script['tables'] ?? []);
				return $stmt;
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
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = ['out.' . $level, $message];
				}
			);
		}

		(new RenameDutchSchemaSlugs($db, $logger))->run($output);

	}//end runStep()

	/**
	 * The calls of a given kind, in order.
	 *
	 * @param string $kind The kind
	 *
	 * @return array
	 */
	private function callsOf(string $kind): array {
		return array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === $kind));

	}//end callsOf()

	/**
	 * No registers: one info line, nothing else.
	 *
	 * @return void
	 */
	public function testNoRegistersDoesNothing(): void {
		$this->runStep(['registers' => []]);

		$this->assertSame(
			[
				['query', 'SELECT schemas FROM `*PREFIX*openregister_registers` WHERE slug IN (?,?)', ['stackiq', 'vng-gemma']],
				['out.info', 'RenameDutchSchemaSlugs: no Stackiq registers on this install; nothing to do.'],
			],
			$this->calls
		);

	}//end testNoRegistersDoesNothing()

	/**
	 * Failing register lookup: warning, then the nothing-to-do line.
	 *
	 * @return void
	 */
	public function testRegisterLookupFailure(): void {
		$this->runStep(['fail' => ['openregister_registers']]);

		$this->assertSame(
			['log.warning', 'RenameDutchSchemaSlugs: could not resolve the registers; skipping.', ['exception' => 'failed: openregister_registers']],
			$this->calls[1]
		);
		$this->assertSame('out.info', $this->calls[2][0]);
		$this->assertCount(3, $this->calls);

	}//end testRegisterLookupFailure()

	/**
	 * An empty ArchiMate schema is parked, then the slugs are renamed with one refusal.
	 *
	 * @return void
	 */
	public function testEmptyArchimateIsParkedAndSlugsRenamed(): void {
		$this->runStep(
			[
				'registers' => [['schemas' => '[3,5,7,9,10,11]']],
				'schemas' => [
					['id' => '3', 'slug' => 'organization'],
					['id' => '5', 'slug' => 'organisatie'],
					['id' => '7', 'slug' => 'gebruik'],
					['id' => '9', 'slug' => 'dienst'],
					['id' => '10', 'slug' => 'service'],
					['id' => '11', 'slug' => 'koppeling'],
				],
				'tables' => [
					['table_name' => 'oc_openregister_table_1_3'],
					['table_name' => 'oc_openregister_table_2_13'],
				],
				'failStatement' => ['usage'],
			]
		);

		$this->assertSame(
			[
				['query', 'SELECT schemas FROM `*PREFIX*openregister_registers` WHERE slug IN (?,?)', ['stackiq', 'vng-gemma']],
				['query', 'SELECT id, slug FROM `*PREFIX*openregister_schemas` WHERE id IN (?,?,?,?,?,?)', ['3', '5', '7', '9', '10', '11']],
				['bind', 'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern', ['pattern' => '%openregister\_table\_%\_3']],
				['query', 'SELECT COUNT(*) FROM "oc_openregister_table_1_3"', []],
				['statement', 'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?', ['archimateOrganizationLegacy', 3]],
				['out.info', 'RenameDutchSchemaSlugs: absorbed ArchiMate organization schema (0 rows) parked as archimateOrganizationLegacy.'],
				['query', 'SELECT slug FROM `*PREFIX*openregister_schemas` WHERE id IN (?,?,?,?,?,?)', ['3', '5', '7', '9', '10', '11']],
			],
			array_slice($this->calls, 0, 7)
		);
		$rest = array_slice($this->calls, 7);
		$this->assertSame('out.info', end($rest)[0]);
		$this->assertSame('RenameDutchSchemaSlugs: 1 slug(s) renamed, 2 refused.', end($rest)[1]);
		$this->assertSame(
			[
				['log.warning', 'RenameDutchSchemaSlugs: slug rename failed.', ['old' => 'gebruik', 'new' => 'usage', 'exception' => 'statement failed']],
			],
			array_values(array_filter($rest, static fn (array $c): bool => $c[1] === 'RenameDutchSchemaSlugs: slug rename failed.'))
		);
		$this->assertSame('fc8bd2ccd7c6e13eab308035d6dc031ea726d85d', sha1(serialize($this->calls)));

	}//end testEmptyArchimateIsParkedAndSlugsRenamed()

	/**
	 * An ArchiMate schema holding rows is left alone, loudly.
	 *
	 * @return void
	 */
	public function testArchimateWithRowsIsRefused(): void {
		$count = 'SELECT COUNT(*) FROM "oc_openregister_table_1_3"';
		$this->runStep(
			[
				'registers' => [['schemas' => '[3,5]']],
				'schemas' => [
					['id' => '3', 'slug' => 'organization'],
					['id' => '5', 'slug' => 'organisatie'],
				],
				'tables' => [
					['table_name' => 'oc_openregister_table_1_3'],
					['table_name' => 'oc_openregister_table_2_3'],
				],
				'counts' => [$count => '2'],
			]
		);

		$this->assertSame(
			['log.warning', 'RenameDutchSchemaSlugs: the ArchiMate organization schema holds rows; refusing to merge. Migrate them onto the catalogue organisation deliberately, then re-run repair.', ['schemaId' => '3', 'rows' => 2]],
			$this->callsOf('log.warning')[0]
		);
		$this->assertSame(
			['out.warning', 'RenameDutchSchemaSlugs: ArchiMate organization schema holds 2 row(s) — merge refused, both schemas left as they are.'],
			$this->callsOf('out.warning')[0]
		);
		$this->assertSame([], array_filter($this->callsOf('statement'), static fn (array $c): bool => in_array('archimateOrganizationLegacy', $c[2], true)));
		$this->assertSame('f140f7346025ce3ed4a7d3f9f6f343b9f52b0159', sha1(serialize($this->calls)));

	}//end testArchimateWithRowsIsRefused()

	/**
	 * Unable to list or count shard tables: treated as non-empty (refused).
	 *
	 * @return void
	 */
	public function testUncountableArchimateIsRefused(): void {
		$base = [
			'registers' => [['schemas' => '[3,5]']],
			'schemas' => [
				['id' => '3', 'slug' => 'organization'],
				['id' => '5', 'slug' => 'organisatie'],
			],
		];

		$this->runStep($base + ['failPrepare' => true]);
		$this->assertSame(
			['log.warning', 'RenameDutchSchemaSlugs: could not list shard tables; treating the schema as non-empty.', ['exception' => 'no information_schema']],
			$this->callsOf('log.warning')[0]
		);
		$this->assertStringContainsString('holds -1 row(s)', $this->callsOf('out.warning')[0][1]);

		$this->calls = [];
		$this->runStep($base + ['tables' => [['table_name' => 'oc_openregister_table_1_3']], 'fail' => ['SELECT COUNT(*)']]);
		$this->assertSame(
			['log.warning', 'RenameDutchSchemaSlugs: could not count rows; treating the schema as non-empty.', ['table' => 'oc_openregister_table_1_3', 'exception' => 'failed: SELECT COUNT(*)']],
			$this->callsOf('log.warning')[0]
		);
		$this->assertStringContainsString('holds -1 row(s)', $this->callsOf('out.warning')[0][1]);

	}//end testUncountableArchimateIsRefused()

	/**
	 * Failing schema reads and a failing park statement are warned and skipped.
	 *
	 * @return void
	 */
	public function testReadAndParkFailures(): void {
		$this->runStep(['registers' => [['schemas' => '[3]']], 'fail' => ['SELECT id, slug', 'SELECT slug']]);
		$this->assertSame(
			[
				['log.warning', 'RenameDutchSchemaSlugs: could not read schemas; skipping.', ['exception' => 'failed: SELECT id, slug']],
				['log.warning', 'RenameDutchSchemaSlugs: could not read schema slugs; skipping.', ['exception' => 'failed: SELECT slug']],
			],
			array_slice($this->callsOf('log.warning'), 0, 2)
		);

		$this->calls = [];
		$this->runStep(
			[
				'registers' => [['schemas' => '[3,5]']],
				'schemas' => [
					['id' => '3', 'slug' => 'organization'],
					['id' => '5', 'slug' => 'organisatie'],
				],
				'failStatement' => ['archimateOrganizationLegacy'],
			]
		);
		$this->assertSame(
			['log.warning', 'RenameDutchSchemaSlugs: could not retire the ArchiMate organization schema.', ['exception' => 'statement failed']],
			$this->callsOf('log.warning')[0]
		);
		$this->assertSame([], array_filter($this->callsOf('out.info'), static fn (array $c): bool => str_contains($c[1], 'parked')));
		$this->assertSame('c47984efff3a95b5c2b1525d383deb6d03dc725f', sha1(serialize($this->calls)));

	}//end testReadAndParkFailures()
}//end class
