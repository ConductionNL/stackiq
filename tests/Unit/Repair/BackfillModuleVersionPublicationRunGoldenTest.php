<?php

/**
 * Golden test for BackfillModuleVersionPublication::run().
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Repair\BackfillModuleVersionPublication;
use OCA\Stackiq\Service\ModuleVersionPublicationService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Pins when the backfill runs, what it reads and reports, and when it marks itself done.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Repair
 */
final class BackfillModuleVersionPublicationRunGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/repair/backfill-module-version-publication.json';

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		return [
			'already done'       => ['done' => true],
			'no openregister'    => ['apps' => ['stackiq']],
			'no register'        => ['register' => null],
			'no schema'          => ['schema' => null],
			'no object service'  => ['noObjects' => true],
			'read fails'         => ['readFails' => true, 'pages' => [2]],
			'clean pass'         => ['pages' => [3]],
			'two pages'          => ['pages' => [200, 1]],
			'failures'           => ['pages' => [2], 'failed' => 1],
			'non-entities'       => ['pages' => [2], 'junk' => true],
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
		$trace = [];
		$apps  = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(($case['apps'] ?? ['openregister', 'stackiq']));

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnCallback(function ($register) use (&$trace, &$objects) {
			$trace[] = ['setRegister', $register];
			return $objects;
		});
		$objects->method('setSchema')->willReturnCallback(function ($schema) use (&$trace, &$objects) {
			$trace[] = ['setSchema', $schema];
			return $objects;
		});
		$page = 0;
		$objects->method('findAll')->willReturnCallback(function (...$args) use (&$trace, &$page, $case): array {
			$trace[] = ['findAll', $args];
			if (($case['readFails'] ?? false) === true) {
				throw new \RuntimeException('read down');
			}

			$size = ($case['pages'][$page++] ?? 0);
			$out  = [];
			for ($i = 0; $i < $size; $i++) {
				$out[] = $this->createMock(ObjectEntityInterface::class);
			}

			if (($case['junk'] ?? false) === true) {
				$out[] = ['not' => 'an entity'];
			}

			return $out;
		});

		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getRegisterIdForObjectType', 'getSchemaIdForObjectType', 'getObjectService'])
			->getMock();
		$settings->method('getRegisterIdForObjectType')->willReturn(array_key_exists('register', $case) === true ? $case['register'] : 20);
		$settings->method('getSchemaIdForObjectType')->willReturn(array_key_exists('schema', $case) === true ? $case['schema'] : 43);
		$settings->method('getObjectService')->willReturn(($case['noObjects'] ?? false) === true ? null : $objects);

		$publication = $this->getMockBuilder(ModuleVersionPublicationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['backfillModule'])
			->getMock();
		$calls = 0;
		$publication->method('backfillModule')->willReturnCallback(function () use (&$calls, $case): array {
			$calls++;
			return ['written' => 2, 'failed' => ($calls === 1 ? ($case['failed'] ?? 0) : 0)];
		});

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(function (...$args) use (&$trace, $case): bool {
			$trace[] = ['getValueBool', $args];
			return ($case['done'] ?? false);
		});
		$config->method('setValueBool')->willReturnCallback(function (...$args) use (&$trace): bool {
			$trace[] = ['setValueBool', $args];
			return true;
		});

		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(function (string $message) use (&$trace, $level): void {
				$trace[] = ['output.' . $level, $message];
			});
		}

		(new BackfillModuleVersionPublication(appManager: $apps, settingsService: $settings, publication: $publication, appConfig: $config))->run($output);
		$trace[] = ['backfillModule calls', $calls];

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
