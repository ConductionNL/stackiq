<?php

/**
 * Unit tests for EolSyncSettings.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\Stackiq\Service\Settings\EolSyncSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Malformed stored EOL config and status fall back to defaults; a positive
 * interval is stored.
 *
 * @covers \OCA\Stackiq\Service\Settings\EolSyncSettings
 *
 * @spec openspec/specs/eol-feed-integration/spec.md
 */
class EolSyncSettingsMalformedTest extends TestCase {

	/**
	 * Stored values by key.
	 *
	 * @var array<string,string>
	 */
	private array $store = [];

	/**
	 * The settings over an in-memory app config.
	 *
	 * @return EolSyncSettings
	 */
	private function settings(): EolSyncSettings {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->store[$key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		return new EolSyncSettings(config: $config);
	}//end settings()

	/**
	 * A stored config that is not a JSON object yields the defaults.
	 *
	 * @return void
	 */
	public function testMalformedConfigYieldsDefaults(): void {
		$this->store = ['eol_sync_config' => 'not json'];

		$this->assertSame(
			expected: [
				'enabled' => false,
				'register' => 'integriq',
				'productSchema' => 'eol_product',
				'cycleSchema' => 'eol_cycle',
				'intervalSeconds' => 86400,
			],
			actual: $this->settings()->getEolSyncConfig()
		);
	}//end testMalformedConfigYieldsDefaults()

	/**
	 * A positive interval is stored; a non-positive one keeps the current value.
	 *
	 * @return void
	 */
	public function testPositiveIntervalIsStored(): void {
		$settings = $this->settings();

		$this->assertSame(expected: 3600, actual: $settings->updateEolSyncConfig(data: ['intervalSeconds' => '3600'])['config']['intervalSeconds']);
		$this->assertSame(expected: 3600, actual: $settings->updateEolSyncConfig(data: ['intervalSeconds' => 0])['config']['intervalSeconds']);
		$this->assertSame(expected: 3600, actual: json_decode(json: $this->store['eol_sync_config'], associative: true)['intervalSeconds']);
	}//end testPositiveIntervalIsStored()

	/**
	 * A stored status that is not a JSON object reads as not yet run.
	 *
	 * @return void
	 */
	public function testMalformedStatusReadsAsNotYetRun(): void {
		$this->store = ['eol_sync_status' => '42'];

		$this->assertSame(
			expected: ['available' => false, 'reason' => 'not-yet-run', 'matched' => 0, 'skipped' => 0, 'lastRunAt' => null],
			actual: $this->settings()->getEolSyncStatus()
		);
	}//end testMalformedStatusReadsAsNotYetRun()
}//end class
