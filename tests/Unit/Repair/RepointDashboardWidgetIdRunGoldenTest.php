<?php

/**
 * Golden test for RepointDashboardWidgetId::run().
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

use OCA\Stackiq\Repair\RepointDashboardWidgetId;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins how dashboard layouts are re-pointed to the frozen widget id.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Repair
 */
final class RepointDashboardWidgetIdRunGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/repair/repoint-dashboard-widget-id.json';

	private const RENAMED = 'stackiq_concept_organisaties_widget';

	private const FROZEN = 'softwarecatalog_concept_organisaties_widget';

	/**
	 * The cases: the seen users with their layouts, and what fails.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$layouts = [
			'alice' => '',
			'bob'   => 'calendar,' . self::RENAMED . ',notes',
			'carol' => 'calendar,notes',
			'dave'  => self::FROZEN . ', ' . self::RENAMED . ' ,,' . self::FROZEN,
			'erin'  => self::RENAMED,
			'frank' => ' ' . self::RENAMED . ' , ' . self::RENAMED . ' ',
		];
		return [
			'no users'        => ['layouts' => []],
			'every layout'    => ['layouts' => $layouts],
			'read fails'      => ['layouts' => $layouts, 'readFails' => 'carol'],
			'write fails'     => ['layouts' => $layouts, 'writeFails' => 'bob'],
			'walk fails'      => ['layouts' => $layouts, 'walkFails' => true],
			'walk stops'      => ['layouts' => $layouts, 'stopOnFalse' => true],
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
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(function (string $uid, string $app, string $key, $default) use (&$trace, $case): string {
			$trace[] = ['getUserValue', $uid, $app, $key, $default];
			if (($case['readFails'] ?? null) === $uid) {
				throw new \RuntimeException('read down');
			}

			return $case['layouts'][$uid];
		});
		$config->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, $value) use (&$trace, $case): void {
			$trace[] = ['setUserValue', $uid, $app, $key, $value];
			if (($case['writeFails'] ?? null) === $uid) {
				throw new \RuntimeException('write down');
			}
		});
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(function (\Closure $callback) use (&$trace, $case): void {
			$trace[] = ['callForSeenUsers'];
			if (($case['walkFails'] ?? false) === true) {
				throw new \RuntimeException('walk down');
			}

			foreach (array_keys($case['layouts']) as $uid) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$returned = $callback($user);
				$trace[]  = ['callback returned', $returned];
				if (($case['stopOnFalse'] ?? false) === true && $returned === false) {
					break;
				}
			}
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use (&$trace, $level): void {
				$trace[] = ['log.' . $level, $message, $context['app'], get_class($context['exception'])];
			});
		}

		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(function (string $message) use (&$trace, $level): void {
				$trace[] = ['output.' . $level, $message];
			});
		}

		$step = new RepointDashboardWidgetId($config, $userManager, $logger);
		$step->run($output);
		$step->run($this->createMock(IOutput::class));

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
