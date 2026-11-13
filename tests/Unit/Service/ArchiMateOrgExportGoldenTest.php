<?php

/**
 * Golden test for the organisation ArchiMate export.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\ArchiMateExportService;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * The result, the OpenRegister calls, the export call and the log lines of
 * ArchiMateService::exportOrgArchiMate(), as recorded.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateService
 *
 * @spec openspec/specs/archimate-import/spec.md
 * @uses \OCA\Stackiq\Service\ArchiMate\AmefRegisterId
 * @uses \OCA\Stackiq\Service\ArchiMate\ObjectServiceLookup
 */
class ArchiMateOrgExportGoldenTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The behaviour of the doubles.
	 *
	 * @var array<string, mixed>
	 */
	private array $plan = [];

	/**
	 * The golden file.
	 *
	 * @return string
	 */
	private static function golden(): string {
		return __DIR__ . '/golden/archiMateOrgExport.json';

	}//end golden()

	/**
	 * The cases: the export options and the behaviour of the doubles.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$full = ['register' => 'reg-v', 'organisatie_schema' => 'schema-org', 'gebruik_schema' => 'schema-gebruik', 'module_schema' => 'schema-module'];

		return [
			'openregister not installed'      => [[], ['noOpenRegister' => true]],
			'organisation not configured'     => [[], ['config' => [], 'noFallback' => true]],
			'fallback register and schema'    => [[], ['config' => ['gebruik_schema' => 'schema-gebruik']]],
			'organisation lookup throws'      => [[], ['config' => $full, 'findThrows' => true]],
			'organisation not found'          => [[], ['config' => $full, 'notFound' => true]],
			'amef register not configured'    => [[], ['config' => $full, 'noAmef' => true]],
			'no gebruik and module schema'    => [[], ['config' => ['register' => 'reg-v', 'organisatie_schema' => 'schema-org']]],
			'gebruik and modules'             => [['format' => 'x'], ['config' => $full]],
			'deelnames'                       => [['deelnames' => true], ['config' => $full]],
			'deelnames without gebruik'       => [['deelnames' => true], ['config' => ['register' => 'reg-v', 'organisatie_schema' => 'schema-org', 'module_schema' => 'schema-module']]],
			'deelnames, nothing found'        => [['deelnames' => true], ['config' => $full, 'noDeelnames' => true]],
			'organisation named in @self'     => [[], ['config' => $full, 'orgData' => ['@self' => ['name' => 'Self Naam']]]],
			'organisation without a name'     => [[], ['config' => $full, 'orgData' => []]],
			'export fails'                    => [[], ['config' => $full, 'exportThrows' => true]],
		];

	}//end cases()

	/**
	 * The export answers, calls and logs as recorded.
	 *
	 * @param array<string, mixed> $options The export options.
	 * @param array<string, mixed> $plan    The behaviour of the doubles.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testExportMatchesTheRecording(array $options, array $plan): void {
		$this->plan  = $plan;
		$this->trace = [];
		$result      = $this->service()->exportOrgArchiMate('org-uuid', $options);
		if (isset($result['file_name']) === true) {
			$result['file_name'] = preg_replace('/^\d{2}-\d{2}-\d{4}_/', 'DD-MM-YYYY_', $result['file_name']);
		}

		$this->check(key: (string) json_encode(value: [$options, $plan]), value: ['result' => $result, 'calls' => $this->trace]);

	}//end testExportMatchesTheRecording()

	/**
	 * The service with recording doubles.
	 *
	 * @return ArchiMateService
	 */
	private function service(): ArchiMateService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(isset($this->plan['noOpenRegister']) === false);

		$objects   = $this->objectService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturnCallback(
			function (): array {
				$this->trace[] = ['settings.getVoorzieningenConfig'];
				return ($this->plan['config'] ?? []);
			}
		);
		$settings->method('getVoorzieningenRegisterId')->willReturnCallback(
			function (): ?int {
				$this->trace[] = ['settings.getVoorzieningenRegisterId'];
				if (isset($this->plan['noFallback']) === true) {
					return null;
				}

				return 31;
			}
		);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			function (string $type): ?int {
				$this->trace[] = ['settings.getSchemaIdForObjectType', $type];
				if ($type === 'organization' && isset($this->plan['noFallback']) === true) {
					return null;
				}

				return (crc32($type) % 90) + 10;
			}
		);
		$settings->method('getAmefConfig')->willReturnCallback(
			function (): array {
				$this->trace[] = ['settings.getAmefConfig'];
				if (isset($this->plan['noAmef']) === true) {
					return [];
				}

				return ['register' => '12'];
			}
		);

		$export = $this->createMock(ArchiMateExportService::class);
		$export->method('exportOrganizationArchiMateXml')->willReturnCallback(
			function (...$arguments): string {
				array_shift($arguments);
				$this->trace[] = ['export.exportOrganizationArchiMateXml', $this->plain(value: $arguments)];
				if (isset($this->plan['exportThrows']) === true) {
					throw new \RuntimeException('export down');
				}

				return '<model/>';
			}
		);

		return new ArchiMateService(
			config: $config,
			userSession: $this->createMock(IUserSession::class),
			appManager: $appManager,
			container: $container,
			logger: $this->logger(),
			settingsService: $settings,
			importService: $this->createMock(ArchiMateImportService::class),
			exportService: $export,
			progressTracker: $this->createMock(ProgressTracker::class)
		);

	}//end service()

	/**
	 * An object service over the planned organisation, gebruik and modules.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (...$arguments) {
				$this->trace[] = ['objects.find', $arguments];
				if (isset($this->plan['findThrows']) === true) {
					throw new \RuntimeException('find down');
				}

				if (isset($this->plan['notFound']) === true) {
					return null;
				}

				return $this->entity(data: ($this->plan['orgData'] ?? ['name' => 'Gemeente Test']));
			}
		);
		$objects->method('searchObjects')->willReturnCallback(
			function (...$arguments): array {
				$this->trace[] = ['objects.searchObjects', $arguments];
				$query = $arguments[0];
				if (isset($query['participants']) === true) {
					if (isset($this->plan['noDeelnames']) === true) {
						return [];
					}

					return [['id' => 'deelname-1']];
				}

				return [['id' => 'found-' . $query['@self']['schema']]];
			}
		);

		return $objects;

	}//end objectService()

	/**
	 * A stored object double that serialises to its data.
	 *
	 * @param array<string, mixed> $data The serialised object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		return new class($data) extends ObjectEntity {
			/**
			 * A stored object.
			 *
			 * @param array<string, mixed> $data The serialised object.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * The object id.
			 *
			 * @return int
			 */
			public function getId() {
				return 1;
			}

			/**
			 * The object UUID.
			 *
			 * @return string|null
			 */
			public function getUuid(): ?string {
				return 'org-uuid';
			}

			/**
			 * The object data.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * The register id.
			 *
			 * @return string|null
			 */
			public function getRegister(): ?string {
				return null;
			}

			/**
			 * The schema id.
			 *
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return null;
			}

			/**
			 * Replaces the object data.
			 *
			 * @param mixed $object The object data.
			 *
			 * @return static
			 */
			public function setObject($object = null) {
				$this->data = (array) $object;
				return $this;
			}

			/**
			 * The serialised object.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};

	}//end entity()

	/**
	 * A value with every object replaced by its class name, so it can be recorded.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function plain(mixed $value): mixed {
		if (is_object($value) === true) {
			return 'object';
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->plain(value: $item), $value);
		}

		return $value;

	}//end plain()

	/**
	 * A logger that records level, message and context without traces.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$test = $this;
		return new class($test) extends AbstractLogger {
			/**
			 * The test that records the lines.
			 *
			 * @param ArchiMateOrgExportGoldenTest $test The test.
			 */
			public function __construct(private ArchiMateOrgExportGoldenTest $test) {
			}

			/**
			 * Records one log line.
			 *
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				unset($context['trace']);
				$this->test->appendTrace(entry: ['log', $level, (string) $message, $context]);
			}
		};

	}//end logger()

	/**
	 * Appends one entry to the trace.
	 *
	 * @param array<int, mixed> $entry The entry.
	 *
	 * @return void
	 */
	public function appendTrace(array $entry): void {
		$this->trace[] = $entry;

	}//end appendTrace()

	/**
	 * Compares a value with its recording, or records it when RECORD_GOLDEN is set.
	 *
	 * @param string               $key   The case key.
	 * @param array<string, mixed> $value The observed value.
	 *
	 * @return void
	 */
	private function check(string $key, array $value): void {
		$json  = json_decode(json: (string) json_encode(value: $value), associative: true);
		$file  = self::golden();
		$saved = [];
		if (file_exists(filename: $file) === true) {
			$saved = json_decode(json: (string) file_get_contents(filename: $file), associative: true);
		}

		if (getenv(name: 'RECORD_GOLDEN') !== false) {
			$saved[$key] = $json;
			file_put_contents(filename: $file, data: json_encode(value: $saved, flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertArrayHasKey(key: $key, array: $saved, message: 'No golden for ' . $key);
		$this->assertSame(expected: $saved[$key], actual: $json);

	}//end check()
}//end class
