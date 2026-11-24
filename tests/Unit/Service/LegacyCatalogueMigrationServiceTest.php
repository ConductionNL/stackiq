<?php

/**
 * Tests for the preview of an export from the previous Softwarecatalogus.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/CmdbTestSupport.php';

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Kernel\Contracts\OpenRegisterLookupInterface;
use OCA\Stackiq\Service\Cmdb\CmdbWorkbookReader;
use OCA\Stackiq\Service\LegacyCatalogue\LegacyCatalogueOpenRegister;
use OCA\Stackiq\Service\LegacyCatalogueMigrationService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Who may preview, and what the preview decides per row.
 */
class LegacyCatalogueMigrationServiceTest extends TestCase {
	/**
	 * Schema ids by object type.
	 */
	private const SCHEMAS = ['organization' => 11, 'module' => 12, 'moduleVersion' => 13, 'usage' => 14];

	/**
	 * The objects stackiq holds, by schema id.
	 */
	private const OBJECTS = [
		11 => [
			['id' => 'org-1', 'name' => 'Voorbeeld Software B.V.', 'registrationNumber' => '12345678'],
			['id' => 'org-2', 'name' => 'Ander Leverancier B.V.'],
			['id' => 'org-3', 'name' => 'Ander Leverancier B.V.'],
		],
		12 => [['id' => 'mod-1', 'name' => 'Zaaksysteem Voorbeeld', 'provider' => 'org-1']],
		13 => [['id' => 'ver-1', 'version' => '4.2', 'module' => 'mod-1']],
	];

