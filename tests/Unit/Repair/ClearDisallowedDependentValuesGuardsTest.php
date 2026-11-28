<?php

/**
 * Tests for the guards of the step that clears values a dependent value table refuses:
 * when it does not run, an unreadable table file, an unknown object type, a failing
 * search and rows that are not objects.
 *
 * @spec openspec/changes/archive/2026-11-24-landscape-dependent-field-options/specs/dependent-field-options/spec.md#requirement-req-dfo-002-existing-rows-that-break-a-table-are-cleaned-before-it-applies
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Repair\ClearDisallowedDependentValues;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Stackiq\Repair\ClearDisallowedDependentValues
 */
final class ClearDisallowedDependentValuesGuardsTest extends TestCase {

	/**
	 * A fragment with one table: a module's licence depends on its source.
	 *
	 * @var array<string, mixed>
	 */
	private const FRAGMENT = [
		'components' => [
			'schemas' => [
				'module' => [
					'properties' => [
						'licence' => [
							'x-openregister-dependent-values' => [
								'controlledBy' => 'source',
								'allowed'      => ['closed' => ['proprietary']],
							],
						],
					],
				],
			],
		],
	];

	/**
	 * Lines written to the repair output.
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * Config values written during the test.
	 *
	 * @var array<string, bool>
	 */
	private array $written = [];

