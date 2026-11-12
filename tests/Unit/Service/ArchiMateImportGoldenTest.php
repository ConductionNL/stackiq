<?php

/**
 * Golden test for the ArchiMate import.
 *
 * Runs both import entry points over ArchiMate fixtures with recording doubles
 * and records, per case, every object service, progress tracker, database and
 * logger call plus the import result. Values that depend on the clock or the
 * memory of the run are masked, and the checkout path reads <root>.
 * Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Golden cases for the ArchiMate import.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateImportService
 * @uses   \OCA\Stackiq\Service\ArchiMate\ArchiMateImportScope
 * @uses   \OCA\Stackiq\Service\ArchiMate\GemmaStandardLinker
 * @uses   \OCA\Stackiq\Service\ArchiMate\GemmaObjectReader
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class ArchiMateImportGoldenTest extends TestCase {

	/**
	 * Context keys whose values depend on the clock or the memory of the run.
	 */
	private const VOLATILE = '/(time|seconds|memory|_mb$|cycles|per_second|percent|published|extracted_at|trace)/';

	/**
	 * Schema ids the AMEF configuration names.
	 */
	private const AMEF_CONFIG = [
		'register'                   => '7',
		'model_schema'               => '10',
		'element_schema'             => '11',
		'relation_schema'            => '12',
		'view_schema'                => '13',
		'organization_schema'        => '14',
		'property_definition_schema' => '15',
	];

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Per-case behaviour of the doubles.
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
		return __DIR__ . '/golden/archiMateImport.json';

	}//end golden()

	/**
	 * The cases: a fixture, an entry point and the behaviour of the doubles.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function cases(): array {
		$data     = __DIR__ . '/../../data/';
		$fixtures = __DIR__ . '/../../fixtures/archimate/';
		$golden   = $data . 'archimate_golden.xml';
		return [
			'sample optimized'              => [$data . 'sample_archimate.xml', 'optimized', []],
			'sample classic'                => [$data . 'sample_archimate.xml', 'classic', []],
			'golden optimized'              => [$golden, 'optimized', []],
			'golden classic'                => [$golden, 'classic', []],
			'golden optimized tracked'      => [$golden, 'optimized', ['operationId' => 'archimate_import_golden01']],
			'golden optimized cancelled'    => [$golden, 'optimized', ['operationId' => 'archimate_import_golden02', 'cancelOnCall' => 4]],
			'golden optimized failing save' => [$golden, 'optimized', ['failSchema' => 12]],
			'golden optimized no org'       => [$golden, 'optimized', ['noOrganisation' => true]],
			'golden classic model exists'   => [$golden, 'classic', ['modelExists' => true]],
			'golden classic no view schema' => [$golden, 'optimized', ['noViewSchema' => true]],
			'big1 optimized'                => [$fixtures . 'big1.xml', 'optimized', []],
			'big1 classic'                  => [$fixtures . 'big1.xml', 'classic', []],
			'big2 optimized'                => [$fixtures . 'big2.xml', 'optimized', []],
			'big2 classic'                  => [$fixtures . 'big2.xml', 'classic', []],
			'noorg optimized'               => [$fixtures . 'noorg.xml', 'optimized', []],
			'noorg classic'                 => [$fixtures . 'noorg.xml', 'classic', []],
		];

	}//end cases()

	/**
	 * Each case matches its recording.
	 *
	 * @param string               $file  The ArchiMate file.
	 * @param string               $entry Which entry point runs.
	 * @param array<string, mixed> $plan  The behaviour of the doubles.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testImportMatchesTheRecording(string $file, string $entry, array $plan): void {
		$this->plan  = $plan;
		$this->trace = [];
		$service     = $this->service();
		$options     = ['file_path' => $file, 'fileName' => basename($file)];
		if (isset($plan['operationId']) === true) {
			$options['operationId'] = $plan['operationId'];
		}

		try {
			if ($entry === 'optimized') {
				$result = $service->importArchiMateFileFromPathOptimized($options);
			} else {
				$result = $service->importArchiMateFileFromPath($options);
			}
		} catch (\Throwable $e) {
			$message = preg_replace('/ (called )?in \/\S+ on line \d+/', '', $e->getMessage());
			$result  = ['escaped' => get_class($e), 'message' => $message];
		}

		$key = $entry . ' ' . basename($file) . ' ' . json_encode($plan);
		$this->check(key: $key, value: ['result' => $result, 'calls' => $this->trace]);

	}//end testImportMatchesTheRecording()

	/**
	 * The service with recording doubles for every collaborator.
	 *
	 * @return ArchiMateImportService
	 */
	private function service(): ArchiMateImportService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $key === 'amef_config' ? json_encode(self::AMEF_CONFIG) : $default
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('importer');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$objectService = $this->objectService();
		$container     = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $id === ObjectService::class ? $objectService : null
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			function (string $type): ?int {
				$this->trace[] = ['settings.getSchemaIdForObjectType', $type];
				if ($type === 'view' && isset($this->plan['noViewSchema']) === true) {
					return null;
				}

				$ids = ['model' => 10, 'element' => 11, 'relationship' => 12, 'view' => 13, 'organization' => 14, 'property_definition' => 15];
				return $ids[$type] ?? null;
			}
		);

		return new ArchiMateImportService(
			config: $config,
			userSession: $userSession,
			appManager: $appManager,
			container: $container,
			logger: $this->logger(),
			settingsService: $settings,
			organisationService: $this->organisationService(),
			dbConnection: $this->database(),
			progressTracker: $this->progressTracker()
		);

	}//end service()

	/**
	 * An object service that records the saves and the existence query.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObjects')->willReturnCallback(
			function (array $objects, $register, $schema, $rbac, $multitenancy, $validation, $events): array {
				$this->trace[] = [
					'objects.saveObjects',
					[
						'objects'       => $objects,
						'register'      => $register,
						'schema'        => $schema,
						'_rbac'         => $rbac,
						'_multitenancy' => $multitenancy,
						'validation'    => $validation,
						'events'        => $events,
					],
				];
				if (($this->plan['failSchema'] ?? null) === $schema) {
					throw new \RuntimeException('save failed for schema ' . $schema);
				}

				$ids = array_map(fn (array $object) => $this->savedEntity(id: $object['@self']['id'] ?? null), $objects);
				return ['saved' => array_slice($ids, 1), 'updated' => array_slice($ids, 0, 1), 'unchanged' => [], 'invalid' => []];
			}
		);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				$this->trace[] = ['objects.searchObjects', $query];
				if (isset($this->plan['modelExists']) === true) {
					return [['id' => 'existing-model']];
				}

				return [];
			}
		);

		return $objectService;

	}//end objectService()

	/**
	 * A saved object as the object service hands it back.
	 *
	 * @param string|null $id The object id.
	 *
	 * @return object
	 */
	private function savedEntity(?string $id): object {
		return new class($id) implements \JsonSerializable {
			/**
			 * Build the saved object.
			 *
			 * @param string|null $id The object id.
			 */
			public function __construct(private ?string $id) {
			}

			/**
			 * @return string|null
			 */
			public function getUuid(): ?string {
				return $this->id;
			}

			/**
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['id' => $this->id];
			}
		};

	}//end savedEntity()

	/**
	 * An organisation service that hands out the default organisation, or fails.
	 *
	 * @return OrganisationService
	 */
	private function organisationService(): OrganisationService {
		$organisation = new Organisation();
		$organisation->setUuid('org-default');
		$service = $this->createMock(OrganisationService::class);
		if (isset($this->plan['noOrganisation']) === true) {
			$service->method('ensureDefaultOrganisation')->willThrowException(new \RuntimeException('no default organisation'));
			return $service;
		}

		$service->method('ensureDefaultOrganisation')->willReturn($organisation);
		return $service;

	}//end organisationService()

	/**
	 * A database whose Standaard query returns one row and whose updates are recorded.
	 *
	 * @return IDBConnection
	 */
	private function database(): IDBConnection {
		$rows   = [['identifier' => 'id-std-1', '_uuid' => 'uuid-std-1']];
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(
			function () use (&$rows) {
				return array_shift($rows) ?? false;
			}
		);
		$database = $this->createMock(IDBConnection::class);
		$database->method('executeQuery')->willReturnCallback(
			function (string $sql) use ($result): IResult {
				$this->trace[] = ['db.executeQuery', $sql];
				return $result;
			}
		);
		$database->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				$this->trace[] = ['db.executeStatement', $sql, $params];
				return 1;
			}
		);

		return $database;

	}//end database()

	/**
	 * A progress tracker that records every call and cancels on the planned check.
	 *
	 * @return ProgressTracker
	 */
	private function progressTracker(): ProgressTracker {
		$tracker = $this->createMock(ProgressTracker::class);
		$tracker->method('startOperation')->willReturnCallback(
			function (string $operationType, array $options = [], ?string $ownerUid = null, ?string $operationId = null): string {
				$this->trace[] = ['progress.startOperation', $operationType, $operationId];
				return (string) $operationId;
			}
		);
		$checks = 0;
		$tracker->method('isCancelRequested')->willReturnCallback(
			function (string $operationId) use (&$checks): bool {
				$checks++;
				$this->trace[] = ['progress.isCancelRequested', $operationId, $checks];
				return $checks === ($this->plan['cancelOnCall'] ?? 0);
			}
		);
		foreach (['setPhase', 'updateProgress', 'completeOperation', 'failOperation', 'cancelOperation'] as $method) {
			$tracker->method($method)->willReturnCallback(
				function (...$args) use ($method): void {
					$this->trace[] = ['progress.' . $method, $args];
				}
			);
		}

		return $tracker;

	}//end progressTracker()

	/**
	 * A logger that records every line.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$trace = &$this->trace;
		return new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record one line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$message = (string) $message;
				if (str_contains($message, '#0 ') === true) {
					$message = '<stack trace>';
				}

				$this->trace[] = ['log.' . $level, $message, $context];
			}
		};

	}//end logger()

	/**
	 * Mask the values that depend on the clock or the memory of the run.
	 *
	 * @param mixed $value The recorded value.
	 *
	 * @return mixed
	 */
	private static function mask(mixed $value): mixed {
		if (is_string($value) === true) {
			return str_replace(dirname(__DIR__, 3), '<root>', $value);
		}

		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			if (is_string($key) === true && preg_match(self::VOLATILE, $key) === 1) {
				$value[$key] = '<volatile>';
				continue;
			}

			$value[$key] = self::mask($item);
		}

		return $value;

	}//end mask()

	/**
	 * Compare a case with the golden file, or record it.
	 *
	 * @param string $key   The case.
	 * @param array  $value What the case observed.
	 *
	 * @return void
	 */
	private function check(string $key, array $value): void {
		$json  = self::mask(json_decode(json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), true));
		$file  = self::golden();
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