	/**
	 * The service with the given caller, organisation and right.
	 *
	 * @param bool $user Whether a user is logged in.
	 * @param string|null $organisation The active organisation.
	 * @param bool $mayCreate What the permission handler answers.
	 * @param bool $configured Whether OpenRegister's object service is there.
	 * @param CmdbWorkbookReader|null $reader The reader.
	 *
	 * @return LegacyCatalogueMigrationService
	 */
	private function service(
		bool $user = true,
		?string $organisation = 'org-9',
		bool $mayCreate = true,
		bool $configured = true,
		?CmdbWorkbookReader $reader = null
	): LegacyCatalogueMigrationService {
		CmdbTestSupport::loadMigrationPack();
		$session = $this->createMock(originalClassName: IUserSession::class);
		$caller = null;
		if ($user === true) {
			$caller = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($caller);
		$lookup = $this->createMock(originalClassName: OpenRegisterLookupInterface::class);
		$lookup->method('activeOrganisationUuid')->willReturn($organisation);
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		$objectService = null;
		if ($configured === true) {
			$objectService = $this->objectService();
		}

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(static fn (string $objectType): ?int => (self::SCHEMAS[$objectType] ?? null));
		$settings->method('getVoorzieningenRegisterId')->willReturn(7);
		$settings->method('getObjectService')->willReturn($objectService);

		$openRegister = new LegacyCatalogueOpenRegister(
			container: $this->container(mayCreate: $mayCreate),
			appManager: $apps,
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		return new LegacyCatalogueMigrationService(openRegister: $openRegister, userSession: $session, lookup: $lookup, reader: $reader);
	}//end service()

	/**
	 * A container with the schema mapper, the permission handler and the translations.
	 *
	 * @param bool $mayCreate What the permission handler answers.
	 *
	 * @return ContainerInterface
	 */
	private function container(bool $mayCreate): ContainerInterface {
		$base = CmdbTestSupport::emptyContainer(test: $this);
		$mapper = new class {
			/**
			 * The schema.
			 *
			 * @param int $id The id.
			 *
			 * @return object
			 */
			public function find(int $id): object {
				return (object)['id' => $id];
			}//end find()
		};
		$permissions = new class($mayCreate) {
			/**
			 * Constructor.
			 *
			 * @param bool $answer The answer.
			 */
			public function __construct(private readonly bool $answer) {
			}//end __construct()

			/**
			 * The answer, for the usage schema and create only.
			 *
			 * @param object $schema The schema.
			 * @param string $action The action.
			 *
			 * @return bool
			 */
			public function hasPermission(object $schema, string $action): bool {
				return $this->answer && $schema->id === 14 && $action === 'create';
			}//end hasPermission()
		};
		$services = [
			LegacyCatalogueOpenRegister::SCHEMA_MAPPER => $mapper,
			LegacyCatalogueOpenRegister::PERMISSIONS => $permissions,
		];

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]) || $base->has($id));
		$container->method('get')->willReturnCallback(static fn (string $id): mixed => ($services[$id] ?? $base->get($id)));
		return $container;
	}//end container()

	/**
	 * An object service that answers a search with every object of the schema, and nothing to an RBAC-scoped read.
	 *
	 * The candidate finder must check each filter itself.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$service = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$service->method('searchObjects')->willReturnCallback(
			static function (array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($_rbac === true || $_multitenancy === true) {
					return [];
				}

				return (self::OBJECTS[(int)$query['@self']['schema']] ?? []);
			}
		);
		return $service;
	}//end objectService()

	/**
	 * A reader row of the "Applicaties" sheet.
	 *
	 * @param int $row The row number.
	 * @param array<string, string> $cells The cells.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(int $row, array $cells): array {
		return ['sheet' => 'Applicaties', 'row' => $row, 'cells' => $cells, 'uncached' => []];
	}//end row()

	/**
	 * No user, no active organisation or no right to create usages: 403 before the file is read.
	 *
	 * @return void
	 */
	public function testOnlyAMemberWhoMayCreateUsagesPreviews(): void {
		$cases = [
			'no user' => ['user' => false, 'organisation' => 'org-9', 'mayCreate' => true],
			'no active organisation' => ['user' => true, 'organisation' => null, 'mayCreate' => true],
			'no create right' => ['user' => true, 'organisation' => 'org-9', 'mayCreate' => false],
		];
		foreach ($cases as $case => $arguments) {
			$reader = $this->createMock(originalClassName: CmdbWorkbookReader::class);
			$reader->expects($this->never())->method('assertXlsx');
			$reader->expects($this->never())->method('read');
			try {
				$this->service(...$arguments, reader: $reader)->preview(path: '/does/not/exist.xlsx', fileName: 'export.xlsx');
				$this->fail(message: $case . ' must be refused');
			} catch (CmdbImportException $exception) {
				$this->assertSame(expected: CmdbImportException::FORBIDDEN, actual: $exception->getErrorCode(), message: $case);
				$this->assertSame(expected: 403, actual: $exception->getHttpStatus(), message: $case);
			}
		}

		$this->assertSame(expected: 'org-9', actual: $this->service()->assertMayImport());
	}//end testOnlyAMemberWhoMayCreateUsagesPreviews()

	/**
	 * Each row gets a match or create decision for supplier, product and version; a row without a supplier is skipped.
	 *
	 * @return void
	 */
	public function testThePreviewDecidesMatchOrCreatePerRow(): void {
		$preview = $this->service()->previewRows(
			rows: [
				self::row(
					row: 2,
					cells: [
						'ID' => 'swc-1',
						'Leverancier' => 'Voorbeeld Software B.V.',
						'KvK-nummer leverancier' => '12345678',
						'Applicatie' => 'Zaaksysteem Voorbeeld',
						'Versie' => '4.2',
						'Status' => 'In gebruik',
					]
				),
				self::row(
					row: 3,
					cells: ['ID' => 'swc-2', 'Leverancier' => 'Voorbeeld Software B.V.', 'Applicatie' => 'Zaaksysteem Voorbeeld', 'Versie' => '5.0', 'Status' => 'Gepland']
				),
				self::row(row: 4, cells: ['ID' => 'swc-3', 'Leverancier' => 'Ander Leverancier B.V.', 'Applicatie' => 'Burgerzaken', 'Versie' => '-']),
				self::row(row: 5, cells: ['ID' => 'swc-4', 'Leverancier' => '', 'Applicatie' => 'Zonder leverancier']),
			]
		);

		$decisions = array_map(
			static fn (array $row): array => [
				$row['legacyId'],
				$row['supplier']['decision'],
				$row['supplier']['id'],
				$row['product']['decision'],
				$row['product']['id'],
				$row['version']['decision'],
				$row['version']['id'],
				$row['skipped'],
			],
			$preview['rows']
		);
		$this->assertSame(
			expected: [
				['swc-1', 'matched', 'org-1', 'matched', 'mod-1', 'matched', 'ver-1', false],
				['swc-2', 'matched', 'org-1', 'matched', 'mod-1', 'create', null, false],
				['swc-3', 'create', null, 'create', null, 'none', null, false],
				['swc-4', 'none', null, 'none', null, 'none', null, true],
			],
			actual: $decisions,
			message: 'a name two organisations share matches neither; "-" is an empty version'
		);
		$this->assertSame(expected: ['status' => 'In production'], actual: $preview['rows'][0]['usage']);
		$this->assertSame(expected: ['Required source field "Leverancier" is missing or empty'], actual: $preview['rows'][3]['errors']);
		$this->assertSame(
			expected: [
				'rows' => 4,
				'skipped' => 1,
				'supplier' => ['matched' => 2, 'create' => 1],
				'product' => ['matched' => 2, 'create' => 1],
				'version' => ['matched' => 1, 'create' => 1],
			],
			actual: $preview['summary']
		);
	}//end testThePreviewDecidesMatchOrCreatePerRow()

	/**
	 * Without OpenRegister's object service the preview says the app is not configured.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThePreviewIsNotConfigured(): void {
		try {
			$this->service(configured: false)->previewRows(rows: [self::row(row: 2, cells: ['ID' => 'swc-1', 'Leverancier' => 'X', 'Applicatie' => 'Y'])]);
			$this->fail(message: 'must be refused');
		} catch (CmdbImportException $exception) {
			$this->assertSame(expected: CmdbImportException::NOT_CONFIGURED, actual: $exception->getErrorCode());
		}
	}//end testWithoutOpenRegisterThePreviewIsNotConfigured()

	/**
	 * The fixture previews to two rows; skipped where PhpSpreadsheet cannot be loaded.
	 *
	 * @return void
	 */
	public function testTheFixturePreviews(): void {
		if (CmdbTestSupport::loadPhpSpreadsheet() === false) {
			$this->markTestSkipped(message: 'PhpSpreadsheet not found: set OPENREGISTER_DIR to an OpenRegister app with vendor/.');
		}

		$preview = $this->service()->preview(path: CmdbTestSupport::fixtures() . '/vng-swc-export.xlsx', fileName: 'vng-swc-export.xlsx');

		$this->assertSame(expected: ['swc-usage-0001', 'swc-usage-0002'], actual: array_column($preview['rows'], 'legacyId'));
		$this->assertSame(expected: ['matched', 'create'], actual: array_column(array_column($preview['rows'], 'supplier'), 'decision'));
		$this->assertSame(expected: ['matched', 'none'], actual: array_column(array_column($preview['rows'], 'version'), 'decision'));
	}//end testTheFixturePreviews()
}//end class
