<?php

/**
 * Tests for the software-review read rule repair.
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

use OCA\Stackiq\Repair\RestoreReviewReadRule;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Stackiq\Repair\RestoreReviewReadRule
 *
 * @spec exclude No canonical spec covers repairing the stored authorization of an
 *  existing schema row; the rule itself is declared in register.d/catalog-ratings.json.
 */
final class RestoreReviewReadRuleTest extends TestCase {

	/**
	 * The read rule an upgraded install can still carry: public reads every review.
	 *
	 * @var array<int, mixed>
	 */
	private const STALE_READ = [
		['group' => 'public', 'crossOrganisation' => true],
		'software-catalog-users',
		['group' => 'public', 'match' => ['status' => 'approved'], 'crossOrganisation' => true],
	];

	/**
	 * A connection whose SELECT returns these rows; UPDATEs are captured.
	 *
	 * @param array<int, array<string, mixed>> $rows    The schema rows.
	 * @param array<int, array<int, mixed>>    $written Captured UPDATE params.
	 *
	 * @return IDBConnection
	 */
	private function db(array $rows, array &$written): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) use ($result): IResult {
				$this->assertSame(['software-review', 'stackiq'], $params);
				return $result;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params) use (&$written): int {
				$written[] = $params;
				return 1;
			}
		);

		return $db;
	}//end db()

	/**
	 * Whether an anonymous reader may read this object under these read entries.
	 *
	 * OpenRegister grants a read entry for group public to everyone; a `match`
	 * restricts it to objects whose fields equal every given value.
	 *
	 * @param array<int, mixed>    $read   The read entries.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return bool
	 */
	private static function anonymousMayRead(array $read, array $object): bool {
		foreach ($read as $entry) {
			if ($entry === 'public') {
				return true;
			}

			if (is_array($entry) === false || ($entry['group'] ?? null) !== 'public') {
				continue;
			}

			$matches = true;
			foreach (($entry['match'] ?? []) as $field => $value) {
				if (($object[$field] ?? null) !== $value) {
					$matches = false;
				}
			}

			if ($matches === true) {
				return true;
			}
		}

		return false;
	}//end anonymousMayRead()

	/**
	 * Run the step over one stored authorization and return what it wrote.
	 *
	 * @param array<string, mixed> $authorization The stored authorization.
	 *
	 * @return array<int, array<int, mixed>> The UPDATE params.
	 */
	private function repair(array $authorization): array {
		$written = [];
		$step = new RestoreReviewReadRule(
			$this->db([['id' => 7, 'authorization' => json_encode($authorization)]], $written),
			$this->createMock(LoggerInterface::class)
		);
		$step->run($this->createMock(IOutput::class));

		return $written;
	}//end repair()

	/**
	 * After the repair a pending or rejected review is hidden from anonymous readers, an approved one is not.
	 *
	 * @return void
	 */
	public function testAfterTheRepairOnlyApprovedReviewsAreAnonymouslyReadable(): void {
		$this->assertTrue(self::anonymousMayRead(self::STALE_READ, ['status' => 'pending']), 'the stale rule leaks pending reviews');

		$written = $this->repair(['read' => self::STALE_READ, 'create' => ['ambtenaar']]);

		$this->assertCount(1, $written);
		$this->assertSame(7, $written[0][1]);
		$stored = json_decode($written[0][0], true);
		$read   = $stored['read'];

		$this->assertFalse(self::anonymousMayRead($read, ['status' => 'pending']));
		$this->assertFalse(self::anonymousMayRead($read, ['status' => 'rejected']));
		$this->assertFalse(self::anonymousMayRead($read, []));
		$this->assertTrue(self::anonymousMayRead($read, ['status' => 'approved']));
		$this->assertSame(
			[RestoreReviewReadRule::APPROVED_ONLY, 'software-catalog-users'],
			$read,
			'the other read entries are kept, the public one appears once'
		);
		$this->assertSame(['ambtenaar'], $stored['create'], 'the other actions are untouched');
	}//end testAfterTheRepairOnlyApprovedReviewsAreAnonymouslyReadable()

	/**
	 * A bare public entry is replaced too.
	 *
	 * @return void
	 */
	public function testABarePublicEntryIsReplaced(): void {
		$written = $this->repair(['read' => ['public', 'ambtenaar']]);

		$this->assertSame(
			[RestoreReviewReadRule::APPROVED_ONLY, 'ambtenaar'],
			json_decode($written[0][0], true)['read']
		);
	}//end testABarePublicEntryIsReplaced()

	/**
	 * A row without a read rule gets the approved-only public read.
	 *
	 * @return void
	 */
	public function testAMissingReadRuleGetsTheApprovedOnlyEntry(): void {
		$written = $this->repair([]);

		$this->assertSame([RestoreReviewReadRule::APPROVED_ONLY], json_decode($written[0][0], true)['read']);
	}//end testAMissingReadRuleGetsTheApprovedOnlyEntry()

	/**
	 * An already restored row is not written again.
	 *
	 * @return void
	 */
	public function testItIsIdempotent(): void {
		$first  = $this->repair(['read' => self::STALE_READ]);
		$second = $this->repair(json_decode($first[0][0], true));

		$this->assertSame([], $second);
	}//end testItIsIdempotent()

	/**
	 * A failing read does nothing and does not throw.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaTableIsLoggedAndLeftAlone(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willThrowException(new DbException('no table'));
		$db->expects($this->never())->method('executeStatement');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new RestoreReviewReadRule($db, $logger))->run($this->createMock(IOutput::class));
	}//end testAnUnreadableSchemaTableIsLoggedAndLeftAlone()

	/**
	 * A failing write is logged and does not throw.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLoggedAndDoesNotThrow(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([['id' => 7, 'authorization' => json_encode(['read' => self::STALE_READ])]]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($result);
		$db->method('executeStatement')->willThrowException(new DbException('read only'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('0 software-review'));

		(new RestoreReviewReadRule($db, $logger))->run($output);
	}//end testAFailingWriteIsLoggedAndDoesNotThrow()

	/**
	 * The step is registered after the register import, on upgrade and on install.
	 *
	 * @return void
	 */
	public function testTheStepRunsAfterTheImportOnUpgradeAndInstall(): void {
		$xml = (string)file_get_contents(dirname(__DIR__, 3).'/appinfo/info.xml');
		$this->assertSame(1, preg_match('#<post-migration>(.*)</post-migration>#s', $xml, $post));
		$this->assertSame(1, preg_match('#<install>(.*)</install>#s', $xml, $install));

		foreach ([$post[1], $install[1]] as $block) {
			$import  = strpos($block, 'Repair\\InitializeSettings</step>');
			$restore = strpos($block, 'Repair\\RestoreReviewReadRule</step>');
			$this->assertNotFalse($import);
			$this->assertNotFalse($restore);
			$this->assertGreaterThan($import, $restore);
		}
	}//end testTheStepRunsAfterTheImportOnUpgradeAndInstall()
}//end class
