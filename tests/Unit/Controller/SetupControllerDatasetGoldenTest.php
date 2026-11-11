<?php

/**
 * Golden test of the dataset step of the setup wizard.
 *
 * Pins, for the load, legacy install, skip and unknown actions with every
 * stored and posted choice, the status, the body, the config writes, the
 * install calls and the log lines, including a failing install.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SetupController;
use OCA\Stackiq\Service\DemoDataService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test of the dataset step.
 */
class SetupControllerDatasetGoldenTest extends TestCase {
	/**
	 * Run one action and record what it did.
	 *
	 * @param string $action The action id.
	 * @param array  $setup  The stored choice, the posted dataset and whether the install throws.
	 *
	 * @return array
	 */
	private function record(string $action, array $setup): array {
		$calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => ($key === 'dataset' ? $setup['posted'] : null));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default) => ($setup['stored'] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) use (&$calls): bool {
			$calls[] = ['setValueString', $key, $value];
			return true;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message) use (&$calls): void {
			$calls[] = ['error', $message];
		});
		$demo = $this->createMock(DemoDataService::class);
		$demo->method('listChoices')->willReturn([['id' => DemoDataService::DEMO_DATASET], ['id' => DemoDataService::NONE_DATASET]]);
		$demo->method('install')->willReturnCallback(function () use (&$calls, $setup): array {
			$calls[] = ['install'];
			if ($setup['fails'] === true) {
				throw new \RuntimeException('register missing');
			}

			return ['objects' => 7];
		});

		$response = (new SetupController($request, $appConfig, $logger, $demo))->runAction($action);
		return ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $calls];
	}//end record()

	/**
	 * Every action and choice matches the golden.
	 *
	 * @return void
	 */
	public function testDatasetStepMatchesTheGolden(): void {
		$demo = DemoDataService::DEMO_DATASET;
		$none = DemoDataService::NONE_DATASET;
		$base = ['stored' => '', 'posted' => null, 'fails' => false];
		$cases = [
			'load, nothing chosen' => ['load-demo-data', $base],
			'load, stored demo' => ['load-demo-data', ['stored' => $demo] + $base],
			'load, stored none' => ['load-demo-data', ['stored' => $none] + $base],
			'load, posted demo over stored none' => ['load-demo-data', ['stored' => $none, 'posted' => $demo] + $base],
			'load, posted none' => ['load-demo-data', ['posted' => $none] + $base],
			'load, posted unknown' => ['load-demo-data', ['posted' => 'nope'] + $base],
			'load, posted list' => ['load-demo-data', ['posted' => [$demo]] + $base],
			'load, posted number' => ['load-demo-data', ['posted' => 5] + $base],
			'load, install fails' => ['load-demo-data', ['stored' => $demo, 'fails' => true] + $base],
			'legacy install, nothing chosen' => ['install-demo-data', $base],
			'legacy install, stored none' => ['install-demo-data', ['stored' => $none] + $base],
			'skip' => ['skip-demo-data', $base],
			'unknown action' => ['do-something', $base],
		];

		$out = [];
		foreach ($cases as $name => [$action, $setup]) {
			$out[$name] = $this->record(action: $action, setup: $setup);
		}

		$path = dirname(__DIR__, 2) . '/fixtures/setup/dataset-step.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testDatasetStepMatchesTheGolden()
}//end class
