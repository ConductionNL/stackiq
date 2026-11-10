<?php

/**
 * Golden test of importing the shipped demo dataset.
 *
 * Pins what install() reads, hands to OpenRegister's configuration import,
 * reports and logs for a dataset with and without objects, and the errors
 * for a missing descriptor, invalid JSON and a missing OpenRegister.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\DemoDataService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test of DemoDataService::install.
 */
class DemoDataServiceInstallGoldenTest extends TestCase {
	/**
	 * Install from a descriptor and record what happened.
	 *
	 * @param string|null $descriptor The descriptor content, or null for none.
	 * @param bool        $withOpenRegister Whether OpenRegister is installed.
	 *
	 * @return array
	 */
	private function record(?string $descriptor, bool $withOpenRegister): array {
		$appPath = sys_get_temp_dir() . '/or-demo-golden-' . bin2hex(random_bytes(4));
		mkdir($appPath . '/lib/Settings', 0777, true);
		$file = $appPath . '/lib/Settings/stackiq_mock_register.json';
		if ($descriptor !== null) {
			file_put_contents($file, $descriptor);
		}

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($appPath);
		$appManager->method('getAppVersion')->willReturn('1.2.3');
		$appManager->method('getInstalledApps')->willReturn($withOpenRegister === true ? ['openregister'] : []);
		$calls = [];
		$importer = new class($calls) {
			/**
			 * Constructor.
			 *
			 * @param array $calls The recorded calls.
			 */
			public function __construct(public array &$calls) {
			}

			/**
			 * Record an import.
			 *
			 * @param string $appId   The app id.
			 * @param array  $data    The data.
			 * @param string $version The version.
			 * @param bool   $force   Whether to force.
			 *
			 * @return array
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->calls[] = ['importFromApp', $appId, $data, $version, $force];
				return ['registers' => ['r1'], 'schemas' => ['s1', 's2']];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($importer);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message, array $context = []) use (&$calls): void {
			$calls[] = ['info', $message, $context];
		});

		try {
			$result = (new DemoDataService($appManager, $container, $logger))->install();
		} catch (\Throwable $e) {
			$result = get_class($e) . ': ' . str_replace($appPath, '<app>', $e->getMessage());
		}

		@unlink($file);
		@rmdir($appPath . '/lib/Settings');
		@rmdir($appPath . '/lib');
		@rmdir($appPath);
		return ['result' => $result, 'calls' => $calls];
	}//end record()

	/**
	 * Every descriptor matches the golden.
	 *
	 * @return void
	 */
	public function testInstallMatchesTheGolden(): void {
		$full = (string)json_encode(
			[
				'components' => [
					'registers' => ['voorzieningen' => ['slug' => 'voorzieningen', 'schemas' => ['module']]],
					'schemas' => ['module' => ['slug' => 'module', 'title' => 'Module']],
					'objects' => [['@self' => ['register' => 'voorzieningen', 'schema' => 'module'], 'naam' => 'A'], ['naam' => 'B']],
				],
			]
		);
		$out = [
			'full dataset' => $this->record(descriptor: $full, withOpenRegister: true),
			'no objects block' => $this->record(descriptor: '{"components":{"schemas":{}}}', withOpenRegister: true),
			'objects not a list' => $this->record(descriptor: '{"components":{"objects":"x"}}', withOpenRegister: true),
			'no descriptor' => $this->record(descriptor: null, withOpenRegister: true),
			'invalid json' => $this->record(descriptor: '{nope', withOpenRegister: true),
			'no openregister' => $this->record(descriptor: $full, withOpenRegister: false),
		];

		$path = dirname(__DIR__, 2) . '/fixtures/demo-data/install.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testInstallMatchesTheGolden()
}//end class
