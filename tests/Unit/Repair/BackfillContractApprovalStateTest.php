<?php

/**
 * Unit tests for the BackfillContractApprovalState repair step.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

require_once __DIR__ . '/../Support/SaveCallShape.php';

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Repair\BackfillContractApprovalState;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Tests\Unit\Support\SaveCallShape;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Existing contracts without an approval state get "none", and nothing else changes.
 *
 * @covers \OCA\Stackiq\Repair\BackfillContractApprovalState
 *
 * @spec openspec/specs/contract-administration/spec.md
 */
class BackfillContractApprovalStateTest extends TestCase {

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $apps;

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Build the collaborators; every run must close its progress bar exactly once.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->apps = $this->createMock(originalClassName: IAppManager::class);
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->output = $this->createMock(originalClassName: IOutput::class);
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * Run the step; every run must open and close its progress bar exactly once.
	 *
	 * @return void
	 */
	private function runStep(): void {
		$this->output->expects($this->once())->method('startProgress')->with(1);
		$this->output->expects($this->once())->method('advance')->with(1);
		$this->output->expects($this->once())->method('finishProgress');
		(new BackfillContractApprovalState(appManager: $this->apps, settingsService: $this->settings, logger: $this->logger))->run($this->output);
	}//end run()

	/**
	 * OpenRegister installed and the contract register/schema configured.
	 *
	 * @return void
	 */
	private function configured(): void {
		$this->apps->method('getInstalledApps')->willReturn(['openregister', 'stackiq']);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->settings->method('getSchemaIdForObjectType')->with('catalogContract')->willReturn(8);
		$this->settings->method('getObjectService')->willReturn($this->objects);
		$this->objects->method('setRegister')->with(5)->willReturnSelf();
		$this->objects->method('setSchema')->with(8)->willReturnSelf();
	}//end configured()

	/**
	 * A contract double.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The body.
	 *
	 * @return ObjectEntityInterface
	 */
	private function contract(string $uuid, array $data): ObjectEntityInterface {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getRegister')->willReturn('5');
		$entity->method('getSchema')->willReturn('8');
		return $entity;
	}//end contract()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new BackfillContractApprovalState(appManager: $this->apps, settingsService: $this->settings, logger: $this->logger);
		$this->assertSame(expected: 'Backfill contract approvalState=none for existing contracts', actual: $step->getName());
	}//end testName()

	/**
	 * Without OpenRegister the step skips before reading any setting.
	 *
	 * @return void
	 */
	public function testSkipsWithoutOpenRegister(): void {
		$this->apps->method('getInstalledApps')->willReturn(['stackiq']);
		$this->settings->expects($this->never())->method('getVoorzieningenRegisterId');
		$this->output->expects($this->once())->method('info')
			->with('OpenRegister not installed — skipping contract approvalState backfill');

		$this->runStep();
	}//end testSkipsWithoutOpenRegister()

	/**
	 * Without a configured contract schema the step skips.
	 *
	 * @return void
	 */
	public function testSkipsWithoutContractSchema(): void {
		$this->apps->method('getInstalledApps')->willReturn(['openregister']);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->settings->method('getSchemaIdForObjectType')->willReturn(null);
		$this->settings->expects($this->never())->method('getObjectService');
		$this->output->expects($this->once())->method('info')
			->with('Contract register/schema not configured — skipping approvalState backfill');

		$this->runStep();
	}//end testSkipsWithoutContractSchema()

	/**
	 * Without an object service the step warns and skips.
	 *
	 * @return void
	 */
	public function testWarnsWithoutObjectService(): void {
		$this->apps->method('getInstalledApps')->willReturn(['openregister']);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(5);
		$this->settings->method('getSchemaIdForObjectType')->willReturn(8);
		$this->settings->method('getObjectService')->willReturn(null);
		$this->output->expects($this->once())->method('warning')
			->with('OpenRegister ObjectService unavailable — skipping approvalState backfill');

		$this->runStep();
	}//end testWarnsWithoutObjectService()

	/**
	 * A failing read warns, logs and saves nothing.
	 *
	 * @return void
	 */
	public function testFailingReadWarnsAndSavesNothing(): void {
		$this->configured();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('timeout'));
		$this->objects->expects($this->never())->method('saveObject');
		$this->output->expects($this->once())->method('warning')->with('Could not read contract objects: timeout');
		$this->logger->expects($this->once())->method('error')
			->with('[BackfillContractApprovalState] Failed to read contracts', ['error' => 'timeout']);

		$this->runStep();
	}//end testFailingReadWarnsAndSavesNothing()

	/**
	 * Only contracts without an approval state are saved, with "none" added and the status untouched.
	 *
	 * @return void
	 */
	public function testOnlyContractsWithoutAStateAreBackfilled(): void {
		$this->configured();
		$this->objects->method('findAll')->with([], false, false)->willReturn(
			[
				$this->contract(uuid: 'blank', data: ['status' => 'Actief', 'approvalState' => '  ']),
				$this->contract(uuid: 'missing', data: ['status' => 'Concept']),
				$this->contract(uuid: 'none', data: ['approvalState' => 'none']),
				$this->contract(uuid: 'approved', data: ['approvalState' => 'approved']),
			]
		);

		$saved = [];
		$entity = $this->contract(uuid: 'x', data: []);
		$this->objects->expects($this->exactly(count: 2))->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$saved, $entity) {
				[$object, , $register, $schema, $uuid] = SaveCallShape::flat(args: $args);
				$saved[$uuid] = [$object, $register, $schema];
				return $entity;
			}
		);
		$this->output->expects($this->once())->method('info')
			->with('Contract approvalState backfill: 2 contract(s) defaulted to none');

		$this->runStep();

		$this->assertSame(
			expected: [
				'blank' => [['status' => 'Actief', 'approvalState' => 'none'], '5', '8'],
				'missing' => [['status' => 'Concept', 'approvalState' => 'none'], '5', '8'],
			],
			actual: $saved
		);
	}//end testOnlyContractsWithoutAStateAreBackfilled()

	/**
	 * A failed save is logged and not counted.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsNotCounted(): void {
		$this->configured();
		$this->objects->method('findAll')->willReturn([$this->contract(uuid: 'c1', data: [])]);
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('error')
			->with('[BackfillContractApprovalState] Failed to backfill a contract', ['uuid' => 'c1', 'error' => 'locked']);
		$this->output->expects($this->once())->method('info')
			->with('Contract approvalState backfill: 0 contract(s) defaulted to none');

		$this->runStep();
	}//end testAFailedSaveIsNotCounted()
}//end class
