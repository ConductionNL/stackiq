<?php

/**
 * Unit tests for the SeedRolesFromGroups repair step.
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
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\SeedRolesFromGroups;
use OCA\Stackiq\Service\RoleGrantSeeder;
use OCA\Stackiq\Service\RoleGroupMap;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The upgrade step seeds the roles once and never aborts the upgrade.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 */
#[CoversClass(SeedRolesFromGroups::class)]
final class SeedRolesFromGroupsTest extends TestCase {

	/**
	 * The seeder double.
	 *
	 * @var RoleGrantSeeder&MockObject
	 */
	private RoleGrantSeeder&MockObject $seeder;

	/**
	 * The role map double.
	 *
	 * @var RoleGroupMap&MockObject
	 */
	private RoleGroupMap&MockObject $roleGroupMap;

	/**
	 * The upgrade output, as [level, message].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $output = [];

	/**
	 * Run the step.
	 *
	 * @return void
	 */
	private function runStep(): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->output[] = ['info', $message];
		});
		$output->method('warning')->willReturnCallback(function (string $message): void {
			$this->output[] = ['warning', $message];
		});

		(new SeedRolesFromGroups(
			seeder: $this->seeder,
			roleGroupMap: $this->roleGroupMap,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		))
			->run($output);
	}//end runStep()

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seeder = $this->createMock(originalClassName: RoleGrantSeeder::class);
		$this->roleGroupMap = $this->createMock(originalClassName: RoleGroupMap::class);
	}//end setUp()

	/**
	 * A seeded instance is not seeded again.
	 *
	 * @return void
	 */
	public function testAnAlreadySeededInstanceIsLeftAlone(): void {
		$this->roleGroupMap->method('grantsSeeded')->willReturn(true);
		$this->seeder->expects($this->never())->method('seed');

		$this->runStep();

		$this->assertSame(expected: [], actual: $this->output);
	}//end testAnAlreadySeededInstanceIsLeftAlone()

	/**
	 * An unseeded instance is seeded and the counts are reported.
	 *
	 * @return void
	 */
	public function testTheSeedingIsRunAndReported(): void {
		$this->roleGroupMap->method('grantsSeeded')->willReturn(false);
		$this->seeder->expects($this->once())->method('seed')->willReturn(
			['written' => 2, 'unchanged' => 3, 'withoutContactPerson' => 1, 'failed' => 0, 'seeded' => true]
		);

		$this->runStep();

		$this->assertSame(
			expected: [
				['info', 'Role groups: 2 contact persons got their role group roles, 3 were already complete, 1 members have no contact person.'],
			],
			actual: $this->output
		);
	}//end testTheSeedingIsRunAndReported()

	/**
	 * Failed writes are reported as a warning that the sync stays off.
	 *
	 * @return void
	 */
	public function testFailedWritesWarnThatTheSyncStaysOff(): void {
		$this->roleGroupMap->method('grantsSeeded')->willReturn(false);
		$this->seeder->method('seed')->willReturn(
			['written' => 0, 'unchanged' => 0, 'withoutContactPerson' => 0, 'failed' => 4, 'seeded' => false]
		);

		$this->runStep();

		$this->assertSame(expected: 'warning', actual: $this->output[1][0]);
		$this->assertStringContainsString(needle: '4 contact persons could not be written', haystack: $this->output[1][1]);
	}//end testFailedWritesWarnThatTheSyncStaysOff()

	/**
	 * A seeder that throws, for example before the register is imported, does not abort the upgrade.
	 *
	 * @return void
	 */
	public function testAThrowingSeederDoesNotAbortTheUpgrade(): void {
		$this->roleGroupMap->method('grantsSeeded')->willReturn(false);
		$this->seeder->method('seed')->willThrowException(new RuntimeException('not configured'));

		$this->runStep();

		$this->assertSame(expected: [['warning', 'Role groups: the roles could not be seeded yet; the nightly job retries.']], actual: $this->output);
	}//end testAThrowingSeederDoesNotAbortTheUpgrade()

	/**
	 * The step names itself in the upgrade output.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItDoes(): void {
		$step = new SeedRolesFromGroups(
			seeder: $this->seeder,
			roleGroupMap: $this->roleGroupMap,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(expected: 'Stackiq: write the role of each role group member onto their contact person', actual: $step->getName());
	}//end testTheStepNamesWhatItDoes()
}//end class
