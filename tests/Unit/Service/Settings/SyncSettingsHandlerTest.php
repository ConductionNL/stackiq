<?php

/**
 * Unit tests for SyncSettingsHandler.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use InvalidArgumentException;
use OCA\Stackiq\Service\Settings\SyncSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Round-trips the sync settings through an in-memory IAppConfig double.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SyncSettingsHandlerTest extends TestCase {

	/**
	 * Build a handler over a key/value store.
	 *
	 * @param array<string, string> $store  Reference to the backing store.
	 * @param LoggerInterface|null  $logger The logger, or a dummy.
	 *
	 * @return SyncSettingsHandler
	 */
	private function handler(array &$store, ?LoggerInterface $logger = null): SyncSettingsHandler {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				TestCase::assertSame('stackiq', $app);
				return $store[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				TestCase::assertSame('stackiq', $app);
				$store[$key] = $value;
				return true;
			}
		);

		return new SyncSettingsHandler($config, $logger ?? $this->createMock(LoggerInterface::class));
	}//end handler()

	/**
	 * An empty store yields the defaults.
	 *
	 * @return void
	 */
	public function testDefaults(): void {
		$store   = [];
		$handler = $this->handler($store);

		$this->assertSame('10', $handler->getSyncTimeWindow());
		$this->assertSame(
			[
				'syncTimeWindow'          => '10',
				'syncEnabled'             => true,
				'cronjobInterval'         => '*/5 * * * *',
				'lastSyncTime'            => '',
				'organizationSyncEnabled' => true,
			],
			$handler->getCronjobConfig()
		);
	}//end testDefaults()

	/**
	 * A valid time window is stored and logged.
	 *
	 * @return void
	 */
	public function testSetSyncTimeWindowStoresAndLogs(): void {
		$store  = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with('SyncSettingsHandler: Updated syncTimeWindow', ['minutes' => '60']);

		$this->handler($store, $logger)->setSyncTimeWindow('60');

		$this->assertSame(['syncTimeWindow' => '60'], $store);
	}//end testSetSyncTimeWindowStoresAndLogs()

	/**
	 * The range bounds 1 and 10080 are accepted.
	 *
	 * @return void
	 */
	public function testRangeBoundsAreAccepted(): void {
		$store   = [];
		$handler = $this->handler($store);

		$handler->setSyncTimeWindow('1');
		$this->assertSame('1', $store['syncTimeWindow']);

		$handler->setSyncTimeWindow('10080');
		$this->assertSame('10080', $store['syncTimeWindow']);
	}//end testRangeBoundsAreAccepted()

	/**
	 * Invalid windows are refused with a specific message and nothing is written.
	 *
	 * @param string $minutes The window.
	 * @param string $message The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('invalidWindows')]
	public function testInvalidWindowIsRefused(string $minutes, string $message): void {
		$store = [];

		try {
			$this->handler($store)->setSyncTimeWindow($minutes);
			$this->fail('Expected an InvalidArgumentException');
		} catch (InvalidArgumentException $e) {
			$this->assertSame($message, $e->getMessage());
		}

		$this->assertSame([], $store);
	}//end testInvalidWindowIsRefused()

	/**
	 * Windows that are refused, with their messages.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function invalidWindows(): array {
		return [
			'non-numeric' => ['ten', 'syncTimeWindow must be numeric, got "ten".'],
			'zero'        => ['0', 'syncTimeWindow must be between 1 and 10080 minutes, got 0.'],
			'too large'   => ['10081', 'syncTimeWindow must be between 1 and 10080 minutes, got 10081.'],
			'negative'    => ['-5', 'syncTimeWindow must be between 1 and 10080 minutes, got -5.'],
		];
	}//end invalidWindows()

	/**
	 * Every cron-job field is written, flags as strict bool strings.
	 *
	 * @return void
	 */
	public function testUpdateCronjobConfigWritesEveryField(): void {
		$store  = ['lastSyncTime' => '2026-01-01T00:00:00Z'];
		$data   = ['syncTimeWindow' => 30, 'syncEnabled' => false, 'cronjobInterval' => '0 * * * *', 'organizationSyncEnabled' => 'yes'];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('info');

		$result = $this->handler($store, $logger)->updateCronjobConfig($data);

		$this->assertSame(
			[
				'lastSyncTime'            => '2026-01-01T00:00:00Z',
				'syncTimeWindow'          => '30',
				'syncEnabled'             => 'false',
				'cronjobInterval'         => '0 * * * *',
				'organizationSyncEnabled' => 'false',
			],
			$store
		);
		$this->assertSame(
			[
				'syncTimeWindow'          => '30',
				'syncEnabled'             => false,
				'cronjobInterval'         => '0 * * * *',
				'lastSyncTime'            => '2026-01-01T00:00:00Z',
				'organizationSyncEnabled' => false,
			],
			$result
		);
	}//end testUpdateCronjobConfigWritesEveryField()

	/**
	 * Bool true enables, and absent fields are left alone.
	 *
	 * @return void
	 */
	public function testPartialUpdateEnablesWithBoolTrue(): void {
		$store = ['syncEnabled' => 'false', 'cronjobInterval' => '1 1 * * *'];

		$result = $this->handler($store)->updateCronjobConfig(['syncEnabled' => true, 'organizationSyncEnabled' => true]);

		$this->assertSame(['syncEnabled' => 'true', 'cronjobInterval' => '1 1 * * *', 'organizationSyncEnabled' => 'true'], $store);
		$this->assertTrue($result['syncEnabled']);
		$this->assertSame('1 1 * * *', $result['cronjobInterval']);
	}//end testPartialUpdateEnablesWithBoolTrue()

	/**
	 * An invalid window in the cron-job update aborts before the other fields are written.
	 *
	 * @return void
	 */
	public function testInvalidWindowAbortsCronjobUpdate(): void {
		$store = [];

		$this->expectException(InvalidArgumentException::class);

		try {
			$this->handler($store)->updateCronjobConfig(['syncTimeWindow' => '0', 'syncEnabled' => false]);
		} finally {
			$this->assertSame([], $store);
		}
	}//end testInvalidWindowAbortsCronjobUpdate()
}//end class
