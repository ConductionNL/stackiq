<?php

/**
 * The backfill reads modules page by page and runs once: after a pass without failures it is skipped.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Repair\BackfillModuleVersionPublication;
use OCA\Stackiq\Service\ModuleVersionPublicationService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pages, the done marker, and a failed pass that runs again.
 */
class BackfillModuleVersionPublicationTest extends TestCase {

	/**
	 * The app settings, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = [];

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The mirror double.
	 *
	 * @var ModuleVersionPublicationService&MockObject
	 */
	private ModuleVersionPublicationService&MockObject $publication;

	/**
	 * The step under test, with OpenRegister installed and the module schema configured.
	 *
	 * @return BackfillModuleVersionPublication
	 */
	private function step(): BackfillModuleVersionPublication {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'stackiq']);

		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();

		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getRegisterIdForObjectType', 'getSchemaIdForObjectType', 'getObjectService'])
			->getMock();
		$settings->method('getRegisterIdForObjectType')->willReturn(20);
		$settings->method('getSchemaIdForObjectType')->willReturn(43);
		$settings->method('getObjectService')->willReturn($this->objects);

		$this->publication = $this->getMockBuilder(ModuleVersionPublicationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['backfillModule'])
			->getMock();

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool) ($this->settings[$key] ?? $default));
		$config->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->settings[$key] = $value;
				return true;
			}
		);

		return new BackfillModuleVersionPublication(appManager: $apps, settingsService: $settings, publication: $this->publication, appConfig: $config);
	}//end step()

	/**
	 * Modules are read in pages until a short page, and a pass without failures sets the marker.
	 *
	 * @return void
	 */
	public function testModulesAreReadPageByPageAndAFullPassIsRecorded(): void {
		$step   = $this->step();
		$module = $this->createMock(ObjectEntityInterface::class);
		$full   = array_fill(0, BackfillModuleVersionPublication::PAGE_SIZE, $module);
		$pages  = [];
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config) use (&$pages, $full, $module): array {
				$pages[] = $config;
				if ($config['offset'] === 0) {
					return $full;
				}

				return [$module];
			}
		);
		$this->publication->expects($this->exactly(BackfillModuleVersionPublication::PAGE_SIZE + 1))
			->method('backfillModule')->willReturn(['written' => 1, 'failed' => 0]);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame(
			[
				['limit' => BackfillModuleVersionPublication::PAGE_SIZE, 'offset' => 0],
				['limit' => BackfillModuleVersionPublication::PAGE_SIZE, 'offset' => BackfillModuleVersionPublication::PAGE_SIZE],
			],
			$pages
		);
		$this->assertTrue($this->settings[BackfillModuleVersionPublication::DONE_CONFIG_KEY]);
	}//end testModulesAreReadPageByPageAndAFullPassIsRecorded()

	/**
	 * After a recorded pass the step reads nothing.
	 *
	 * @return void
	 */
	public function testARecordedPassIsSkipped(): void {
		$step = $this->step();
		$this->settings[BackfillModuleVersionPublication::DONE_CONFIG_KEY] = true;
		$this->objects->expects($this->never())->method('findAll');

		$step->run($this->createMock(IOutput::class));
	}//end testARecordedPassIsSkipped()

	/**
	 * A pass in which a version failed is not recorded, so the next upgrade tries again.
	 *
	 * @return void
	 */
	public function testAPassWithFailuresRunsAgain(): void {
		$step = $this->step();
		$this->objects->method('findAll')->willReturn([$this->createMock(ObjectEntityInterface::class)]);
		$this->publication->method('backfillModule')->willReturn(['written' => 0, 'failed' => 1]);

		$step->run($this->createMock(IOutput::class));

		$this->assertArrayNotHasKey(BackfillModuleVersionPublication::DONE_CONFIG_KEY, $this->settings);
	}//end testAPassWithFailuresRunsAgain()
}//end class
