<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
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
	private ObjectServiceInterface&MockObject $objects;

	/** @var array<string, bool> */
	private array $settings = [];

	/** @var array<int, array{schema: int|string|null, uuid: ?string, object: array<string, mixed>}> */
	private array $saves = [];

	/** @var list<string> */
	private array $infos = [];

	/** @var array<int, array<int, ObjectEntityInterface>> Entries per schema id. */
	private array $entries = [];

	private ?int $usageSchema = 44;

	private ?int $currentSchema = null;

	private function entry(string $uuid, array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entry()

	private function step(bool $saveFails = false): RescoreDataQuality {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'stackiq']);

		$this->objects = $this->createMock(ObjectServiceInterface::class);
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
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use ($saveFails): ObjectEntityInterface {
				if ($saveFails === true) {
					throw new \RuntimeException('validation failed');
				}

				$this->saves[] = ['schema' => $schema, 'uuid' => $uuid, 'object' => $object];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$settings = $this->getMockBuilder(SettingsService::class)
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

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => ($this->settings[$key] ?? $default));
		$config->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->settings[$key] = $value;
				return true;
			}
		);

		return new RescoreDataQuality(appManager: $apps, settingsService: $settings, appConfig: $config);
	}//end step()

	private function upgradeOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->infos[] = $message;
		});
		return $output;
	}//end upgradeOutput()

	public function testEveryUnscoredEntryIsSavedOnceAndTheCountLogged(): void {
		$this->entries[43] = [
			$this->entry('m-1', ['name' => 'Zaaksysteem', '@self' => ['id' => 'm-1'], 'id' => 'm-1']),
			$this->entry('m-2', ['name' => 'Scored', 'qualityScore' => 0.7]),
		];
		$this->entries[44] = [$this->entry('u-1', ['module' => 'm-1'])];

		$this->step()->run($this->upgradeOutput());

		self::assertSame(
			[
				['schema' => 43, 'uuid' => 'm-1', 'object' => ['name' => 'Zaaksysteem']],
				['schema' => 44, 'uuid' => 'u-1', 'object' => ['module' => 'm-1']],
			],
			$this->saves
		);
		self::assertContains('2 applications and usages now have a data quality score.', $this->infos);
		self::assertTrue($this->settings[RescoreDataQuality::DONE_CONFIG_KEY]);
	}//end testEveryUnscoredEntryIsSavedOnceAndTheCountLogged()

	public function testEntriesAreReadPageByPage(): void {
		$this->entries[43] = [];
		for ($i = 0; $i <= RescoreDataQuality::PAGE_SIZE; $i++) {
			$this->entries[43][] = $this->entry('m-' . $i, ['name' => 'App ' . $i]);
		}

		$this->step()->run($this->upgradeOutput());

		self::assertCount(RescoreDataQuality::PAGE_SIZE + 1, $this->saves);
		self::assertSame('m-' . RescoreDataQuality::PAGE_SIZE, $this->saves[RescoreDataQuality::PAGE_SIZE]['uuid']);
	}//end testEntriesAreReadPageByPage()

	public function testASecondRunWritesNothing(): void {
		$this->entries[43] = [$this->entry('m-1', ['name' => 'Zaaksysteem'])];
		$this->settings[RescoreDataQuality::DONE_CONFIG_KEY] = true;

		$this->step()->run($this->upgradeOutput());

		self::assertSame([], $this->saves);
	}//end testASecondRunWritesNothing()

	public function testAFailedSaveLeavesTheStepToRunAgain(): void {
		$this->entries[43] = [$this->entry('m-1', ['name' => 'Zaaksysteem'])];

		$this->step(saveFails: true)->run($this->upgradeOutput());

		self::assertArrayNotHasKey(RescoreDataQuality::DONE_CONFIG_KEY, $this->settings);
	}//end testAFailedSaveLeavesTheStepToRunAgain()

	public function testAnUnconfiguredSchemaLeavesTheStepToRunAgain(): void {
		$this->usageSchema = null;
		$this->entries[43] = [$this->entry('m-1', ['name' => 'Zaaksysteem'])];

		$this->step()->run($this->upgradeOutput());

		self::assertCount(1, $this->saves);
		self::assertArrayNotHasKey(RescoreDataQuality::DONE_CONFIG_KEY, $this->settings);
	}//end testAnUnconfiguredSchemaLeavesTheStepToRunAgain()

	public function testTheStepNamesItself(): void {
		self::assertSame('Give every application and usage a data quality score', $this->step()->getName());
	}//end testTheStepNamesItself()
}//end class
