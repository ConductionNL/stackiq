<?php

/**
 * Golden test for updating the three role group lists of SettingsService.
 *
 * Records, per method and case, what updateGenericUserGroups(),
 * updateOrganizationAdminGroups() and updateSuperUserGroups() return and every
 * config write and log line they make. Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
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

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden cases for the SettingsService role group updates.
 *
 * @covers \OCA\Stackiq\Service\SettingsService
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class SettingsServiceRoleGroupsGoldenTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The cases: method, groups and whether the config write fails.
	 *
	 * @return array<string, array{0: string, 1: array, 2: bool}>
	 */
	public static function cases(): array {
		$inputs = [
			'valid groups'     => [['admin', 'team.a', 'x_y-z'], false],
			'no groups'        => [[], false],
			'invalid groups'   => [['ok', '', 'bad name', 7, null], false],
			'config write fails' => [['admin'], true],
		];
		$cases  = [];
		foreach (['updateGenericUserGroups', 'updateOrganizationAdminGroups', 'updateSuperUserGroups'] as $method) {
			foreach ($inputs as $name => [$groups, $fails]) {
				$cases[$method . ' / ' . $name] = [$method, $groups, $fails];
			}
		}

		return $cases;

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param string $method The update method.
	 * @param array  $groups The groups to set.
	 * @param bool   $fails  Whether the config write throws.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testRoleGroups(string $method, array $groups, bool $fails): void {
		$service = $this->service(fails: $fails);
		$result  = $service->$method($groups);
		$this->check(key: $this->dataName(), value: ['result' => $result, 'trace' => $this->trace]);

	}//end testRoleGroups()

	/**
	 * Build the service with recording doubles.
	 *
	 * @param bool $fails Whether the config write throws.
	 *
	 * @return SettingsService
	 */
	private function service(bool $fails): SettingsService {
		$trace  = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->trace[] = ['log.' . $level, (string) $message, $context];
			}
		};

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use ($fails) {
				$this->trace[] = ['config.set', $app, $key, $value];
				if ($fails === true) {
					throw new \RuntimeException('config down');
				}

				return true;
			}
		);

		$reflection = new \ReflectionClass(SettingsService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('config')->setValue($service, $config);
		$reflection->getProperty('logger')->setValue($service, $logger);
		$reflection->getProperty('appName')->setValue($service, 'stackiq');

		return $service;

	}//end service()

	/**
	 * Compare a case with the golden file, or record it.
	 *
	 * @param string $key   The case.
	 * @param array  $value What the case observed.
	 *
	 * @return void
	 */
	private function check(string $key, array $value): void {
		$json  = json_decode(json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
		$file  = __DIR__ . '/golden/settingsServiceRoleGroups.json';
		$saved = [];
		if (file_exists($file) === true) {
			$saved = json_decode((string) file_get_contents($file), true);
		}

		if (getenv('RECORD_GOLDEN') !== false) {
			$saved[$key] = $json;
			file_put_contents($file, json_encode($saved, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertArrayHasKey(key: $key, array: $saved, message: 'No golden for ' . $key);
		$this->assertSame(expected: $saved[$key], actual: $json);

	}//end check()

}//end class