	/**
	 * Temporary files to remove after the test.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	/**
	 * Remove the temporary table files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A table file holding the given text.
	 *
	 * @param string $text The file's content.
	 *
	 * @return string Its path.
	 */
	private function tablesFile(string $text): string {
		$file = tempnam(sys_get_temp_dir(), 'dfo');
		file_put_contents($file, $text);
		$this->files[] = $file;
		return $file;
	}//end tablesFile()

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
	}//end repairOutput()

	/**
	 * An app config that records what the step writes.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturn(false);
		$config->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->written[$key] = $value;
				return true;
			}
		);
		return $config;
	}//end appConfig()

	/**
	 * The step over the given apps, settings reader and table file.
	 *
	 * @param list<string>                  $apps      Installed apps.
	 * @param RegisterConfigReaderInterface $reader    The settings reader.
	 * @param string                        $tableFile The table file.
	 *
	 * @return ClearDisallowedDependentValues
	 */
	private function step(array $apps, RegisterConfigReaderInterface $reader, string $tableFile): ClearDisallowedDependentValues {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($apps);

		return new ClearDisallowedDependentValues(
			appManager: $appManager,
			settingsService: $reader,
			appConfig: $this->appConfig(),
			tablesFile: $tableFile
		);
	}//end step()

	/**
	 * A settings reader returning this object service and these ids.
	 *
	 * @param ObjectServiceInterface|null $objects  The object service.
	 * @param int|null                    $register The register id.
	 * @param int|null                    $schema   The schema id.
	 *
	 * @return RegisterConfigReaderInterface&MockObject
	 */
	private function reader(?ObjectServiceInterface $objects, ?int $register = 20, ?int $schema = 43): RegisterConfigReaderInterface&MockObject {
		$reader = $this->createMock(RegisterConfigReaderInterface::class);
		$reader->method('getObjectService')->willReturn($objects);
		$reader->method('getRegisterIdForObjectType')->willReturn($register);
		$reader->method('getSchemaIdForObjectType')->willReturn($schema);
		return $reader;
	}//end reader()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItClears(): void {
		$step = $this->step(apps: [], reader: $this->reader(objects: null), tableFile: $this->tablesFile(text: '{}'));

		self::assertSame('Clear stored values that a dependent value table does not allow', $step->getName());
	}//end testTheStepNamesWhatItClears()

	/**
	 * Without OpenRegister installed nothing is read and the pass is not recorded.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsCheckedAndThePassIsNotRecorded(): void {
		$reader = $this->reader(objects: null);
		$reader->expects(self::never())->method('getObjectService');

		$this->step(apps: ['stackiq'], reader: $reader, tableFile: $this->tablesFile(text: '{}'))->run($this->repairOutput());

		self::assertSame(['OpenRegister not installed, so there are no stored values to check.'], $this->lines);
		self::assertSame([], $this->written);
	}//end testWithoutOpenRegisterNothingIsCheckedAndThePassIsNotRecorded()

	/**
	 * When OpenRegister gives no object service nothing is read and the pass is not recorded.
	 *
	 * @return void
	 */
	public function testAnUnreachableOpenRegisterChecksNothingAndDoesNotRecordThePass(): void {
		$reader = $this->reader(objects: null);
		$reader->expects(self::never())->method('getRegisterIdForObjectType');

		$this->step(apps: ['openregister'], reader: $reader, tableFile: $this->tablesFile(text: '{}'))->run($this->repairOutput());

		self::assertSame(['OpenRegister is not reachable, so no stored values were checked.'], $this->lines);
		self::assertSame([], $this->written);
	}//end testAnUnreachableOpenRegisterChecksNothingAndDoesNotRecordThePass()

	/**
	 * A missing table file means no tables: nothing is searched and the pass is recorded.
	 *
	 * @return void
	 */
	public function testAMissingTableFileSearchesNothingAndRecordsThePass(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::never())->method('findAll');
		$missing = sys_get_temp_dir() . '/dfo-missing-' . bin2hex(random_bytes(6)) . '.json';

		$this->step(apps: ['openregister'], reader: $this->reader(objects: $objects), tableFile: $missing)->run($this->repairOutput());

		self::assertSame(['0 stored value(s) cleared that a dependent value table does not allow.'], $this->lines);
		self::assertSame([ClearDisallowedDependentValues::DONE_CONFIG_KEY => true], $this->written);
	}//end testAMissingTableFileSearchesNothingAndRecordsThePass()

	/**
	 * A table file that is not a JSON object means no tables.
	 *
	 * @return void
	 */
	public function testATableFileThatIsNotAJsonObjectSearchesNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::never())->method('findAll');

		$this->step(apps: ['openregister'], reader: $this->reader(objects: $objects), tableFile: $this->tablesFile(text: 'not json'))
			->run($this->repairOutput());

		self::assertSame(['0 stored value(s) cleared that a dependent value table does not allow.'], $this->lines);
		self::assertSame([ClearDisallowedDependentValues::DONE_CONFIG_KEY => true], $this->written);
	}//end testATableFileThatIsNotAJsonObjectSearchesNothing()

	/**
	 * A table whose object type has no register or schema configured is skipped, not a failure.
	 *
	 * @return void
	 */
	public function testATableWithoutAConfiguredSchemaIsSkippedAndThePassRecorded(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::never())->method('findAll');

		$this->step(
			apps: ['openregister'],
			reader: $this->reader(objects: $objects, register: 20, schema: null),
			tableFile: $this->tablesFile(text: (string) json_encode(self::FRAGMENT))
		)->run($this->repairOutput());

		self::assertSame(['0 stored value(s) cleared that a dependent value table does not allow.'], $this->lines);
		self::assertSame([ClearDisallowedDependentValues::DONE_CONFIG_KEY => true], $this->written);
	}//end testATableWithoutAConfiguredSchemaIsSkippedAndThePassRecorded()

	/**
	 * A failing search counts as a failure: a warning, and the pass is not recorded.
	 *
	 * @return void
	 */
	public function testAFailingSearchWarnsAndLeavesThePassForTheNextUpgrade(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::once())->method('setRegister')->with(20)->willReturnSelf();
		$objects->expects(self::once())->method('setSchema')->with(43)->willReturnSelf();
		$objects->expects(self::once())->method('findAll')->willThrowException(new RuntimeException('search down'));
		$objects->expects(self::never())->method('saveObject');

		$this->step(
			apps: ['openregister'],
			reader: $this->reader(objects: $objects),
			tableFile: $this->tablesFile(text: (string) json_encode(self::FRAGMENT))
		)->run($this->repairOutput());

		self::assertSame(
			[
				'0 stored value(s) cleared that a dependent value table does not allow.',
				'WARNING 1 object(s) or searches failed; the next upgrade tries again.',
			],
			$this->lines
		);
		self::assertSame([], $this->written);
	}//end testAFailingSearchWarnsAndLeavesThePassForTheNextUpgrade()

	/**
	 * Rows that are not objects are passed over; a refused value on a real object is still cleared.
	 *
	 * @return void
	 */
	public function testRowsThatAreNotObjectsArePassedOver(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn(['id' => 'm-1', 'source' => 'closed', 'licence' => 'EUPL-1.2']);
		$entity->method('getUuid')->willReturn('m-1');

		$saved   = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->expects(self::once())->method('findAll')->willReturn([['id' => 'raw'], 'text', $entity]);
		$objects->expects(self::once())->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntityInterface {
				$saved[] = $object;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$this->step(
			apps: ['openregister'],
			reader: $this->reader(objects: $objects),
			tableFile: $this->tablesFile(text: (string) json_encode(self::FRAGMENT))
		)->run($this->repairOutput());

		self::assertSame([['id' => 'm-1', 'source' => 'closed', 'licence' => null]], $saved);
		self::assertSame(
			[
				'1 module value(s) of licence cleared: not allowed for their source.',
				'1 stored value(s) cleared that a dependent value table does not allow.',
			],
			$this->lines
		);
		self::assertSame([ClearDisallowedDependentValues::DONE_CONFIG_KEY => true], $this->written);
	}//end testRowsThatAreNotObjectsArePassedOver()
}//end class
