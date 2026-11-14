<?php

/**
 * Characterisation tests for what SeedCmdbImportPacks reports per pack.
 *
 * Runs the step with a stubbed pack store and profile, independent of the
 * shipped pack data, and pins every output line and warning before run()
 * and seed() are split into named steps.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\SeedCmdbImportPacks;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackStore;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the seed report lines.
 */
class SeedCmdbImportPacksReportTest extends TestCase {

	/**
	 * Every output and log line, in order.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $lines = [];

	/**
	 * Run the step over the given packs with a store answering per slug.
	 *
	 * @param bool          $available Whether the store is available
	 * @param array|null    $packs     The shipped packs, or null when they cannot be read
	 * @param array<string, array|\Throwable> $answers The store answer per slug
	 *
	 * @return void
	 */
	private function runStep(bool $available, ?array $packs, array $answers = []): void {
		$profile = $this->createMock(CmdbImportProfile::class);
		if ($packs === null) {
			$profile->method('shippedEditablePacks')->willThrowException(new \RuntimeException('bad sheet'));
		} else {
			$profile->method('shippedEditablePacks')->willReturn($packs);
		}

		$store = $this->createMock(CmdbImportPackStore::class);
		$store->method('isAvailable')->willReturn($available);
		$store->method('resolve')->willReturnCallback(
			static function (array $shipped) use ($answers): array {
				$answer = $answers[$shipped['id']];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->lines[] = ['log.warning', $message];
			}
		);
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->lines[] = ['out.' . $level, $message];
				}
			);
		}

		(new SeedCmdbImportPacks($profile, $store, $logger))->run($output);

	}//end runStep()

	/**
	 * An unavailable store or unreadable packs stop the step with one line.
	 *
	 * @return void
	 */
	public function testNothingToSeed(): void {
		$this->runStep(false, []);
		$this->assertSame([['out.info', 'OpenRegister migration pack store not available; CMDB import packs not seeded']], $this->lines);

		$this->lines = [];
		$this->runStep(true, null);
		$this->assertSame(
			[
				['log.warning', '[SeedCmdbImportPacks] shipped packs unreadable: bad sheet'],
				['out.warning', 'CMDB import packs not seeded: bad sheet'],
			],
			$this->lines
		);

	}//end testNothingToSeed()

	/**
	 * Each outcome has its own line; a failing pack does not stop the others.
	 *
	 * @return void
	 */
	public function testEachOutcomeIsReported(): void {
		$this->runStep(
			true,
			[
				['id' => 'a', 'version' => '2'],
				['id' => 'b', 'version' => '3'],
				['id' => 'c', 'version' => '4'],
				['id' => 'd', 'version' => '5'],
				['id' => 'e', 'version' => '6'],
			],
			[
				'a' => ['id' => 11, 'outcome' => CmdbImportPackStore::SEEDED],
				'b' => ['id' => 12, 'outcome' => CmdbImportPackStore::UPDATED, 'version' => '3'],
				'c' => new \RuntimeException('store down'),
				'd' => ['id' => 14, 'outcome' => CmdbImportPackStore::FOUND, 'newerShipped' => true],
				'e' => ['id' => 15, 'outcome' => CmdbImportPackStore::FOUND, 'newerShipped' => false],
			]
		);

		$this->assertSame(
			[
				['out.info', 'Seeded CMDB import pack a (id 11)'],
				['out.info', 'Updated unedited CMDB import pack b (id 12) to version 3'],
				['log.warning', '[SeedCmdbImportPacks] c: store down'],
				['out.warning', 'CMDB import pack c not seeded: store down'],
				['out.warning', 'CMDB import pack d (id 14) was edited in OpenRegister and is kept; this release ships version 5, which is not applied'],
				['out.info', 'CMDB import pack e (id 15) already present'],
			],
			$this->lines
		);

	}//end testEachOutcomeIsReported()
}//end class
