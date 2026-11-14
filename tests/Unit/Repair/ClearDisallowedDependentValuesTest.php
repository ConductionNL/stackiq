<?php

/**
 * Tests for the step that clears values a dependent value table refuses.
 *
 * @spec openspec/changes/landscape-dependent-field-options/specs/dependent-field-options/spec.md#requirement-req-dfo-002-existing-rows-that-break-a-table-are-cleaned-before-it-applies
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Repair\ClearDisallowedDependentValues;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Stackiq\Repair\ClearDisallowedDependentValues
 */
final class ClearDisallowedDependentValuesTest extends TestCase {

	/**
	 * App config values set during the test.
	 *
	 * @var array<string, bool>
	 */
	private array $settings = [];

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Saves made, as [data, uuid, register, schema].
	 *
	 * @var list<array{0: array<string, mixed>, 1: string|null, 2: int|string|null, 3: int|string|null}>
	 */
	private array $saves = [];

	/**
	 * Lines written to the repair output.
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * The step over these modules and organisations, with the shipped tables.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows Data per object type.
	 * @param bool                                      $failSave Whether a save throws.
	 *
	 * @return ClearDisallowedDependentValues
	 */
	private function step(array $rows, bool $failSave = false): ClearDisallowedDependentValues {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'stackiq']);

		$current = null;
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnCallback(
			function (string|int $schema) use (&$current): ObjectServiceInterface {
				$current = $schema;
				return $this->objects;
			}
		);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$current, $rows): array {
				$type = ($current === 43) ? 'module' : 'organization';
				$page = array_slice(($rows[$type] ?? []), $config['offset'], $config['limit']);
				return array_map(function (array $data): ObjectEntityInterface {
					$entity = $this->createMock(ObjectEntityInterface::class);
					$entity->method('getObject')->willReturn($data);
					$entity->method('getUuid')->willReturn($data['id']);
					return $entity;
				}, $page);
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use ($failSave): object {
				if ($failSave === true) {
					throw new \RuntimeException('refused');
				}
				$this->saves[] = [$object, $uuid, $register, $schema];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getRegisterIdForObjectType', 'getSchemaIdForObjectType', 'getObjectService'])
			->getMock();
		$settings->method('getRegisterIdForObjectType')->willReturn(20);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(static fn (string $type): int => ($type === 'module') ? 43 : 44);
		$settings->method('getObjectService')->willReturn($this->objects);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool) ($this->settings[$key] ?? $default));
		$config->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->settings[$key] = $value;
				return true;
			}
		);

		return new ClearDisallowedDependentValues(appManager: $apps, settingsService: $settings, appConfig: $config);
	}//end step()

	/**
	 * A repair output that records its lines.
	 *
	 * @return IOutput
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->lines[] = $line;
		});
		$output->method('warning')->willReturnCallback(function (string $line): void {
			$this->lines[] = 'WARNING ' . $line;
		});

		return $output;
	}//end output()

	/**
	 * The scenario of REQ-DFO-002, and its module twin: the refused values are cleared, everything else is kept.
	 *
	 * @return void
	 */
	public function testAClosedSourceModulesLicenceAndAMunicipalitysCollaborationTypeAreCleared(): void {
		$step = $this->step([
			'module' => [
				['id' => 'm1', 'name' => 'Closed', 'licentietype' => 'Closed source', 'licence' => 'MIT License'],
				['id' => 'm2', 'name' => 'Open', 'licentietype' => 'Open source', 'licence' => 'MIT License'],
				['id' => 'm3', 'name' => 'Closed, none', 'licentietype' => 'Closed source', 'licence' => null],
			],
			'organization' => [
				['id' => 'o1', 'name' => 'Gemeente A', 'type' => 'Municipality', 'samenwerkingtype' => 'Shared Service Center'],
				['id' => 'o2', 'name' => 'Samenwerking B', 'type' => 'Collaboration', 'samenwerkingtype' => 'Shared Service Center'],
			],
		]);

		$step->run($this->repairOutput());

		$this->assertSame(
			[
				[['id' => 'm1', 'name' => 'Closed', 'licentietype' => 'Closed source', 'licence' => null], 'm1', 20, 43],
				[['id' => 'o1', 'name' => 'Gemeente A', 'type' => 'Municipality', 'samenwerkingtype' => null], 'o1', 20, 44],
			],
			$this->saves
		);
		$this->assertContains('2 stored value(s) cleared that a dependent value table does not allow.', $this->lines);
		$this->assertContains('1 module value(s) of licence cleared: not allowed for their licentietype.', $this->lines);
		$this->assertTrue($this->settings[ClearDisallowedDependentValues::DONE_CONFIG_KEY]);
	}//end testAClosedSourceModulesLicenceAndAMunicipalitysCollaborationTypeAreCleared()

	/**
	 * A collaboration type outside its row is cleared even for a collaboration.
	 *
	 * @return void
	 */
	public function testAValueOutsideTheRowIsClearedAndAnUnlistedControllerLeavesItFree(): void {
		$step = $this->step([
			'organization' => [
				['id' => 'o1', 'type' => 'Collaboration', 'samenwerkingtype' => 'samenwerkingtype'],
				['id' => 'o2', 'type' => 'Something new', 'samenwerkingtype' => 'Shared Service Center'],
			],
		]);

		$step->run($this->repairOutput());

		$this->assertCount(1, $this->saves);
		$this->assertSame('o1', $this->saves[0][1]);
		$this->assertNull($this->saves[0][0]['samenwerkingtype']);
	}//end testAValueOutsideTheRowIsClearedAndAnUnlistedControllerLeavesItFree()

	/**
	 * Objects are read in pages until a short page.
	 *
	 * @return void
	 */
	public function testObjectsAreReadPageByPage(): void {
		$modules = [];
		for ($i = 0; $i < ClearDisallowedDependentValues::PAGE_SIZE + 1; $i++) {
			$modules[] = ['id' => 'm' . $i, 'licentietype' => 'Closed source', 'licence' => 'MIT License'];
		}

		$this->step(['module' => $modules])->run($this->repairOutput());

		$this->assertCount(ClearDisallowedDependentValues::PAGE_SIZE + 1, $this->saves);
	}//end testObjectsAreReadPageByPage()

	/**
	 * A failed save is named and not recorded as a pass; a recorded pass is skipped.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsRetriedNextTimeAndARecordedPassIsSkipped(): void {
		$rows = ['module' => [['id' => 'm1', 'licentietype' => 'Closed source', 'licence' => 'MIT License']]];
		$this->step($rows, failSave: true)->run($this->repairOutput());

		$this->assertContains('WARNING 1 object(s) or searches failed; the next upgrade tries again.', $this->lines);
		$this->assertArrayNotHasKey(ClearDisallowedDependentValues::DONE_CONFIG_KEY, $this->settings);

		$this->settings[ClearDisallowedDependentValues::DONE_CONFIG_KEY] = true;
		$step = $this->step($rows);
		$this->objects->expects($this->never())->method('findAll');
		$step->run($this->repairOutput());
	}//end testAFailedSaveIsRetriedNextTimeAndARecordedPassIsSkipped()

	/**
	 * The tables are read from the shipped fragment.
	 *
	 * @return void
	 */
	public function testTheShippedFragmentHoldsBothTables(): void {
		$fragment = json_decode((string) file_get_contents(ClearDisallowedDependentValues::TABLES_FILE), true);
		$tables   = ClearDisallowedDependentValues::tables($fragment);

		$this->assertSame(
			[['module', 'licence', 'licentietype'], ['organization', 'samenwerkingtype', 'type']],
			array_map(static fn (array $t): array => [$t['type'], $t['field'], $t['controlledBy']], $tables)
		);
		$this->assertSame([], ClearDisallowedDependentValues::tables(['components' => ['schemas' => ['module' => ['properties' => ['x' => 'not a property']]]]]));
	}//end testTheShippedFragmentHoldsBothTables()
}//end class
