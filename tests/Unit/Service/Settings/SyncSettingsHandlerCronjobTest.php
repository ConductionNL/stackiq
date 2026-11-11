<?php

/**
 * Characterisation tests for SyncSettingsHandler::updateCronjobConfig.
 *
 * Pins which cron-job settings an update stores, how the two flags are
 * written, the log line and the configuration answered, in order.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\Stackiq\Service\Settings\SyncSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Cases for updating the cron-job configuration.
 *
 * @covers \OCA\Stackiq\Service\Settings\SyncSettingsHandler
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */
class SyncSettingsHandlerCronjobTest extends TestCase {

	/**
	 * Every write and log line, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Update the cron-job configuration.
	 *
	 * @param array $data The update.
	 *
	 * @return array The configuration answered.
	 */
	private function update(array $data): array {
		$this->trace = [];
		$stored      = [];

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$stored) {
				$this->trace[] = ['set', $app, $key, $value];
				$stored[$key]  = $value;
				return true;
			}
		);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use (&$stored) {
				return ($stored[$key] ?? $default);
			}
		);

		$trace  = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record a log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		return (new SyncSettingsHandler($config, $logger))->updateCronjobConfig($data);

	}//end update()

	/**
	 * Every field is stored in order; only a strict true flag is stored as 'true'.
	 *
	 * @return void
	 */
	public function testEveryFieldIsStored(): void {
		$data   = ['organizationSyncEnabled' => 'true', 'cronjobInterval' => 15, 'syncEnabled' => true, 'syncTimeWindow' => 30];
		$result = $this->update(data: $data);

		$this->assertSame(
			[
				['set', 'stackiq', 'syncTimeWindow', '30'],
				['log', 'info', 'SyncSettingsHandler: Updated syncTimeWindow', ['minutes' => '30']],
				['set', 'stackiq', 'syncEnabled', 'true'],
				['set', 'stackiq', 'cronjobInterval', '15'],
				['set', 'stackiq', 'organizationSyncEnabled', 'false'],
				['log', 'info', 'SyncSettingsHandler: Updated cron-job configuration', ['data' => $data]],
			],
			$this->trace
		);
		$this->assertSame(
			['syncTimeWindow' => '30', 'syncEnabled' => true, 'cronjobInterval' => '15', 'lastSyncTime' => '', 'organizationSyncEnabled' => false],
			$result
		);

	}//end testEveryFieldIsStored()

	/**
	 * Missing and null fields are left alone; false flags are stored as 'false'.
	 *
	 * @return void
	 */
	public function testMissingAndNullFieldsAreLeftAlone(): void {
		$data   = ['syncTimeWindow' => null, 'syncEnabled' => false, 'organizationSyncEnabled' => true];
		$result = $this->update(data: $data);

		$this->assertSame(
			[
				['set', 'stackiq', 'syncEnabled', 'false'],
				['set', 'stackiq', 'organizationSyncEnabled', 'true'],
				['log', 'info', 'SyncSettingsHandler: Updated cron-job configuration', ['data' => $data]],
			],
			$this->trace
		);
		$this->assertSame(
			['syncTimeWindow' => '10', 'syncEnabled' => false, 'cronjobInterval' => '*/5 * * * *', 'lastSyncTime' => '', 'organizationSyncEnabled' => true],
			$result
		);

		$this->update(data: []);
		$this->assertSame([['log', 'info', 'SyncSettingsHandler: Updated cron-job configuration', ['data' => []]]], $this->trace);

	}//end testMissingAndNullFieldsAreLeftAlone()

	/**
	 * An invalid time window is refused before anything is stored.
	 *
	 * @return void
	 */
	public function testAnInvalidTimeWindowIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		try {
			$this->update(data: ['syncTimeWindow' => 'soon', 'syncEnabled' => true]);
		} finally {
			$this->assertSame([], $this->trace);
		}

	}//end testAnInvalidTimeWindowIsRefused()
}//end class
