<?php

/**
 * Golden test for the SBOM import of a module version.
 *
 * Records, per case, every object service, progress tracker and logger call
 * importForModuleVersie() makes, plus its result or the exception that escapes.
 * The import time is masked. Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sbom-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SbomImportService;
use OCA\Stackiq\Service\SbomParserService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Golden cases for importForModuleVersie().
 *
 * @covers \OCA\Stackiq\Service\SbomImportService
 * @uses   \OCA\Stackiq\Service\SbomParserService
 *
 * @spec openspec/specs/sbom-import/spec.md
 */
class SbomImportGoldenTest extends TestCase {

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
	 * The cases: fixture (or generated document), format and the doubles' behaviour.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'cyclonedx 1.5'                 => ['cyclonedx-1.5-valid.json', 'cyclonedx-json', []],
			'cyclonedx 1.6, previous set'   => ['cyclonedx-1.6-valid.json', 'cyclonedx-json', ['previous' => ['old-1', 'old-2']]],
			'cyclonedx with vex'            => ['cyclonedx-with-vex.json', 'cyclonedx-json', []],
			'cyclonedx empty'               => ['cyclonedx-empty-components.json', 'cyclonedx-json', []],
			'spdx 2.3'                      => ['spdx-2.3-valid.json', 'spdx-json', []],
			'unsupported'                   => ['cyclonedx-invalid-format.json', 'cyclonedx-json', []],
			'module version missing'        => ['cyclonedx-1.5-valid.json', 'cyclonedx-json', ['missing' => true]],
			'large set, progress tracked'   => ['(generated:60)', 'cyclonedx-json', []],
			'threshold set, not tracked'    => ['(generated:50)', 'cyclonedx-json', []],
		];
	}//end cases()

	/**
	 * Each case matches its recording.
	 *
	 * @param string               $file   The fixture, or "(generated:N)".
	 * @param string               $format The upload format.
	 * @param array<string, mixed> $plan   The behaviour of the doubles.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testImportMatchesTheRecording(string $file, string $format, array $plan): void {
		$this->plan  = $plan;
		$this->trace = [];
		try {
			$result = $this->service()->importForModuleVersie('mv-1', $this->document(file: $file), $format, 'sbom.json');
		} catch (\Throwable $e) {
			$result = ['escaped' => get_class($e), 'message' => $e->getMessage()];
		}

		$this->check(key: $this->dataName(), value: ['result' => $result, 'calls' => $this->trace]);
	}//end testImportMatchesTheRecording()

	/**
	 * The status reports the provenance a module version records, and nulls without one.
	 *
	 * @return void
	 */
	public function testTheStatusReportsTheProvenance(): void {
		$this->plan = ['versionData' => ['sbomLastImportedAt' => '2026-11-03T10:00:00+00:00', 'sbomFormat' => 'spdx-json', 'sbomFileName' => 'a.json']];
		self::assertSame(
			['sbomLastImportedAt' => '2026-11-03T10:00:00+00:00', 'sbomFormat' => 'spdx-json', 'sbomFileName' => 'a.json', 'progress' => null],
			$this->service()->getStatus('mv-1')
		);

		$this->plan = ['missing' => true];
		self::assertSame(
			['sbomLastImportedAt' => null, 'sbomFormat' => null, 'sbomFileName' => null, 'progress' => null],
			$this->service()->getStatus('mv-1')
		);
	}//end testTheStatusReportsTheProvenance()

	/**
	 * The raw document for a case.
	 *
	 * @param string $file The fixture, or "(generated:N)" for N CycloneDX components.
	 *
	 * @return string The document.
	 */
	private function document(string $file): string {
		if (preg_match('/^\(generated:(\d+)\)$/', $file, $m) === 1) {
			$components = [];
			for ($i = 0; $i < (int) $m[1]; $i++) {
				$components[] = ['type' => 'library', 'name' => 'lib-' . $i, 'version' => '1.' . $i, 'purl' => 'pkg:npm/lib-' . $i . '@1.' . $i];
			}

			return (string) json_encode(['bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'components' => $components]);
		}

		return (string) file_get_contents(__DIR__ . '/../../fixtures/sbom/' . $file);
	}//end document()

	/**
	 * The service with recording doubles.
	 *
	 * @return SbomImportService
	 */
	private function service(): SbomImportService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => 1]);
		$settings->method('getSchemaIdForObjectType')->willReturnMap([['moduleVersion', 10], ['sbomComponent', 20], ['module', 30]]);

		$objects   = $this->objectService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

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
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->trace[] = ['log.' . $level, (string) $message, $context];
			}
		};

		return new SbomImportService($container, $settings, new SbomParserService(), $this->progressTracker(), $logger);
	}//end service()

	/**
	 * An object service that records every call.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$version = $this->createMock(ObjectEntity::class);
		$version->method('getObject')->willReturn($this->plan['versionData'] ?? ['id' => 'mv-1', 'version' => '1.0.0']);
		$version->method('getUuid')->willReturn('mv-1');

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (...$args) use ($version) {
				$this->trace[] = ['objects.find', $args];
				return isset($this->plan['missing']) === true ? null : $version;
			}
		);
		$objects->method('searchObjects')->willReturnCallback(
			function (...$args) {
				$this->trace[] = ['objects.searchObjects', $args[0] ?? null];
				$found = [];
				foreach ($this->plan['previous'] ?? [] as $uuid) {
					$entity = $this->createMock(ObjectEntity::class);
					$entity->method('getUuid')->willReturn($uuid);
					$entity->method('getObject')->willReturn(['id' => $uuid]);
					$found[] = $entity;
				}

				return $found;
			}
		);
		$objects->method('deleteObjects')->willReturnCallback(
			function (array $uuids) {
				$this->trace[] = ['objects.deleteObjects', $uuids];
				return ['deleted_uuids' => $uuids, 'skipped_uuids' => [], 'cascade_count' => 0];
			}
		);
		$objects->method('saveObjects')->willReturnCallback(
			function (array $objects, ?BulkSaveOptions $options = null) {
				$this->trace[] = ['objects.saveObjects', count($objects), $objects[0] ?? null, $options?->defaults->register, $options?->defaults->schema];
				return ['statistics' => ['objectsCreated' => count($objects)]];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, $extend = null, $register = null, $schema = null) use ($version) {
				if (isset($object['sbomLastImportedAt']) === true) {
					$object['sbomLastImportedAt'] = '(time)';
				}

				$this->trace[] = ['objects.saveObject', $object, $register, $schema];
				return $version;
			}
		);

		return $objects;
	}//end objectService()

	/**
	 * A progress tracker that records every call.
	 *
	 * @return ProgressTracker
	 */
	private function progressTracker(): ProgressTracker {
		$tracker = $this->createMock(ProgressTracker::class);
		$tracker->method('startOperation')->willReturnCallback(
			function (...$args): string {
				$this->trace[] = ['progress.startOperation', $args];
				return 'op-1';
			}
		);
		foreach (['setPhase', 'updateProgress', 'completeOperation'] as $method) {
			$tracker->method($method)->willReturnCallback(
				function (...$args) use ($method): void {
					$this->trace[] = ['progress.' . $method, $args];
				}
			);
		}

		return $tracker;
	}//end progressTracker()

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
		$file  = __DIR__ . '/golden/sbomImport.json';
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
