<?php

/**
 * Golden test for MigrateAppConfigKeys::run().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\MigrateAppConfigKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which legacy app config keys are copied, skipped and reported.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Repair
 */
final class MigrateAppConfigKeysRunGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/repair/migrate-app-config-keys.json';

	/**
	 * The cases: legacy keys, legacy values, keys the new app id holds, failures.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$values = [
			'enabled' => 'yes', 'types' => 'x', 'installed_version' => '1.0',
			'flag' => false, 'count' => 0, 'ratio' => 1.5, 'list' => ['a'], 'empty_list' => [],
			'name' => 'Stackiq', 'blank' => '', 'held' => 'old', 'nothing' => null,
		];
		return [
			'nothing stored'   => ['keys' => [], 'values' => []],
			'every kind'       => ['keys' => array_merge(array_keys($values), ['listed_only']), 'values' => $values, 'held' => ['held']],
			'all held'         => ['keys' => ['name', 'flag'], 'values' => $values, 'held' => ['name', 'flag']],
			'keys fail'        => ['keysFail' => true],
			'values fail'      => ['keys' => ['name'], 'valuesFail' => true],
			'write fails'      => ['keys' => ['flag', 'name', 'count'], 'values' => $values, 'writeFails' => 'name'],
		];
	}//end cases()

	/**
	 * Run one case and record the trace.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function record(array $case): array {
		$trace  = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getKeys')->willReturnCallback(function (string $app) use (&$trace, $case): array {
			$trace[] = ['getKeys', $app];
			if (($case['keysFail'] ?? false) === true) {
				throw new \RuntimeException('keys down');
			}

			return $case['keys'];
		});
		$config->method('getAllValues')->willReturnCallback(function (string $app) use (&$trace, $case): array {
			$trace[] = ['getAllValues', $app];
			if (($case['valuesFail'] ?? false) === true) {
				throw new \RuntimeException('values down');
			}

			return $case['values'];
		});
		$config->method('hasKey')->willReturnCallback(function (string $app, string $key) use (&$trace, $case): bool {
			$trace[] = ['hasKey', $app, $key];
			return in_array($key, ($case['held'] ?? []), true);
		});
		foreach (['setValueBool', 'setValueInt', 'setValueFloat', 'setValueArray', 'setValueString'] as $setter) {
			$config->method($setter)->willReturnCallback(function (string $app, string $key, $value) use (&$trace, $case, $setter): bool {
				$trace[] = [$setter, $app, $key, $value];
				if (($case['writeFails'] ?? null) === $key) {
					throw new \RuntimeException('write down');
				}

				return true;
			});
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []) use (&$trace): void {
			$trace[] = ['error', $message, $context['app'], get_class($context['exception'])];
		});
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(function (string $message) use (&$trace, $level): void {
				$trace[] = ['output.' . $level, $message];
			});
		}

		(new MigrateAppConfigKeys($config, $logger))->run($output);

		return $trace;
	}//end record()

	/**
	 * Every case matches the recorded trace.
	 *
	 * @return void
	 */
	public function testRunMatchesGolden(): void {
		$actual = [];
		$lines  = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = $this->record(case: $case);
			$lines[]       = json_encode($name) . ': ' . json_encode($actual[$name], JSON_UNESCAPED_SLASHES);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), json_decode((string) json_encode($actual), true));

	}//end testRunMatchesGolden()
}//end class
