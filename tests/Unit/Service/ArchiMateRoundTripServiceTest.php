<?php

/**
 * ArchiMateRoundTripServiceTest
 *
 * The round-trip check's two modes write nothing, compare what the import and
 * export really do, and name a model that was never imported
 * (architecture-round-trip-check REQ-ART-002, task 3).
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
use OCA\Stackiq\Service\ArchiMateExportService;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ArchiMateModelComparator;
use OCA\Stackiq\Service\ArchiMateRoundTripService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
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
 * Tests for ArchiMateRoundTripService.
 */
class ArchiMateRoundTripServiceTest extends TestCase {

	/**
	 * The GEMMA fixture.
	 */
	private const GEMMA = 'lib/Settings/GEMMA_testdata_below_1_5mb.xml';

	/**
	 * The small golden model, identifier id-model-golden.
	 */
	private const GOLDEN = 'tests/data/archimate_golden.xml';

	/**
	 * The object counts the export generated from.
	 *
	 * @var list<int>
	 */
	private array $generated = [];

	/**
	 * A repository file.
	 *
	 * @param string $path Relative to the repository root.
	 *
	 * @return string
	 */
	private static function file(string $path): string {
		return dirname(__DIR__, 3) . '/' . $path;
	}//end file()

	private function importService(): ArchiMateImportService {
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
		// The check must never save: any call fails the test.
		$objectService->expects($this->never())->method('saveObjects');
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
	}//end importService()

	/**
	 * An export that records how many objects it generated from.
	 *
	 * @return ArchiMateExportService
	 */
	private function exportService(): ArchiMateExportService {
		$generated = &$this->generated;
		return new class(logger: new NullLogger(), generated: $generated) extends ArchiMateExportService {
			/**
			 * Build the recording export.
			 *
			 * @param NullLogger $logger    The logger.
			 * @param array      $generated The shared record.
			 */
			public function __construct(NullLogger $logger, private array &$generated) {
				parent::__construct(logger: $logger);
			}

			/**
			 * Record the call and generate as the export does.
			 *
			 * @param array $objects The AMEF objects.
			 *
			 * @return string
			 */
			public function generateXmlFromObjects(array $objects): string {
				$this->generated[] = count($objects);
				return parent::generateXmlFromObjects(objects: $objects);
			}
		};
	}//end exportService()

	/**
	 * The check, with stored objects answered per schema 11 query and none for the others.
	 *
	 * @param array $stored The stored objects the element schema answers.
	 *
	 * @return ArchiMateRoundTripService
	 */
	private function check(array $stored = []): ArchiMateRoundTripService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->never())->method('saveObjects');
		$objectService->method('searchObjects')->willReturnCallback(
			static fn (array $query): array => ($query['@self']['schema'] ?? null) === 11 ? $stored : []
		);
		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getAmefConfig')->willReturn(['register' => '7']);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			static fn (string $type): ?int => ['model' => 10, 'element' => 11, 'relationship' => 12, 'view' => 13, 'organization' => 14, 'property_definition' => 15][$type] ?? null
		);

		return new ArchiMateRoundTripService(
			importService: $this->importService(),
			exportService: $this->exportService(),
			comparator: new ArchiMateModelComparator(),
			settings: $settings,
			config: $this->createMock(IAppConfig::class)
		);
	}//end check()

	/**
	 * Before import, the GEMMA fixture is converted and generated with no save, and every category is reported.
	 *
	 * @return void
	 */
	public function testBeforeImportReportsEveryCategoryAndSavesNothing(): void {
		$report = $this->check()->check(filePath: self::file(self::GEMMA), mode: 'before-import');

		$this->assertSame('before-import', $report['mode']);
		$this->assertNull($report['imported']);
		$this->assertSame(['elements', 'relationships', 'views', 'viewNodes', 'propertyDefinitions', 'organizations'], array_keys($report['categories']));
		$this->assertGreaterThan(0, $report['categories']['elements']['source']);
		$this->assertCount(1, $this->generated);
		$this->assertIsBool($report['lossless']);
		$this->assertIsFloat($report['seconds']);
	}//end testBeforeImportReportsEveryCategoryAndSavesNothing()

	/**
	 * Against the imported model, only the stored objects with the file's model identifier are compared.
	 *
	 * @return void
	 */
	public function testAgainstImportedComparesOnlyThatModelsObjects(): void {
		$own = $this->importService()->convertFileToObjects(filePath: self::file(self::GOLDEN))['objects'];
		$foreign = $own[0];
		$foreign['identifier'] = 'id-from-another-model';
		$foreign['model_identifier'] = 'id-model-other';

		$report = $this->check(stored: [...$own, $foreign])->check(filePath: self::file(self::GOLDEN), mode: 'against-imported');

		$this->assertTrue($report['imported']);
		$this->assertSame('id-model-golden', $report['modelIdentifier']);
		$this->assertSame([count($own)], $this->generated);
		$this->assertSame(0, $report['categories']['elements']['extra']);
	}//end testAgainstImportedComparesOnlyThatModelsObjects()

	/**
	 * With no stored object of the file's model, the check says the model is not imported and generates nothing.
	 *
	 * @return void
	 */
	public function testAModelThatWasNeverImportedIsNamedAsSuch(): void {
		$report = $this->check(stored: [])->check(filePath: self::file(self::GOLDEN), mode: 'against-imported');

		$this->assertSame(['mode', 'modelIdentifier', 'imported', 'message', 'seconds'], array_keys($report));
		$this->assertFalse($report['imported']);
		$this->assertSame('The model is not imported', $report['message']);
		$this->assertSame('id-model-golden', $report['modelIdentifier']);
		$this->assertSame([], $this->generated);
	}//end testAModelThatWasNeverImportedIsNamedAsSuch()

	/**
	 * An unknown mode and a missing file are refused; the root identifier is read, or '' without one.
	 *
	 * @return void
	 */
	public function testBadInputIsRefusedAndTheRootIdentifierIsRead(): void {
		$this->assertSame('id-model-golden', ArchiMateRoundTripService::modelIdentifierOf(filePath: self::file(self::GOLDEN)));
		$this->assertSame('', ArchiMateRoundTripService::modelIdentifierOf(filePath: self::file(self::GEMMA)));

		try {
			$this->check()->check(filePath: self::file(self::GOLDEN), mode: 'import');
			$this->fail('An unknown mode ran');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('mode must be before-import or against-imported', $e->getMessage());
		}

		$this->expectExceptionMessage('An uploaded exchange file is required');
		$this->check()->check(filePath: '/nonexistent.xml', mode: 'before-import');
	}//end testBadInputIsRefusedAndTheRootIdentifierIsRead()
}//end class
