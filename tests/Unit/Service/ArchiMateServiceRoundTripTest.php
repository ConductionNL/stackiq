<?php

/**
 * ArchiMateService runs the round-trip check in the mode the admin chose.
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
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/BuildsArchiMateImportService.php';

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\ArchiMateExportService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Tests\Unit\Support\BuildsArchiMateImportService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Stackiq\Service\ArchiMateService
 */
final class ArchiMateServiceRoundTripTest extends TestCase {
	use BuildsArchiMateImportService;

	/**
	 * The golden test model, model identifier id-model-golden.
	 */
	private const FIXTURE = __DIR__ . '/../../data/archimate_golden.xml';

	/**
	 * The queries sent to the stored objects.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The service over an AMEF register holding the given objects.
	 *
	 * @param list<array<string, mixed>> $stored The stored objects.
	 *
	 * @return ArchiMateService
	 */
	private function service(array $stored): ArchiMateService {
		$bySchema = [];
		foreach ($stored as $object) {
			$bySchema[$object['@self']['schema']][] = $object;
		}

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->never())->method('saveObjects');
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query) use ($bySchema): array {
				$this->queries[] = $query;
				return $bySchema[$query['@self']['schema']] ?? [];
			}
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objects): ?ObjectServiceInterface {
				if ($id === ObjectService::class) {
					return $objects;
				}

				return null;
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getAmefConfig')->willReturn(['register' => '7']);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			function (string $type): ?int {
				$ids = ['model' => 10, 'element' => 11, 'relationship' => 12, 'view' => 13, 'organization' => 14, 'property_definition' => 15];
				return $ids[$type] ?? null;
			}
		);

		return new ArchiMateService(
			config: $this->createMock(originalClassName: IAppConfig::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			appManager: $appManager,
			container: $container,
			logger: new NullLogger(),
			settingsService: $settings,
			importService: $this->importService(objectService: $this->createMock(originalClassName: ObjectServiceInterface::class)),
			exportService: new ArchiMateExportService(logger: new NullLogger()),
			progressTracker: $this->createMock(originalClassName: ProgressTracker::class)
		);
	}//end service()

	/**
	 * The objects an import of the fixture stores.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function storedObjects(): array {
		$converted = $this->importService(objectService: $this->createMock(originalClassName: ObjectServiceInterface::class))
			->convertFileToObjects(filePath: self::FIXTURE);
		$this->assertNotNull(actual: $converted);

		return $converted['objects'];
	}//end storedObjects()

	/**
	 * Before import, nothing stored is read.
	 *
	 * @return void
	 */
	public function testBeforeImportReadsNothingStored(): void {
		$report = $this->service(stored: [])->checkRoundTrip(filePath: self::FIXTURE, mode: 'before-import');

		$this->assertSame(expected: 'before-import', actual: $report['mode']);
		$this->assertTrue(condition: $report['imported']);
		$this->assertSame(expected: [], actual: $this->queries);
	}//end testBeforeImportReadsNothingStored()

	/**
	 * Against the imported model, the AMEF register's schemas are read and compared.
	 *
	 * @return void
	 */
	public function testAgainstImportedReadsTheAmefRegister(): void {
		$report = $this->service(stored: $this->storedObjects())->checkRoundTrip(filePath: self::FIXTURE, mode: 'against-imported');

		$this->assertSame(expected: 'against-imported', actual: $report['mode']);
		$this->assertTrue(condition: $report['imported']);
		$this->assertNotSame(expected: [], actual: $this->queries);
		foreach ($this->queries as $query) {
			$this->assertSame(expected: 7, actual: $query['@self']['register']);
		}
	}//end testAgainstImportedReadsTheAmefRegister()

	/**
	 * An empty register means the model is not imported.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterMeansNotImported(): void {
		$report = $this->service(stored: [])->checkRoundTrip(filePath: self::FIXTURE, mode: 'against-imported');

		$this->assertFalse(condition: $report['imported']);
	}//end testAnEmptyRegisterMeansNotImported()

	/**
	 * An unknown mode is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownModeIsRefused(): void {
		$this->expectException(exception: InvalidArgumentException::class);

		$this->service(stored: [])->checkRoundTrip(filePath: self::FIXTURE, mode: 'sideways');
	}//end testAnUnknownModeIsRefused()
}//end class
