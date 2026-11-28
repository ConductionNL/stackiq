<?php

/**
 * BackfillRecordStatusTest
 *
 * operations-record-reconciliation task 1: on upgrade every existing
 * application and service without a record status becomes Active, once.
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
 * @spec openspec/changes/archive/2026-11-25-operations-record-reconciliation/tasks.md#task-1-declare-the-duplicate-and-merge-rules-and-the-record-status
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Repair\BackfillRecordStatus;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for BackfillRecordStatus.
 */
class BackfillRecordStatusTest extends TestCase {

	/**
	 * Captured saves: [schema id, uuid, data].
	 *
	 * @var array<int, array{0: int, 1: string, 2: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Whether the done marker was set.
	 *
	 * @var bool
	 */
	private bool $done = false;

	/**
	 * A stored row.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function row(string $uuid, array $data): ObjectEntityInterface {
		$row = $this->createMock(ObjectEntityInterface::class);
		$row->method('getUuid')->willReturn($uuid);
		$row->method('getObject')->willReturn($data);
		return $row;
	}//end row()

	/**
	 * The step over rows per schema id.
	 *
	 * @param array<int, array<int, ObjectEntityInterface>> $rows      Rows per schema id.
	 * @param bool                                          $alreadyDone Whether the marker is set.
	 * @param bool                                          $saveFails Whether saving throws.
	 *
	 * @return BackfillRecordStatus
	 */
	private function step(array $rows, bool $alreadyDone = false, bool $saveFails = false): BackfillRecordStatus {
		$schema = 0;
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $id) use (&$schema, $objects): ObjectServiceInterface {
				$schema = (int)$id;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$schema, $rows): array {
				return array_slice(($rows[$schema] ?? []), (int)$config['offset'], (int)$config['limit']);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options) use ($saveFails): ObjectEntityInterface {
				if ($saveFails === true) {
					throw new RuntimeException('locked');
				}

				$this->saved[] = [(int)$options->target->schema, (string)$options->uuid, $object];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(static fn (string $type): ?int => ['module' => 7, 'catalogService' => 8][$type] ?? null);
		$settings->method('getRegisterIdForObjectType')->willReturn(100);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturn($alreadyDone);
		$config->method('setValueBool')->willReturnCallback(
			function (): bool {
				$this->done = true;
				return true;
			}
		);

		return new BackfillRecordStatus(appManager: $apps, settings: $settings, appConfig: $config);
	}//end step()

	/**
	 * Rows without a status become Active; rows with one are left alone.
	 *
	 * @return void
	 */
	public function testRowsWithoutAStatusBecomeActive(): void {
		$this->step(rows: [
			7 => [$this->row(uuid: 'm1', data: ['name' => 'A']), $this->row(uuid: 'm2', data: ['name' => 'B', 'recordStatus' => 'Merged'])],
			8 => [$this->row(uuid: 's1', data: ['name' => 'C', 'recordStatus' => ''])],
		])->run($this->createMock(IOutput::class));

		$this->assertSame(
			[[7, 'm1', ['name' => 'A', 'recordStatus' => 'Active']], [8, 's1', ['name' => 'C', 'recordStatus' => 'Active']]],
			$this->saved
		);
		$this->assertTrue($this->done);
	}//end testRowsWithoutAStatusBecomeActive()

	/**
	 * It reads every page.
	 *
	 * @return void
	 */
	public function testItReadsEveryPage(): void {
		$rows = [];
		for ($i = 0; $i < BackfillRecordStatus::PAGE_SIZE + 3; $i++) {
			$rows[] = $this->row(uuid: 'm'.$i, data: []);
		}

		$this->step(rows: [7 => $rows])->run($this->createMock(IOutput::class));

		$this->assertCount(BackfillRecordStatus::PAGE_SIZE + 3, $this->saved);
	}//end testItReadsEveryPage()

	/**
	 * A done marker skips it; a failed save keeps the marker off.
	 *
	 * @return void
	 */
	public function testItRunsOnceAndRetriesAfterAFailure(): void {
		$this->step(rows: [7 => [$this->row(uuid: 'm1', data: [])]], alreadyDone: true)->run($this->createMock(IOutput::class));
		$this->assertSame([], $this->saved);
		$this->assertFalse($this->done);

		$this->step(rows: [7 => [$this->row(uuid: 'm1', data: [])]], saveFails: true)->run($this->createMock(IOutput::class));
		$this->assertFalse($this->done);
	}//end testItRunsOnceAndRetriesAfterAFailure()

	/**
	 * A row that is not an object entity is not marked.
	 *
	 * @return void
	 */
	public function testOnlyObjectEntitiesAreMarked(): void {
		$this->assertNull(BackfillRecordStatus::marked(row: ['recordStatus' => null]));
		$this->assertSame(['recordStatus' => 'Active'], BackfillRecordStatus::marked(row: $this->row(uuid: 'x', data: [])));
	}//end testOnlyObjectEntitiesAreMarked()

	/**
	 * Without OpenRegister there is nothing to mark: the step says so and is not marked done.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsMarked(): void {
		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->expects($this->never())->method('getObjectService');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['files', 'stackiq']);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturn(false);
		$config->expects($this->never())->method('setValueBool');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('OpenRegister not installed, so there are no records to mark.');

		(new BackfillRecordStatus(appManager: $apps, settings: $settings, appConfig: $config))->run($output);
	}//end testWithoutOpenRegisterNothingIsMarked()

	/**
	 * A schema whose id does not resolve is skipped; the other schema is still marked.
	 *
	 * @return void
	 */
	public function testASchemaWithoutAnIdIsSkipped(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		// Only the schema that resolves is read. Recorded rather than expected:
		// the step reads inside a catch-all, which would swallow a failed expectation.
		$schemasRead = [];
		$objects->method('setSchema')->willReturnCallback(
			function (mixed $id) use (&$schemasRead, $objects): ObjectServiceInterface {
				$schemasRead[] = $id;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturn([$this->row(uuid: 's1', data: ['name' => 'C'])]);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntityInterface {
				$this->saved[] = [(int)$options->target->schema, (string)$options->uuid, $object];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getRegisterIdForObjectType')->willReturn(100);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(static fn (string $type): ?int => ['catalogService' => 8][$type] ?? null);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturn(false);

		(new BackfillRecordStatus(appManager: $apps, settings: $settings, appConfig: $config))->run($this->createMock(IOutput::class));

		$this->assertSame([8], $schemasRead);
		$this->assertSame([[8, 's1', ['name' => 'C', 'recordStatus' => 'Active']]], $this->saved);
	}//end testASchemaWithoutAnIdIsSkipped()

	/**
	 * A page that cannot be read marks nothing and does not stop the step.
	 *
	 * @return void
	 */
	public function testAPageThatCannotBeReadMarksNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->expects($this->exactly(2))->method('findAll')->willThrowException(new RuntimeException('register offline'));
		$objects->expects($this->never())->method('saveObject');
		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getRegisterIdForObjectType')->willReturn(100);
		$settings->method('getSchemaIdForObjectType')->willReturn(7);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturn(false);

		(new BackfillRecordStatus(appManager: $apps, settings: $settings, appConfig: $config))->run($this->createMock(IOutput::class));
	}//end testAPageThatCannotBeReadMarksNothing()
}//end class
