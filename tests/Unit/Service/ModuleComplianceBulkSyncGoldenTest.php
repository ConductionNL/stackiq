<?php

/**
 * Golden test for the bulk sync of module standards.
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
use OCA\Stackiq\Service\ModuleComplianceService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * The result, the OpenRegister calls and the log lines of
 * ModuleComplianceService::bulkSyncModuleStandards(), as recorded.
 *
 * @covers \OCA\Stackiq\Service\ModuleComplianceService
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
class ModuleComplianceBulkSyncGoldenTest extends TestCase {

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
		return __DIR__ . '/golden/moduleComplianceBulkSync.json';

	}//end golden()

	/**
	 * The cases: the behaviour of the doubles.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'no compliance schema'      => [['noComplianceSchema' => true]],
			'no register'               => [['noRegister' => true]],
			'no module schema'          => [['noModuleSchema' => true]],
			'no object service'         => [['noObjectService' => true]],
			'search fails'              => [['searchThrows' => true]],
			'no compliance objects'     => [['compliance' => []]],
			'every kind of module'      => [['compliance' => 'mixed']],
			'more than five of each'    => [['compliance' => 'many']],
			'save fails for one module' => [['compliance' => 'mixed', 'saveThrows' => 'mod-update']],
		];

	}//end cases()

	/**
	 * The bulk sync answers, calls and logs as recorded.
	 *
	 * @param array<string, mixed> $plan The behaviour of the doubles.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testBulkSyncMatchesTheRecording(array $plan): void {
		$this->plan  = $plan;
		$this->trace = [];
		$result      = null;
		$error       = null;
		try {
			$result = $this->service()->bulkSyncModuleStandards();
		} catch (\Exception $e) {
			$error = [get_class($e), $e->getMessage()];
		}

		$this->check(key: (string) json_encode(value: $plan), value: ['result' => $result, 'error' => $error, 'calls' => $this->trace]);

	}//end testBulkSyncMatchesTheRecording()

	/**
	 * The service with recording doubles.
	 *
	 * @return ModuleComplianceService
	 */
	private function service(): ModuleComplianceService {
		$objectService = $this->objectService();
		$container     = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				$this->trace[] = ['container.get', $id];
				if (isset($this->plan['noObjectService']) === true) {
					throw new \RuntimeException('object service down');
				}

				return $objectService;
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			function (string $type): ?int {
				$this->trace[] = ['settings.getSchemaIdForObjectType', $type];
				if ($type === 'compliancy') {
					return isset($this->plan['noComplianceSchema']) === true ? null : 7;
				}

				return isset($this->plan['noModuleSchema']) === true ? null : 9;
			}
		);
		$settings->method('getVoorzieningenConfig')->willReturnCallback(
			function (): array {
				$this->trace[] = ['settings.getVoorzieningenConfig'];
				return isset($this->plan['noRegister']) === true ? [] : ['register' => '3'];
			}
		);

		return new ModuleComplianceService(container: $container, settingsService: $settings, logger: $this->logger());

	}//end service()

	/**
	 * An object service over the planned compliance objects and modules.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				$this->trace[] = ['objects.searchObjects', $query];
				if (isset($this->plan['searchThrows']) === true) {
					throw new \RuntimeException('search down');
				}

				return $this->complianceObjects();
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null) {
				$this->trace[] = ['objects.find', $id, $register, $schema];
				return $this->module(uuid: (string) $id);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) {
				$this->trace[] = ['objects.saveObject', $object, $extend, $register, $schema, $uuid];
				if (($this->plan['saveThrows'] ?? null) === $uuid) {
					throw new \RuntimeException('save down');
				}

				return $this->record(id: 0, data: $object, uuid: $uuid);
			}
		);

		return $objects;

	}//end objectService()

	/**
	 * The planned compliance objects.
	 *
	 * @return array<int, object>
	 */
	private function complianceObjects(): array {
		$plan = ($this->plan['compliance'] ?? []);
		if ($plan === 'mixed') {
			return [
				$this->record(id: 1, data: ['uuid' => 'c-1', 'module' => 'mod-update', 'standardVersion' => 'sv-1']),
				$this->record(id: 2, data: ['uuid' => 'c-2', 'module' => ['uuid' => 'mod-update'], 'standardVersion' => ['uuid' => 'sv-2']]),
				$this->record(id: 3, data: ['uuid' => 'c-3', 'module' => (object) ['uuid' => 'mod-current'], 'standardVersion' => 'sv-3']),
				$this->record(id: 4, data: ['uuid' => 'c-4', 'standardVersion' => 'sv-4']),
				$this->record(id: 5, data: ['module' => 42]),
				$this->record(id: 6, data: ['uuid' => 'c-6', 'module' => 'mod-empty']),
				$this->record(id: 7, data: ['uuid' => 'c-7', 'module' => 'mod-missing', 'standardVersion' => 'sv-7']),
				$this->record(id: 8, data: ['uuid' => 'c-8', 'module' => 'mod-throws', 'standardVersion' => 'sv-8']),
				$this->record(id: 9, data: ['uuid' => 'c-9', 'module' => 'mod-scalar', 'standardVersion' => 'sv-9']),
			];
		}

		if ($plan === 'many') {
			$records = [];
			for ($i = 1; $i <= 14; $i++) {
				$kind      = ['mod-update', 'mod-empty', 'mod-current'][($i % 3)];
				$records[] = $this->record(
					id: $i,
					data: ['uuid' => 'c-' . $i, 'module' => $kind . '-' . $i] + ($kind === 'mod-empty' ? [] : ['standardVersion' => 'sv-' . $i])
				);
			}

			return $records;
		}

		return [];

	}//end complianceObjects()

	/**
	 * The module stored under a UUID, or null when none is.
	 *
	 * @param string $uuid The module UUID.
	 *
	 * @return ObjectEntity|null
	 */
	private function module(string $uuid): ?ObjectEntity {
		if (str_starts_with($uuid, 'mod-missing') === true) {
			return null;
		}

		if (str_starts_with($uuid, 'mod-throws') === true) {
			throw new \RuntimeException('find down');
		}

		$data = ['uuid' => $uuid, 'name' => 'Module ' . $uuid];
		if (str_starts_with($uuid, 'mod-current') === true) {
			$data['standardVersions'] = ['sv-3'];
			if ($uuid !== 'mod-current') {
				$data['standardVersions'] = ['sv-' . substr($uuid, 12)];
				unset($data['name']);
				$data['title'] = 'Titled ' . $uuid;
			}
		}

		if ($uuid === 'mod-scalar') {
			$data['standardVersions'] = 'sv-9';
		}

		return $this->record(id: crc32($uuid) % 1000, data: $data, uuid: $uuid);

	}//end module()

	/**
	 * A stored object double.
	 *
	 * @param int                  $id   The object id.
	 * @param array<string, mixed> $data The object data.
	 * @param string|null          $uuid The object UUID.
	 *
	 * @return ObjectEntity
	 */
	private function record(int $id, array $data, ?string $uuid = null): ObjectEntity {
		return new class($id, $data, $uuid) extends ObjectEntity {
			/**
			 * A stored object.
			 *
			 * @param int                  $id   The object id.
			 * @param array<string, mixed> $data The object data.
			 * @param string|null          $uuid The object UUID.
			 */
			public function __construct(private int $id, private array $data, private ?string $uuid) {
			}

			/**
			 * The object id.
			 *
			 * @return int
			 */
			public function getId() {
				return $this->id;
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
				return '3';
			}

			/**
			 * The schema id.
			 *
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return '9';
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
			 * The object data.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};

	}//end record()

	/**
	 * A logger that records level and message.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$test = $this;
		return new class($test) extends AbstractLogger {
			/**
			 * The test that records the lines.
			 *
			 * @param ModuleComplianceBulkSyncGoldenTest $test The test.
			 */
			public function __construct(private ModuleComplianceBulkSyncGoldenTest $test) {
			}

			/**
			 * Records one log line without its volatile context.
			 *
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				unset($context['trace'], $context['executionTimeMs'], $context['file'], $context['line']);
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
