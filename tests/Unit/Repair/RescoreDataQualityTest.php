<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Repair\RescoreDataQuality;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * REQ-CDQ-001: entries saved before the rules existed get a score once.
 *
 * @covers \OCA\Stackiq\Repair\RescoreDataQuality
 */
final class RescoreDataQualityTest extends TestCase {
	/** @var ObjectServiceInterface&MockObject */
	private ObjectServiceInterface&MockObject $objects;

	/** @var array<string, bool> */
	private array $settings = [];

	/** @var array<int, array{schema: int|string|null, uuid: ?string, object: array<string, mixed>}> */
	private array $saves = [];

	/** @var list<string> */
	private array $infos = [];

	/** @var array<int, array<int, ObjectEntityInterface>> Entries per schema id. */
	private array $entries = [];

	/** @var int|null */
	private ?int $usageSchema = 44;

	/** @var int|null */
	private ?int $currentSchema = null;

	/**
	 * The entry.
	 *
	 * @param string $uuid The uuid.
	 * @param array  $data The data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entry(string $uuid, array $data): ObjectEntityInterface {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entry()

	/**
	 * The step.
	 *
	 * @param bool $saveFails The save fails.
	 *
	 * @return RescoreDataQuality
	 */
	private function step(bool $saveFails = false): RescoreDataQuality {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'stackiq']);

		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnCallback(
			function (string|int $schema): ObjectServiceInterface {
				$this->currentSchema = (int) $schema;
				return $this->objects;
			}
		);
		$this->objects->method('findAll')->willReturnCallback(
			fn (array $config): array => array_slice($this->entries[$this->currentSchema] ?? [], $config['offset'], $config['limit'])
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use ($saveFails): ObjectEntityInterface {
				$schema = $options->target->schema;
				$uuid   = $options->uuid;
				if ($saveFails === true) {
					throw new \RuntimeException('validation failed');
				}

				$this->saves[] = ['schema' => $schema, 'uuid' => $uuid, 'object' => $object];
				return $this->createMock(originalClassName: ObjectEntityInterface::class);
			}
		);

		$settings = $this->getMockBuilder(className: SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getRegisterIdForObjectType', 'getSchemaIdForObjectType', 'getObjectService'])
			->getMock();
		$settings->method('getRegisterIdForObjectType')->willReturn(20);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			fn (string $type): ?int => match ($type) {
				'module' => 43,
				'usage' => $this->usageSchema,
				default => null,
			}
		);
		$settings->method('getObjectService')->willReturn($this->objects);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => ($this->settings[$key] ?? $default)
		);
		$config->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->settings[$key] = $value;
				return true;
			}
		);

		return new RescoreDataQuality(appManager: $apps, settingsService: $settings, appConfig: $config);
	}//end step()

	/**
	 * The upgrade output.
	 *
	 * @return IOutput
	 */
	private function upgradeOutput(): IOutput {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->infos[] = $message;
		});
		return $output;
	}//end upgradeOutput()

	/**
	 * Every unscored entry is saved once and the count logged.
	 *
	 * @return void
	 */
	public function testEveryUnscoredEntryIsSavedOnceAndTheCountLogged(): void {
		$this->entries[43] = [
			$this->entry(uuid: 'm-1', data: ['name' => 'Zaaksysteem', '@self' => ['id' => 'm-1'], 'id' => 'm-1']),
			$this->entry(uuid: 'm-2', data: ['name' => 'Scored', 'qualityScore' => 0.7]),
		];
		$this->entries[44] = [$this->entry(uuid: 'u-1', data: ['module' => 'm-1'])];

		$this->step()->run($this->upgradeOutput());

		self::assertSame(
			expected: [
				['schema' => 43, 'uuid' => 'm-1', 'object' => ['name' => 'Zaaksysteem']],
				['schema' => 44, 'uuid' => 'u-1', 'object' => ['module' => 'm-1']],
			],
			actual: $this->saves
		);
		self::assertContains(needle: '2 applications and usages now have a data quality score.', haystack: $this->infos);
		self::assertTrue(condition: $this->settings[RescoreDataQuality::DONE_CONFIG_KEY]);
	}//end testEveryUnscoredEntryIsSavedOnceAndTheCountLogged()

	/**
	 * Entries are read page by page.
	 *
	 * @return void
	 */
	public function testEntriesAreReadPageByPage(): void {
		$this->entries[43] = [];
		for ($i = 0; $i <= RescoreDataQuality::PAGE_SIZE; $i++) {
			$this->entries[43][] = $this->entry(uuid: 'm-' . $i, data: ['name' => 'App ' . $i]);
		}

		$this->step()->run($this->upgradeOutput());

		self::assertCount(expectedCount: RescoreDataQuality::PAGE_SIZE + 1, haystack: $this->saves);
		self::assertSame(expected: 'm-' . RescoreDataQuality::PAGE_SIZE, actual: $this->saves[RescoreDataQuality::PAGE_SIZE]['uuid']);
	}//end testEntriesAreReadPageByPage()

	/**
	 * A second run writes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunWritesNothing(): void {
		$this->entries[43] = [$this->entry(uuid: 'm-1', data: ['name' => 'Zaaksysteem'])];
		$this->settings[RescoreDataQuality::DONE_CONFIG_KEY] = true;

		$this->step()->run($this->upgradeOutput());

		self::assertSame(expected: [], actual: $this->saves);
	}//end testASecondRunWritesNothing()

	/**
	 * A failed save leaves the step to run again.
	 *
	 * @return void
	 */
	public function testAFailedSaveLeavesTheStepToRunAgain(): void {
		$this->entries[43] = [$this->entry(uuid: 'm-1', data: ['name' => 'Zaaksysteem'])];

		$this->step(saveFails: true)->run($this->upgradeOutput());

		self::assertArrayNotHasKey(key: RescoreDataQuality::DONE_CONFIG_KEY, array: $this->settings);
	}//end testAFailedSaveLeavesTheStepToRunAgain()

	/**
	 * An unconfigured schema leaves the step to run again.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaLeavesTheStepToRunAgain(): void {
		$this->usageSchema = null;
		$this->entries[43] = [$this->entry(uuid: 'm-1', data: ['name' => 'Zaaksysteem'])];

		$this->step()->run($this->upgradeOutput());

		self::assertCount(expectedCount: 1, haystack: $this->saves);
		self::assertArrayNotHasKey(key: RescoreDataQuality::DONE_CONFIG_KEY, array: $this->settings);
	}//end testAnUnconfiguredSchemaLeavesTheStepToRunAgain()

	/**
	 * The step names itself.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		self::assertSame(expected: 'Give every application and usage a data quality score', actual: $this->step()->getName());
	}//end testTheStepNamesItself()
	/**
	 * The step runs on upgrade, after InitializeSettings imported the rules and
	 * the score fields; a fresh install has no unscored rows, so it is not an
	 * install step.
	 *
	 * @return void
	 */
	public function testItIsRegisteredOnUpgradeAfterTheRegisterImport(): void {
		// Read the bytes, not the path: Nextcloud's external entity loader makes
		// simplexml_load_file() return false for a valid file in CI.
		$info = simplexml_load_string((string) file_get_contents(__DIR__ . '/../../../appinfo/info.xml'));
		$this->assertNotFalse(condition: $info);

		$upgrade = array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false));
		$rescore = array_search(RescoreDataQuality::class, $upgrade, true);
		$this->assertIsInt(actual: $rescore);
		$initialize = array_search(needle: 'OCA\Stackiq\Repair\InitializeSettings', haystack: $upgrade, strict: true);
		$this->assertGreaterThan(expected: $initialize, actual: $rescore);

		$install = array_map('strval', iterator_to_array($info->{'repair-steps'}->install->step, false));
		$this->assertNotContains(needle: RescoreDataQuality::class, haystack: $install);
	}//end testItIsRegisteredOnUpgradeAfterTheRegisterImport()
}//end class
