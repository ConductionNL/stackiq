<?php

/**
 * Tests for the GEMMA standard links back-fill command.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Command
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Command;

use OCA\Stackiq\Command\GemmaStandardLinksCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The back-fill writes only missing links, reports, and is idempotent.
 *
 * @covers \OCA\Stackiq\Command\GemmaStandardLinksCommand
 * @uses   \OCA\Stackiq\Command\ObjectPages
 * @uses   \OCA\Stackiq\Service\ArchiMate\GemmaStandardLinker
 * @uses   \OCA\Stackiq\Service\ArchiMate\GemmaObjectReader
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class GemmaStandardLinksCommandTest extends TestCase {

	private const COMPONENT = '0b1e7c2a-0000-4000-8000-00000000c001';
	private const STANDARD  = '0b1e7c2a-0000-4000-8000-00000000a001';
	private const VERSION   = '0b1e7c2a-0000-4000-8000-00000000b001';

	/**
	 * The stored objects per schema, updated by every patch.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * The patches, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $writes = [];

	/**
	 * The reads, as [schema, rbac, multitenancy].
	 *
	 * @var array<int, array{0: string, 1: bool, 2: bool}>
	 */
	public array $reads = [];

	/**
	 * Whether a patch fails.
	 *
	 * @var bool
	 */
	public bool $failWrites = false;

	/**
	 * Seed an import saved without links.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'element'  => [
				['id' => self::COMPONENT, 'identifier' => 'id-rc1', 'gemmaType' => 'Referentiecomponent', 'name' => 'Zaaksysteem'],
				['id' => self::STANDARD, 'identifier' => 'id-s1', 'gemmaType' => 'Standaard'],
				['id' => self::VERSION, 'identifier' => 'id-v1', 'gemmaType' => 'Standaardversie'],
				['id' => 'other', 'identifier' => 'id-x', 'gemmaType' => 'Bedrijfsfunctie'],
			],
			'relation' => [
				['id' => 'r1', 'identifier' => 'id-r1', 'source' => 'id-rc1', 'target' => 'id-s1', 'verbindingsrol' => 'Verplicht', 'type' => 'Association'],
				['id' => 'r2', 'identifier' => 'id-r2', 'source' => 'id-v1', 'target' => 'id-s1', 'type' => 'Specialization'],
			],
		];
		$this->writes = [];
		$this->reads  = [];
	}//end setUp()

	/**
	 * The missing links are written, and only the link properties.
	 *
	 * @return void
	 */
	public function testTheMissingLinksAreWritten(): void {
		$tester = $this->tester();
		$tester->execute([]);

		self::assertSame(
			[
				[
					'id'       => self::COMPONENT,
					'data'     => [
						'recommendedStandards'   => [],
						'mandatoryStandards'     => [self::STANDARD],
						'standards'              => [self::STANDARD],
						'linkedStandardVersions' => [self::VERSION],
					],
					'register' => 'vng-gemma',
					'schema'   => 'element',
					'_rbac'    => false,
					'_multitenancy' => false,
				],
				[
					'id'       => self::VERSION,
					'data'     => ['standard' => self::STANDARD],
					'register' => 'vng-gemma',
					'schema'   => 'element',
					'_rbac'    => false,
					'_multitenancy' => false,
				],
			],
			$this->writes
		);
		self::assertStringContainsString('6 GEMMA objects read, 2 need links, 2 written, 0 failed', $tester->getDisplay());
		self::assertSame(0, $tester->getStatusCode());
		self::assertSame([['element', false, false], ['relation', false, false]], $this->reads);
	}//end testTheMissingLinksAreWritten()

	/**
	 * A verbose run lists every change, then the summary; a dry run marks its summary.
	 *
	 * @return void
	 */
	public function testTheVerboseDisplayListsEveryChangeThenTheSummary(): void {
		$listing = '  '.self::COMPONENT.': recommendedStandards, mandatoryStandards, standards, linkedStandardVersions'.PHP_EOL
			.'  '.self::VERSION.': standard'.PHP_EOL;

		$tester = $this->tester();
		$tester->execute(['--dry-run' => true], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);
		self::assertSame($listing.'6 GEMMA objects read, 2 need links, 0 written, 0 failed (dry run)'.PHP_EOL, $tester->getDisplay());

		$this->failWrites = true;
		$tester = $this->tester();
		$tester->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);
		self::assertSame($listing.'6 GEMMA objects read, 2 need links, 0 written, 2 failed'.PHP_EOL, $tester->getDisplay());

		$this->failWrites = false;
		$tester = $this->tester();
		$tester->execute([]);
		self::assertSame('6 GEMMA objects read, 2 need links, 2 written, 0 failed'.PHP_EOL, $tester->getDisplay());
	}//end testTheVerboseDisplayListsEveryChangeThenTheSummary()

	/**
	 * A second run finds nothing to write.
	 *
	 * @return void
	 */
	public function testASecondRunWritesNothing(): void {
		$this->tester()->execute([]);
		$this->writes = [];

		$tester = $this->tester();
		$tester->execute([]);

		self::assertSame([], $this->writes);
		self::assertStringContainsString('0 need links, 0 written', $tester->getDisplay());
	}//end testASecondRunWritesNothing()

	/**
	 * Links already stored in another order are left alone.
	 *
	 * @return void
	 */
	public function testStoredLinksInAnotherOrderAreLeftAlone(): void {
		$other = '0b1e7c2a-0000-4000-8000-00000000a002';
		$this->rows['element'][]  = ['id' => $other, 'identifier' => 'id-s2', 'gemmaType' => 'Standaard'];
		$this->rows['relation'][] = ['id' => 'r3', 'identifier' => 'id-r3', 'source' => 'id-s2', 'target' => 'id-rc1', 'verbindingsrol' => 'Verplicht'];
		$this->rows['element'][0] += [
			'recommendedStandards'   => [],
			'mandatoryStandards'     => [$other, self::STANDARD],
			'standards'              => [$other, self::STANDARD],
			'linkedStandardVersions' => [self::VERSION],
		];
		$this->rows['element'][2]['standard'] = self::STANDARD;

		$tester = $this->tester();
		$tester->execute([]);

		self::assertSame([], $this->writes);
	}//end testStoredLinksInAnotherOrderAreLeftAlone()

	/**
	 * A dry run reports and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$tester = $this->tester();
		$tester->execute(['--dry-run' => true], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

		self::assertSame([], $this->writes);
		self::assertStringContainsString('2 need links, 0 written, 0 failed (dry run)', $tester->getDisplay());
		self::assertStringContainsString(self::VERSION.': standard', $tester->getDisplay());
		self::assertSame(0, $tester->getStatusCode());
	}//end testADryRunWritesNothing()

	/**
	 * A failed write is counted and fails the run.
	 *
	 * @return void
	 */
	public function testAFailedWriteFailsTheRun(): void {
		$this->failWrites = true;
		$tester = $this->tester();
		$tester->execute([]);

		self::assertStringContainsString('2 need links, 0 written, 2 failed', $tester->getDisplay());
		self::assertSame(1, $tester->getStatusCode());
	}//end testAFailedWriteFailsTheRun()

	/**
	 * An unreadable register fails the run without writing.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterFailsTheRun(): void {
		$this->rows = [];
		$tester = $this->tester();
		$tester->execute([]);

		self::assertSame([], $this->writes);
		self::assertStringContainsString('could not read the GEMMA objects', $tester->getDisplay());
		self::assertSame(1, $tester->getStatusCode());
	}//end testAnUnreadableRegisterFailsTheRun()

	/**
	 * The command over a fake ObjectService that serves and patches $this->rows.
	 *
	 * @return CommandTester The tester.
	 */
	private function tester(): CommandTester {
		$test = $this;
		$objectService = new class($test) {
			private string $schema = '';

			/**
			 * @param GemmaStandardLinksCommandTest $test The test holding the rows.
			 */
			public function __construct(private GemmaStandardLinksCommandTest $test) {
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
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				if (isset($this->test->rows[$this->schema]) === false) {
					throw new RuntimeException('no such schema');
				}

				$this->test->reads[] = [$this->schema, $_rbac, $_multitenancy];
				return array_slice($this->test->rows[$this->schema], $config['offset'], $config['limit']);
			}

			/**
			 * @param string      $objectId      The object id.
			 * @param array       $data          The patch.
			 * @param string|null $register      The register.
			 * @param string|null $schema        The schema.
			 * @param bool        $_rbac         RBAC flag.
			 * @param bool        $_multitenancy Multitenancy flag.
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
				if ($this->test->failWrites === true) {
					throw new RuntimeException('write refused');
				}

				$this->test->writes[] = ['id' => $objectId] + compact('data', 'register', 'schema', '_rbac', '_multitenancy');
				foreach ($this->test->rows[$schema] as $index => $row) {
					if ($row['id'] === $objectId) {
						$this->test->rows[$schema][$index] = $data + $row;
					}
				}

				return $data;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new CommandTester(new GemmaStandardLinksCommand($container, new NullLogger()));
	}//end tester()
}//end class
