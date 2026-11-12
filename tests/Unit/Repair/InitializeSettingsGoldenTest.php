<?php

/**
 * Golden test for the InitializeSettings repair step.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/repair-init/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\InitializeSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every config read and write, output line and log line of the step.
 *
 * @spec openspec/specs/repair-init/spec.md
 */
class InitializeSettingsGoldenTest extends TestCase {
	/**
	 * Calls across all collaborators, in order.
	 *
	 * @var array
	 */
	private array $calls = [];

	/**
	 * Run the step in one scenario and return the recorded calls.
	 *
	 * @param array $scenario lastVersion, presentKeys, initialize (array result or 'throw').
	 *
	 * @return array
	 */
	private function runStep(array $scenario): array {
		$this->calls = [];
		$record      = function (string $name): \Closure {
			return function (...$args) use ($name) {
				$this->calls[] = array_merge([$name], $args);
				return null;
			};
		};

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (...$args) use ($scenario) {
				$this->calls[] = array_merge(['config.getValueString'], $args);
				if (($scenario['configThrows'] ?? false) === true) {
					throw new RuntimeException('config down');
				}

				return $scenario['lastVersion'];
			}
		);
		$config->method('hasKey')->willReturnCallback(
			function (...$args) use ($scenario) {
				$this->calls[] = array_merge(['config.hasKey'], $args);
				return in_array($args[1], $scenario['presentKeys'], true);
			}
		);
		$config->method('setValueInt')->willReturnCallback(fn (...$args) => ($record('config.setValueInt')(...$args)) ?? true);
		$config->method('setValueBool')->willReturnCallback(fn (...$args) => ($record('config.setValueBool')(...$args)) ?? true);
		$config->method('setValueString')->willReturnCallback(fn (...$args) => ($record('config.setValueString')(...$args)) ?? true);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturnCallback(
			function (...$args) {
				$this->calls[] = array_merge(['apps.getAppVersion'], $args);
				return '2.1.0';
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('initialize')->willReturnCallback(
			function () use ($scenario) {
				$this->calls[] = ['settings.initialize'];
				if ($scenario['initialize'] === 'throw') {
					throw new RuntimeException('init failed');
				}

				return $scenario['initialize'];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($settings) {
				$this->calls[] = ['container.get', $id];
				return $settings;
			}
		);

		$output = $this->createMock(IOutput::class);
		foreach (['startProgress', 'advance', 'finishProgress', 'info', 'warning'] as $method) {
			$output->method($method)->willReturnCallback($record('output.' . $method));
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $method) {
			$logger->method($method)->willReturnCallback($record('logger.' . $method));
		}

		(new InitializeSettings($config, $apps, $container, $logger))->run($output);
		return $this->calls;
	}

	/**
	 * The step in every scenario.
	 *
	 * @return void
	 */
	public function testRunPerScenario(): void {
		$allKeys = [
			'contract_expiry_window_days',
			'eol_warning_window_days',
			'portfolio_report_page_size_ceiling',
			'federation_enabled',
			'federation_directory_url',
			'federation_sync_interval',
		];
		$out     = [];
		foreach ([
			'already'      => ['lastVersion' => '2.1.0', 'presentKeys' => [], 'initialize' => []],
			'fresh'        => ['lastVersion' => '', 'presentKeys' => [], 'initialize' => ['created' => 2]],
			'all-present'  => ['lastVersion' => '2.0.0', 'presentKeys' => $allKeys, 'initialize' => ['errors' => []]],
			'some-present' => ['lastVersion' => '2.0.0', 'presentKeys' => ['eol_warning_window_days', 'federation_enabled'], 'initialize' => ['errors' => ['a', 'b']]],
			'init-throws'  => ['lastVersion' => '2.0.0', 'presentKeys' => $allKeys, 'initialize' => 'throw'],
			'config-fails' => ['lastVersion' => '', 'presentKeys' => [], 'initialize' => [], 'configThrows' => true],
		] as $name => $scenario) {
			$out[$name] = $this->runStep(scenario: $scenario);
		}

		$path = dirname(__DIR__, 2) . '/fixtures/repair/initialize-settings.json';
		$this->assertSame(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $out);
	}
}
