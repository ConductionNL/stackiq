<?php

/**
 * ArchiMateImportServiceConvertTest
 *
 * The import and the round-trip check convert an exchange file the same way
 * (architecture-round-trip-check REQ-ART-002, task 2).
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
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
use Psr\Log\NullLogger;

/**
 * Converts the GEMMA fixture through the import (save recorded) and through convertFileToObjects.
 */
class ArchiMateImportServiceConvertTest extends TestCase {

	/**
	 * The fixture, relative to the repository root.
	 */
	private const FIXTURE = 'lib/Settings/GEMMA_testdata_below_1_5mb.xml';

	/**
	 * Every object the import handed to saveObjects, in order.
	 *
	 * @var list<array>
	 */
	private array $saved = [];

	/**
	 * The service with doubles for every collaborator; saveObjects records what it is given.
	 *
	 * @return ArchiMateImportService
	 */
	private function service(): ArchiMateImportService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'amef_config'
				? (string) json_encode(['register' => '7', 'model_schema' => '10', 'element_schema' => '11', 'relation_schema' => '12', 'view_schema' => '13', 'organization_schema' => '14', 'property_definition_schema' => '15'])
				: $default
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('importer');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObjects')->willReturnCallback(
			function (array $objects): array {
				array_push($this->saved, ...$objects);
				return ['saved' => [], 'updated' => [], 'unchanged' => [], 'invalid' => []];
			}
		);
		$objectService->method('searchObjects')->willReturn([]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => $id === ObjectService::class ? $objectService : null);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			static fn (string $type): ?int => ['model' => 10, 'element' => 11, 'relationship' => 12, 'view' => 13, 'organization' => 14, 'property_definition' => 15][$type] ?? null
		);
		$organisation = new Organisation();
		$organisation->setUuid('org-default');
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('ensureDefaultOrganisation')->willReturn($organisation);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$database = $this->createMock(IDBConnection::class);
		$database->method('executeQuery')->willReturn($result);
		$database->method('executeStatement')->willReturn(0);

		return new ArchiMateImportService(
			config: $config,
			userSession: $userSession,
			appManager: $appManager,
			container: $container,
			logger: new NullLogger(),
			settingsService: $settings,
			organisationService: $organisations,
			dbConnection: $database,
			progressTracker: $this->createMock(ProgressTracker::class)
		);
	}//end service()

	/**
	 * The objects keyed by their identifier, with the run's model identifier replaced.
	 *
	 * The fixture carries no model identifier, so each run generates its own;
	 * every object of a run must carry that run's identifier, and with it
	 * replaced the two runs must agree on everything else.
	 *
	 * @param list<array> $objects         The objects.
	 * @param string      $modelIdentifier The identifier the run stamped.
	 *
	 * @return array<string, array>
	 */
	private function keyed(array $objects, string $modelIdentifier): array {
		$keyed = [];
		foreach ($objects as $object) {
			if (array_key_exists('model_identifier', $object) === true) {
				$this->assertSame($modelIdentifier, $object['model_identifier']);
				$object['model_identifier'] = '<model>';
			}

			$keyed[(string) ($object['identifier'] ?? json_encode($object))] = $object;
		}

		ksort($keyed);
		return $keyed;
	}//end keyed()

	/**
	 * The import saves exactly the objects convertFileToObjects returns for the fixture.
	 *
	 * @return void
	 */
	public function testTheImportSavesWhatTheConversionReturns(): void {
		$path = dirname(__DIR__, 3) . '/' . self::FIXTURE;

		$conversion = $this->service()->convertFileToObjects(filePath: $path);
		$this->assertFalse($conversion['cancelled']);
		$this->assertSame($path, $conversion['filePath']);
		$this->assertNotSame('', $conversion['modelIdentifier']);
		$this->assertGreaterThan(100, count($conversion['objects']));

		$import = $this->service()->importArchiMateFileFromPathOptimized(options: ['filePath' => $path]);
		$this->assertTrue($import['success'], (string) ($import['error'] ?? ''));
		$this->assertSame(count($conversion['objects']), $import['performance_metrics']['objects_processed']);

		$saved = $this->saved;
		$importedModel = (string) ($saved[0]['model_identifier'] ?? '');
		$this->assertMatchesRegularExpression('/^model-/', $importedModel);
		$expected = $this->keyed(objects: $conversion['objects'], modelIdentifier: $conversion['modelIdentifier']);
		$actual = $this->keyed(objects: $saved, modelIdentifier: $importedModel);
		$this->assertSame(array_keys($expected), array_keys($actual));
		// Compared object by object, so a difference names its identifier instead of diffing two thousand objects.
		foreach ($expected as $identifier => $object) {
			$this->assertSame($object, $actual[$identifier], 'object ' . $identifier);
		}
	}//end testTheImportSavesWhatTheConversionReturns()

	/**
	 * An empty path and a missing file are refused before anything is parsed.
	 *
	 * @return void
	 */
	public function testAMissingFileIsRefused(): void {
		try {
			$this->service()->convertFileToObjects(filePath: '');
			$this->fail('An empty path was converted');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('File path is required for import', $e->getMessage());
		}

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('File not found: /nonexistent/model.xml');
		$this->service()->convertFileToObjects(filePath: '/nonexistent/model.xml');
	}//end testAMissingFileIsRefused()
}//end class
