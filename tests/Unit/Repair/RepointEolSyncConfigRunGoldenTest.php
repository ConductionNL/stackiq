<?php

/**
 * Golden test of the EOL sync configuration repair step's run.
 *
 * Pins, for an unreadable, missing, non-JSON, already current, stale,
 * unencodable and unwritable configuration, what the step writes, logs and
 * reports.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\RepointEolSyncConfig;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test of RepointEolSyncConfig::run.
 */
final class RepointEolSyncConfigRunGoldenTest extends TestCase {
	/**
	 * Run the step over a stored value and record what it did.
	 *
	 * @param mixed $stored The stored value, or an exception the read throws.
	 * @param bool  $failWrite Whether the write throws.
	 *
	 * @return array
	 */
	private function record(mixed $stored, bool $failWrite): array {
		$calls = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static function () use ($stored): string {
			if ($stored instanceof \Throwable) {
				throw $stored;
			}

			return $stored;
		});
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) use (&$calls, $failWrite): bool {
			$calls[] = ['setValueString', $app, $key, $value];
			if ($failWrite === true) {
				throw new \RuntimeException('read only');
			}

			return true;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$calls): void {
			$calls[] = ['warning', $message, $context];
		});
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message) use (&$calls): void {
			$calls[] = ['info', $message];
		});

		(new RepointEolSyncConfig($appConfig, $logger))->run($output);
		return $calls;
	}//end record()

	/**
	 * Every stored value matches the golden.
	 *
	 * @return void
	 */
	public function testRunMatchesTheGolden(): void {
		$stale = '{"register":"openconnector","cycleSchema":"eolCycle","other":1}';
		$out = [
			'unreadable' => $this->record(stored: new \RuntimeException('db gone'), failWrite: false),
			'missing' => $this->record(stored: '', failWrite: false),
			'not json' => $this->record(stored: '"text"', failWrite: false),
			'already current' => $this->record(stored: '{"a":1}', failWrite: false),
			'stale' => $this->record(stored: $stale, failWrite: false),
			'stale, unencodable' => $this->record(stored: '{"register":"openconnector","big":1e400}', failWrite: false),
			'stale, unwritable' => $this->record(stored: $stale, failWrite: true),
		];

		$path = dirname(__DIR__, 2) . '/fixtures/repair/repoint-eol-sync-run.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testRunMatchesTheGolden()
}//end class
