<?php

/**
 * ReferencesAuditCommand run tests
 *
 * Pins what the audit does when its satellite rows cannot be read, and
 * that empty rows are passed over.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/contract-administration/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Command;

use OCA\Stackiq\Command\ReferencesAuditCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Cases for an unreadable or empty satellite schema.
 *
 * @covers \OCA\Stackiq\Command\ReferencesAuditCommand
 *
 * @spec openspec/specs/contract-administration/spec.md
 */
class ReferencesAuditCommandRunTest extends TestCase {

	/**
	 * Without OpenRegister's object service nothing is read and the run fails.
	 *
	 * @return void
	 */
	public function testAnUnreadableSatelliteSchemaFailsTheRun(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));

		$tester = new CommandTester(new ReferencesAuditCommand($container));
		$tester->execute(['--write' => true]);

		self::assertSame(1, $tester->getStatusCode());
		self::assertSame('could not read catalogContract: no openregister'.PHP_EOL, $tester->getDisplay());
	}//end testAnUnreadableSatelliteSchemaFailsTheRun()

	/**
	 * Empty satellite rows are passed over; a run with nothing to fill in says nothing about the write option.
	 *
	 * @return void
	 */
	public function testEmptyRowsArePassedOver(): void {
		$objectService = new class {
			/**
			 * The schema read next.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array $config The query.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config): array {
				if (($config['offset'] ?? 0) > 0) {
					return [];
				}

				if ($this->schema === 'Contract') {
					return [['id' => 'o1', 'contractNumber' => 'K1']];
				}

				return [[], 'not a row', ['id' => 's1', 'contractNumber' => 'K1', 'contract' => 'o1']];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$tester = new CommandTester(new ReferencesAuditCommand($container));
		$tester->execute([]);

		self::assertSame(0, $tester->getStatusCode());
		self::assertSame(
			PHP_EOL.'catalogContract.contract -> shillinq.Contract via contractNumber: 1 set, 0 dangling, 0 backfillable, 0 ambiguous, 0 unmatched, 0 written'.PHP_EOL,
			$tester->getDisplay()
		);
	}//end testEmptyRowsArePassedOver()
}//end class
