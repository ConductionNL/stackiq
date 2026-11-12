<?php

/**
 * GEMMA standard links written by the ArchiMate import.
 *
 * Imports a fixture with reference components, standards and standard versions
 * through both import entry points, and checks the links the import writes onto
 * the saved objects and the post-save fix of standard version references.
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
use Psr\Log\NullLogger;

/**
 * The import links reference components and standard versions to standards.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateImportService
 * @uses   \OCA\Stackiq\Service\ArchiMate\ArchiMateImportScope
 * @uses   \OCA\Stackiq\Service\ArchiMate\GemmaObjectReader
 * @uses   \OCA\Stackiq\Service\ArchiMate\GemmaStandardLinker
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class ArchiMateGemmaStandardLinksTest extends TestCase {

	private const S1 = '0b1e7c2a-0000-4000-8000-00000000a001';
	private const V1 = '0b1e7c2a-0000-4000-8000-00000000b001';

	private const SCHEMA_IDS = ['model' => 10, 'element' => 11, 'relationship' => 12, 'view' => 13, 'organization' => 14, 'property_definition' => 15];

	/**
	 * Saved objects by identifier.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Database statements, in order.
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $statements = [];

	/**
	 * Both import entry points.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function entries(): array {
		return ['optimized' => ['optimized'], 'classic' => ['classic']];
	}//end entries()

	/**
	 * A reference component gets its mandatory, recommended and combined standards.
	 *
	 * Standards with a uuid Object ID are referenced by that uuid, a standard
	 * without one by its slug. Both directions of a relationship count once.
	 *
	 * @param string $entry The entry point.
	 *
	 * @return void
	 *
	 * @dataProvider entries
	 */
	public function testReferenceComponentIsLinkedToItsStandards(string $entry): void {
		$this->import(entry: $entry);
		$component = $this->saved['id-rc1'];

		$this->assertSame(expected: [self::S1], actual: $component['mandatoryStandards'] ?? null);
		$this->assertSame(expected: ['s2'], actual: $component['recommendedStandards'] ?? null);
		$this->assertSame(expected: ['s2', self::S1], actual: $component['standards'] ?? null);
		$this->assertSame(expected: ['v2', self::V1], actual: $component['linkedStandardVersions'] ?? null);
	}//end testReferenceComponentIsLinkedToItsStandards()

	/**
	 * A relationship without a connection role links nothing.
	 *
	 * @param string $entry The entry point.
	 *
	 * @return void
	 *
	 * @dataProvider entries
	 */
	public function testRelationshipWithoutRoleLinksNothing(string $entry): void {
		$this->import(entry: $entry);
		$component = $this->saved["id-rc2"];

		$this->assertArrayNotHasKey(key: 'standards', array: $component);
		$this->assertArrayNotHasKey(key: 'mandatoryStandards', array: $component);
		$this->assertArrayNotHasKey(key: 'linkedStandardVersions', array: $component);
	}//end testRelationshipWithoutRoleLinksNothing()

	/**
	 * A standard version gets its standard through a Specialization, in either direction.
	 *
	 * @param string $entry The entry point.
	 *
	 * @return void
	 *
	 * @dataProvider entries
	 */
	public function testStandardVersionIsLinkedToItsStandard(string $entry): void {
		$this->import(entry: $entry);

		$this->assertSame(expected: self::S1, actual: $this->saved['id-v1']['standard'] ?? null);
		$this->assertSame(expected: 's2', actual: $this->saved['id-v2']['standard'] ?? null);
	}//end testStandardVersionIsLinkedToItsStandard()

	/**
	 * After saving, slug references to a standard are rewritten to its database uuid.
	 *
	 * A Standaard row without an identifier or a uuid is skipped.
	 *
	 * @return void
	 */
	public function testSavedStandardVersionSlugsAreRewrittenToUuids(): void {
		$this->import(entry: 'optimized');

		$this->assertSame(
			expected: [
				[
					"UPDATE oc_openregister_table_7_11 SET standard = ? WHERE standard = ? AND gemma_type = 'Standaardversie'",
					['db-uuid-s2', 's2'],
				],
			],
			actual: $this->statements
		);
	}//end testSavedStandardVersionSlugsAreRewrittenToUuids()

	/**
	 * Run one import of the fixture.
	 *
	 * @param string $entry The entry point.
	 *
	 * @return void
	 */
	private function import(string $entry): void {
		$file    = __DIR__ . '/../../fixtures/archimate/gemma-links.xml';
		$options = ['file_path' => $file, 'fileName' => basename($file)];
		$service = $this->service();
		if ($entry === 'optimized') {
			$service->importArchiMateFileFromPathOptimized($options);
			return;
		}

		$service->importArchiMateFileFromPath($options);
	}//end import()

	/**
	 * The service with doubles that keep the saved objects.
	 *
	 * @return ArchiMateImportService
	 */
	private function service(): ArchiMateImportService {
		$amef   = [
			'register'                   => '7',
			'model_schema'               => '10',
			'element_schema'             => '11',
			'relation_schema'            => '12',
			'view_schema'                => '13',
			'organization_schema'        => '14',
			'property_definition_schema' => '15',
		];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $key === 'amef_config' ? json_encode($amef) : $default
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
			fn (string $type): ?int => self::SCHEMA_IDS[$type] ?? null
		);

		$organisation = new Organisation();
		$organisation->setUuid('org-default');
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('ensureDefaultOrganisation')->willReturn($organisation);

		return new ArchiMateImportService(
			config: $config,
			userSession: $userSession,
			appManager: $appManager,
			container: $container,
			logger: new NullLogger(),
			settingsService: $settings,
			organisationService: $organisations,
			dbConnection: $this->database(),
			progressTracker: $this->createMock(ProgressTracker::class)
		);
	}//end service()

	/**
	 * An object service that keeps every saved object by identifier.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObjects')->willReturnCallback(
			function (array $objects): array {
				foreach ($objects as $object) {
					$this->saved[(string) ($object['identifier'] ?? '')] = $object;
				}

				return ['saved' => [], 'updated' => [], 'unchanged' => [], 'invalid' => []];
			}
		);
		$objectService->method('searchObjects')->willReturn([]);

		return $objectService;
	}//end objectService()

	/**
	 * A database whose Standaard query returns the slug-only standard with its uuid, and two rows missing one.
	 *
	 * @return IDBConnection
	 */
	private function database(): IDBConnection {
		$rows   = [['identifier' => 'id-s2', '_uuid' => 'db-uuid-s2'], ['identifier' => 'id-s9', '_uuid' => ''], ['identifier' => '', '_uuid' => 'db-uuid-none']];
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(
			function () use (&$rows) {
				return array_shift($rows) ?? false;
			}
		);
		$database = $this->createMock(IDBConnection::class);
		$database->method('executeQuery')->willReturn($result);
		$database->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				$this->statements[] = [$sql, $params];
				return 1;
			}
		);

		return $database;
	}//end database()
}//end class
