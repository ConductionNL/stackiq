<?php

/**
 * ReferencesAuditCommand failure-path unit tests
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Command;

use OCA\Stackiq\Command\ReferencesAuditCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What the audit does when a read or a write fails, and which rows it skips.
 *
 * @covers \OCA\Stackiq\Command\ReferencesAuditCommand
 * @uses   \OCA\Stackiq\Command\ObjectPages
 */
class ReferencesAuditCommandFailuresTest extends TestCase {
	/**
	 * Patches the fake ObjectService received.
	 *
	 * @var array<int, string>
	 */
	public array $patched = [];

	/**
	 * Build the command over a fake ObjectService that can fail on demand.
	 *
	 * @param array<int, array<string, mixed>> $satellites      Satellite rows.
	 * @param array<int, array<string, mixed>> $owners          Owner rows.
	 * @param string|null                      $satelliteError  Message the satellite read throws, if any.
	 * @param array<int, string>               $failingPatchIds Object ids whose patch throws.
	 *
	 * @return CommandTester The tester.
	 */
	private function tester(array $satellites, array $owners, ?string $satelliteError = null, array $failingPatchIds = []): CommandTester {
		$objectService = new class($satellites, $owners, $satelliteError, $failingPatchIds, $this) {
			/**
			 * The schema the next read targets.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<int, array<string, mixed>> $satellites      Satellite rows.
			 * @param array<int, array<string, mixed>> $owners          Owner rows.
			 * @param string|null                      $satelliteError  Satellite read failure.
			 * @param array<int, string>               $failingPatchIds Ids whose patch throws.
			 * @param ReferencesAuditCommandFailuresTest $test          The test, for patch capture.
			 */
			public function __construct(
				private array $satellites,
				private array $owners,
				private ?string $satelliteError,
				private array $failingPatchIds,
				private ReferencesAuditCommandFailuresTest $test,
			) {
			}

			/**
			 * @param string $register The register slug.
			 *
			 * @return self Fluent.
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema slug.
			 *
			 * @return self Fluent.
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config): array {
				if (($config['offset'] ?? 0) > 0) {
					return [];
				}

				if ($this->schema === 'Contract') {
					return $this->owners;
				}

				if ($this->satelliteError !== null) {
					throw new RuntimeException($this->satelliteError);
				}

				return $this->satellites;
			}

			/**
			 * @param string               $objectId      The object id.
			 * @param array<string, mixed> $data          The patch.
			 * @param string|null          $register      The register.
			 * @param string|null          $schema        The schema.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return array<string, mixed> The patch, echoed.
			 */
			public function patchObject(
				string $objectId,
				array $data,
				?string $register = null,
				?string $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if (in_array($objectId, $this->failingPatchIds, true) === true) {
					throw new RuntimeException('locked by another process');
				}

				$this->test->patched[] = $objectId;
				return $data;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

		return new CommandTester(new ReferencesAuditCommand($container));
	}

	/**
	 * An unreadable satellite schema stops the run with status 1 and names the schema.
	 *
	 * @return void
	 */
	public function testAnUnreadableSatelliteSchemaFailsBeforeAuditing(): void {
		$tester = $this->tester([], [['id' => 'o1', 'contractNumber' => 'K1']], 'register not found');

		$status = $tester->execute(['--write' => true]);

		self::assertSame(1, $status);
		self::assertSame('could not read catalogContract: register not found', trim($tester->getDisplay()));
		self::assertSame([], $this->patched);
	}

	/**
	 * A failed patch is reported per row and not counted as written; the other row still is.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsReportedAndNotCounted(): void {
		$tester = $this->tester(
			[
				['id' => 's1', 'contractNumber' => 'K1', 'contract' => ''],
				['id' => 's2', 'contractNumber' => 'K2', 'contract' => ''],
			],
			[['id' => 'o1', 'contractNumber' => 'K1'], ['id' => 'o2', 'contractNumber' => 'K2']],
			null,
			['s1']
		);

		$status  = $tester->execute(['--write' => true]);
		$display = $tester->getDisplay();

		self::assertSame(0, $status);
		self::assertSame(['s2'], $this->patched);
		self::assertStringContainsString('write failed s1: locked by another process', $display);
		self::assertStringContainsString('2 backfillable, 0 ambiguous, 0 unmatched, 1 written', $display);
	}

	/**
	 * Empty satellite rows are skipped and owners without an identity key are not indexed.
	 *
	 * @return void
	 */
	public function testEmptyRowsAndKeylessOwnersAreIgnored(): void {
		$tester = $this->tester(
			[[], ['id' => 's1', 'contractNumber' => 'K1', 'contract' => '']],
			[['id' => 'blank', 'contractNumber' => '  '], ['id' => 'o1', 'contractNumber' => 'K1']]
		);

		$tester->execute([]);

		self::assertStringContainsString(
			'catalogContract.contract -> shillinq.Contract via contractNumber: 0 set, 0 dangling, 1 backfillable, 0 ambiguous, 0 unmatched, 0 written',
			$tester->getDisplay()
		);
	}

	/**
	 * A reference to an owner whose identity key is blank is dangling: blank owners are not indexed.
	 *
	 * @return void
	 */
	public function testAReferenceToAKeylessOwnerIsDangling(): void {
		$tester = $this->tester(
			[['id' => 's1', 'contractNumber' => 'K9', 'contract' => 'blank']],
			[['id' => 'blank', 'contractNumber' => ''], ['id' => 'o1', 'contractNumber' => 'K1']]
		);

		$status = $tester->execute([]);

		self::assertSame(1, $status);
		self::assertStringContainsString('dangling  s1 -> blank', $tester->getDisplay());
	}
}
