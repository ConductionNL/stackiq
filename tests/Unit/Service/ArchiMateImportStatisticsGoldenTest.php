<?php

/**
 * Golden test for the statistics of an ArchiMate import.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The statistics ArchiMateImportService::calculateObjectStatistics() reports, as recorded.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateImportService
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
class ArchiMateImportStatisticsGoldenTest extends TestCase {

	/**
	 * The log lines, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The golden file.
	 *
	 * @return string
	 */
	private static function golden(): string {
		return __DIR__ . '/golden/archiMateImportStatistics.json';

	}//end golden()

	/**
	 * The cases: the last save result, the cached configuration and the normalised data.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$normalised = [
			'elements'             => [[], [], []],
			'relationships'        => [[]],
			'views'                => [],
			'property_definitions' => [[], []],
		];

		return [
			'no save result'                    => [['normalised' => $normalised]],
			'no save result and no data'        => [['normalised' => []]],
			'every outcome and section'         => [['save' => 'mixed', 'config' => null]],
			'schema fallback with configuration' => [['save' => 'mixed', 'config' => ['schemaIds' => ['view' => '4', 'relationship' => 5, 'other' => 6, 'model' => 7]]]],
			'configuration without schema ids'  => [['save' => 'mixed', 'config' => ['registerId' => 1]]],
			'empty save result'                 => [['save' => 'empty', 'config' => null]],
		];

	}//end cases()

	/**
	 * The statistics and log lines match the recording.
	 *
	 * @param array<string, mixed> $plan The case.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testStatisticsMatchTheRecording(array $plan): void {
		$this->trace = [];
		$service     = $this->service();

		$save = null;
		if (isset($plan['save']) === true) {
			$save = $this->saveResult(kind: $plan['save']);
		}

		(new ReflectionProperty($service, 'lastSaveResult'))->setValue($service, $save);
		(new ReflectionProperty($service, 'cachedConfig'))->setValue($service, ($plan['config'] ?? null));

		$method     = new ReflectionMethod($service, 'calculateObjectStatistics');
		$statistics = $method->invoke($service, ($plan['normalised'] ?? []));

		$this->check(key: (string) json_encode(value: $plan), value: ['statistics' => $statistics, 'log' => $this->trace]);

	}//end testStatisticsMatchTheRecording()

	/**
	 * A save result as ObjectService::saveObjects() reports it.
	 *
	 * @param string $kind Which result.
	 *
	 * @return array<string, mixed>
	 */
	private function saveResult(string $kind): array {
		if ($kind === 'empty') {
			return [];
		}

		return [
			'saved'     => [
				$this->entity(uuid: 'e-1', data: ['section' => 'element', '@self' => ['id' => 'e-1']]),
				$this->entity(uuid: 'm-1', data: ['section' => 'model', '@self' => ['id' => 'm-1']]),
				$this->entity(uuid: 'v-1', data: ['@self' => ['id' => 'v-1', 'schema' => 4]]),
			],
			'updated'   => [
				$this->entity(uuid: 'r-1', data: ['section' => 'relationships', '@self' => ['id' => 'r-1']]),
				$this->entity(uuid: 'o-1', data: ['section' => 'organization', 'identifier' => 'o-1']),
				$this->entity(uuid: 'x-1', data: ['@self' => ['id' => 'x-1', 'schema' => '6']]),
			],
			'unchanged' => [
				$this->entity(uuid: 'p-1', data: ['section' => 'property_definition', '@self' => ['id' => 'p-1']]),
				$this->entity(uuid: 'w-1', data: ['section' => 'views', '@self' => ['id' => 'w-1']]),
				$this->entity(uuid: 'u-1', data: ['section' => 'unknown', '@self' => ['id' => 'u-1', 'schema' => 99]]),
				$this->entity(uuid: 'z-1', data: ['@self' => ['id' => 'z-1', 'schema' => 7]]),
			],
			'invalid'   => [
				['object' => ['section' => 'elements', '@self' => ['id' => 'bad-1']], 'error' => 'Missing name'],
				['object' => ['section' => 'relationship', '@self' => ['id' => 'bad-2']]],
				['object' => ['section' => 'organizations', '@self' => ['id' => 'e-1']], 'error' => 'Duplicate'],
				['object' => ['section' => 'views']],
				['error' => 'No object at all'],
			],
		];

	}//end saveResult()

	/**
	 * A stored object double.
	 *
	 * @param string               $uuid The object UUID.
	 * @param array<string, mixed> $data The serialised object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		return new class($uuid, $data) extends ObjectEntity {
			/**
			 * A stored object.
			 *
			 * @param string               $uuid The object UUID.
			 * @param array<string, mixed> $data The serialised object.
			 */
			public function __construct(private string $uuid, private array $data) {
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
				return $this->uuid;
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
	 * The service with a recording logger.
	 *
	 * @return ArchiMateImportService
	 */
	private function service(): ArchiMateImportService {
		$test   = $this;
		$logger = new class($test) extends AbstractLogger {
			/**
			 * The test that records the lines.
			 *
			 * @param ArchiMateImportStatisticsGoldenTest $test The test.
			 */
			public function __construct(private ArchiMateImportStatisticsGoldenTest $test) {
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
				$this->test->appendTrace(entry: [$level, (string) $message, $context]);
			}
		};

		return new ArchiMateImportService(
			config: $this->createMock(IAppConfig::class),
			userSession: $this->createMock(IUserSession::class),
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			logger: $logger,
			settingsService: $this->createMock(ObjectTypeLocatorInterface::class),
			organisationService: $this->createMock(OrganisationService::class),
			dbConnection: $this->createMock(IDBConnection::class),
			progressTracker: $this->createMock(ProgressTracker::class)
		);

	}//end service()

	/**
	 * Appends one log line to the trace.
	 *
	 * @param array<int, mixed> $entry The log line.
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
