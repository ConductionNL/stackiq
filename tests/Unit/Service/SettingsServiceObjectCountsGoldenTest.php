<?php

/**
 * Golden test for the object counts and statistics of SettingsService.
 *
 * Records, per case, what getObjectsCounts(), getObjectsStatistics(),
 * getObjectCountsStatistics() and getArchiMateStatus() return and every
 * logger, container, object service and ArchiMate call they make.
 * Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Golden cases for the SettingsService object counts.
 *
 * @covers \OCA\Stackiq\Service\SettingsService
 * @uses   \OCA\Stackiq\Service\Settings\SettingsObjectCounts
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class SettingsServiceObjectCountsGoldenTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The cases: installed apps, stored config values and the ArchiMate answers.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function cases(): array {
		$amef = [
			'element' => [1, 2, 3],
			'organization' => [1],
			'view' => [],
			'relationship' => [1, 2],
			'model' => [1],
		];
		return [
			'nothing installed or configured' => [['apps' => [], 'config' => [], 'archimate' => 'throw']],
			'openregister without config'     => [['apps' => ['openregister'], 'config' => [], 'archimate' => $amef]],
			'configured voorzieningen'        => [
				[
					'apps'      => ['openregister'],
					'config'    => [
						'voorzieningen_config' => '{"register":"5","organisatie_schema":"7","contactpersoon_schema":8,"gebruik_schema":null}',
						'amef_config'          => '{"register_id":"9","elements_schema":"10"}',
						'archimate_import_status' => '{"status":"done"}',
					],
					'archimate' => $amef,
				],
			],
			'legacy scalar keys'              => [
				[
					'apps'      => ['openregister', 'opencatalogi'],
					'config'    => [
						'voorzieningen_config'      => 'not json',
						'voorzieningen_register'    => '3',
						'voorzieningen_organisatie_schema' => '4',
						'archimate_export_status'   => '[1]',
					],
					'archimate' => 'throw',
				],
			],
			'container fails'                 => [['apps' => ['openregister'], 'config' => [], 'archimate' => 'throw', 'container' => 'throw']],
			'register without schema'         => [
				[
					'apps'      => ['openregister'],
					'config'    => [
						'voorzieningen_config' => '{"register":"5"}',
						'amef_config'          => '{"register_id":"9"}',
					],
					'archimate' => $amef,
				],
			],
			'amef config without archimate'   => [
				[
					'apps'             => ['openregister'],
					'config'           => ['amef_config' => '{"register_id":"9","elements_schema":"10"}'],
					'archimate'        => $amef,
					'archimateMissing' => true,
				],
			],
			'amef register only'              => [
				[
					'apps'             => ['openregister'],
					'config'           => ['amef_config' => '{"register_id":"9"}'],
					'archimate'        => $amef,
					'archimateMissing' => true,
				],
			],
			'config read fails'               => [
				['apps' => ['openregister'], 'config' => [], 'archimate' => $amef, 'configThrows' => 'voorzieningen_config'],
			],
		];

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param array $plan The doubles' answers.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testObjectCounts(array $plan): void {
		$service = $this->service(plan: $plan);
		$out     = [];
		$methods = ['getObjectsCounts', 'getObjectsStatistics', 'getObjectCountsStatistics', 'getArchiMateStatus'];
		foreach ($methods as $method) {
			$this->trace[] = ['call', $method];
			try {
				$out[$method] = self::stable(value: $service->$method());
			} catch (\Throwable $e) {
				$out[$method] = 'escaped: ' . get_class($e) . ': ' . $e->getMessage();
			}
		}

		$this->check(key: $this->dataName(), value: ['out' => $out, 'trace' => $this->trace]);

	}//end testObjectCounts()

	/**
	 * Replace the moments in a result by a marker.
	 *
	 * @param mixed $value The result.
	 *
	 * @return mixed
	 */
	private static function stable(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			if ($key === 'timestamp' && is_int($item) === true) {
				$value[$key] = '(time)';
				continue;
			}

			$value[$key] = self::stable(value: $item);
		}

		return $value;

	}//end stable()

	/**
	 * Build the service with recording doubles.
	 *
	 * @param array $plan The doubles' answers.
	 *
	 * @return SettingsService
	 */
	private function service(array $plan): SettingsService {
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
				if (array_key_exists('trace', $context) === true) {
					$context['trace'] = '(trace)';
				}

				$this->trace[] = ['log.' . $level, (string) $message, $context];
			}
		};

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default='') use ($plan) {
				$this->trace[] = ['config.get', $app, $key];
				if (($plan['configThrows'] ?? null) === $key) {
					throw new \RuntimeException('config down');
				}

				return ($plan['config'][$key] ?? $default);
			}
		);

		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($plan['apps']);
		$apps->method('isEnabledForUser')->willReturn(true);
		$apps->method('isInstalled')->willReturnCallback(fn (string $app) => in_array($app, $plan['apps'], true));

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('countSearchObjects')->willReturnCallback(
			function (array $query) {
				$this->trace[] = ['objects.count', $query];
				return 7;
			}
		);

		$archimate = $this->createMock(originalClassName: ArchiMateService::class);
		$getters = [
			'element' => 'getElementObjects',
			'organization' => 'getOrganizationObjects',
			'view' => 'getViewObjects',
			'relationship' => 'getRelationshipObjects',
			'model' => 'getModelObjects',
		];
		foreach ($getters as $key => $method) {
			$archimate->method($method)->willReturnCallback(
				function () use ($plan, $key, $method) {
					$this->trace[] = ['archimate.' . $method];
					if ($plan['archimate'] === 'throw') {
						throw new \RuntimeException('archimate down');
					}

					return $plan['archimate'][$key];
				}
			);
		}

		$archimate->method('getArchiMateStatus')->willThrowException(new \RuntimeException('status down'));

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($plan, $objects, $archimate) {
				$this->trace[] = ['container.get', $id];
				if (($plan['container'] ?? null) === 'throw') {
					throw new \RuntimeException('container down');
				}

				if ($id === ArchiMateService::class) {
					if (($plan['archimateMissing'] ?? false) === true) {
						throw new \RuntimeException('no archimate');
					}

					return $archimate;
				}

				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objects;
				}

				throw new \RuntimeException('no ' . $id);
			}
		);

		$reflection = new \ReflectionClass(SettingsService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		$properties = [
			'config'     => $config,
			'appManager' => $apps,
			'container'  => $container,
			'logger'     => $logger,
			'appName'    => 'stackiq',
		];
		foreach ($properties as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

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
		$file  = __DIR__ . '/golden/settingsServiceObjectCounts.json';
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
