<?php

/**
 * Tests for the colliding schema slug rename.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\RenameCollidingSchemaSlugs;
use OCP\DB\Exception;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Stackiq\Repair\RenameCollidingSchemaSlugs
 *
 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
 */
final class RenameCollidingSchemaSlugsTest extends TestCase {

	/**
	 * Every output, log and write call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $events = [];

	/**
	 * Run the step over a schema table.
	 *
	 * @param array<string, array<int, int>|null> $ids        Slug => ids of this app's schemas (null: the read fails).
	 * @param array<int, string>                  $failWrites Slugs whose rename UPDATE fails.
	 *
	 * @return array<int, string> The recorded events.
	 */
	private function runOver(array $ids, array $failWrites = []): array {
		$this->events = [];

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) use ($ids): IResult {
				$this->events[] = 'query ' . $params[0] . ' ' . $params[1];
				if (array_key_exists($params[0], $ids) === true && $ids[$params[0]] === null) {
					throw new Exception('read failed');
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($ids[$params[0]] ?? []);
				return $result;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params) use ($failWrites): int {
				$this->events[] = 'write ' . $sql . ' ' . json_encode($params);
				if (in_array($params[0], $failWrites, true) === true) {
					throw new Exception('write failed');
				}

				return 1;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context) use ($level): void {
					$this->events[] = 'log.' . $level . ' ' . $message . ' ' . json_encode($context);
				}
			);
		}

		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->events[] = 'out.' . $level . ' ' . $message;
				}
			);
		}

		(new RenameCollidingSchemaSlugs($db, $logger))->run($output);

		return $this->events;
	}//end runOver()

	/**
	 * A lone old slug is renamed; an absent one is left alone.
	 *
	 * @return void
	 */
	public function testRenamesALoneSlugAndSkipsAnAbsentOne(): void {
		self::assertSame(
			[
				'query contract stackiq',
				'query catalogContract stackiq',
				'write UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ? ["catalogContract",7]',
				'out.info RenameCollidingSchemaSlugs: schema 7 renamed `contract` -> `catalogContract` (collided with shillinq); its objects stay attached.',
				'query service stackiq',
				'query catalogService stackiq',
				'out.info RenameCollidingSchemaSlugs: no stackiq-owned `service`; nothing to do.',
			],
			$this->runOver(['contract' => [7], 'catalogContract' => []])
		);
	}//end testRenamesALoneSlugAndSkipsAnAbsentOne()

	/**
	 * An unreadable table and two present slugs both leave the rows alone.
	 *
	 * @return void
	 */
	public function testAnUnreadableTableAndTwoPresentSlugsChangeNothing(): void {
		self::assertSame(
			[
				'query contract stackiq',
				'log.warning RenameCollidingSchemaSlugs: could not read the schema table; skipping. {"slug":"contract","exception":"read failed"}',
				'query catalogContract stackiq',
				'out.info RenameCollidingSchemaSlugs: schema table unreadable; leaving `contract` alone.',
				'query service stackiq',
				'query catalogService stackiq',
				'log.warning RenameCollidingSchemaSlugs: both slugs exist; refusing to merge them. {"from":"service","to":"catalogService","old":[3],"new":[4]}',
				'out.warning RenameCollidingSchemaSlugs: both `service` and `catalogService` exist; refusing to merge them. Resolve by hand.',
			],
			$this->runOver(['contract' => null, 'service' => [3], 'catalogService' => [4]])
		);
	}//end testAnUnreadableTableAndTwoPresentSlugsChangeNothing()

	/**
	 * Duplicate old slugs are refused; an unreadable new slug and a failing write are reported.
	 *
	 * @return void
	 */
	public function testDuplicatesAnUnreadableTargetAndAFailingWriteAreReported(): void {
		self::assertSame(
			[
				'query contract stackiq',
				'query catalogContract stackiq',
				'log.warning RenameCollidingSchemaSlugs: duplicate slugs; refusing to guess. {"from":"contract","ids":[1,2]}',
				'out.warning RenameCollidingSchemaSlugs: duplicate `contract` schemas; refusing to guess.',
				'query service stackiq',
				'query catalogService stackiq',
				'write UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ? ["catalogService",5]',
				'log.error RenameCollidingSchemaSlugs: slug rename failed; the import will create a second schema. {"from":"service","id":5,"exception":"write failed"}',
				'out.warning RenameCollidingSchemaSlugs: renaming `service` failed; see the log.',
			],
			$this->runOver(['contract' => [1, 2], 'service' => [5]], ['catalogService'])
		);
		self::assertSame(
			[
				'query contract stackiq',
				'query catalogContract stackiq',
				'log.warning RenameCollidingSchemaSlugs: could not read the schema table; skipping. {"slug":"catalogContract","exception":"read failed"}',
				'out.info RenameCollidingSchemaSlugs: schema table unreadable; leaving `contract` alone.',
				'query service stackiq',
				'query catalogService stackiq',
				'out.info RenameCollidingSchemaSlugs: no stackiq-owned `service`; nothing to do.',
			],
			$this->runOver(['contract' => [1], 'catalogContract' => null])
		);
	}//end testDuplicatesAnUnreadableTargetAndAFailingWriteAreReported()
}//end class
