<?php

/**
 * Unit tests for CatalogExportService: the rows the facets, the quick filter
 * and the search leave, the page's columns, the cut-off row and the export grant.
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
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/tasks.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Kernel\Contracts\FacetServiceInterface;
use OCA\Stackiq\Service\CatalogExportService;
use OCA\Stackiq\Service\Export\CatalogExportGate;
use OCA\Stackiq\Service\Export\CatalogExportRequest;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * The faceted catalogue export.
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/tasks.md
 */
#[CoversClass(CatalogExportService::class)]
#[UsesClass(\OCA\Stackiq\Service\Export\SimpleXlsxWriter::class)]
#[UsesClass(CatalogExportRequest::class)]
#[UsesClass(CatalogExportGate::class)]
#[UsesClass(\OCA\Stackiq\Service\PortfolioReportService::class)]
class CatalogExportServiceTest extends TestCase {

	/**
	 * The queries OpenRegister was asked.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The service over the given facet answer, OpenRegister rows and schema grant.
	 *
	 * @param array<string, mixed>       $meta          The facet answer's `_meta`.
	 * @param array<int, array>          $rows          What OpenRegister answers.
	 * @param array<string, mixed>|null  $authorization The schema's authorization, null when it cannot be read.
	 * @param array<int, string>         $groups        The caller's groups.
	 * @param array<string, mixed>|null  $facetsCall    Filled with the facet call's arguments.
	 *
	 * @return CatalogExportService
	 */
	private function service(array $meta, array $rows = [], ?array $authorization = [], array $groups = [], ?array &$facetsCall = null): CatalogExportService {
		$facets = $this->createMock(FacetServiceInterface::class);
		$facets->method('getFacets')->willReturnCallback(
			static function (string $schema, array $filters = [], ?string $search = null) use ($meta, &$facetsCall): array {
				$facetsCall = ['schema' => $schema, 'filters' => $filters, 'search' => $search];
				return ['_meta' => $meta];
			}
		);

		$catalogue = $this->createMock(CatalogueLocator::class);
		$catalogue->method('getRegisterIdForObjectType')->willReturn(7);
		$catalogue->method('getSchemaIdForObjectType')->willReturn(21);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($rows): array {
				$this->queries[] = $query;
				return ['results' => $rows];
			}
		);

		$schemas = new class($authorization) {
			/**
			 * @param array<string, mixed>|null $authorization The grant, null to fail.
			 */
			public function __construct(private readonly ?array $authorization) {
			}

			/**
			 * @param int  $id            The schema id.
			 * @param bool $_rbac         Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return object
			 */
			public function find(int $id, bool $_rbac = true, bool $_multitenancy = true): object {
				if ($this->authorization === null) {
					throw new \RuntimeException('no schema ' . $id);
				}

				$authorization = $this->authorization;
				return new class($authorization) {
					/**
					 * @param array<string, mixed> $authorization The grant.
					 */
					public function __construct(private readonly array $authorization) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function getAuthorization(): array {
						return $this->authorization;
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === ObjectService::class ? $objects : $schemas)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('linda');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(in_array('admin', $groups, true));
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => in_array($group, $groups, true));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new CatalogExportService(
			facets: $facets,
			catalogue: $catalogue,
			container: $container,
			l10n: $l10n,
			gate: new CatalogExportGate(
				catalogue: $catalogue,
				container: $container,
				userSession: $session,
				groupManager: $groupManager,
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}//end service()

	/**
	 * Only the Applications and Services lists are exported here.
	 *
	 * @return void
	 */
	public function testOnlyTheTwoFacetedSchemasAreServed(): void {
		$service = $this->service(meta: []);
		$service->assertSchema(schema: 'module');
		$service->assertSchema(schema: 'catalogService');

		$this->expectException(InvalidArgumentException::class);
		$service->assertSchema(schema: 'contactPerson');
	}//end testOnlyTheTwoFacetedSchemasAreServed()

	/**
	 * The rows are the matched ids, narrowed by the quick filter, in the page's columns.
	 *
	 * @return void
	 */
	public function testTheFileHoldsTheMatchedRowsInThePagesColumns(): void {
		$facetsCall = null;
		$service = $this->service(
			meta: ['matchedObjectIds' => ['m1', 'm2'], 'truncated' => false],
			rows: [
				['id' => 'm1', 'name' => 'Zaaksysteem', 'bbnLevel' => 'BBN2', 'supplier' => ['name' => 'Leverancier A'], 'tags' => ['zaak', 'dms']],
				['id' => 'm2', 'name' => '=HYPERLINK("x")', 'bbnLevel' => 'BBN2', 'supplier' => null, 'tags' => []],
			],
			facetsCall: $facetsCall
		);

		$file = $service->export(
			schema: 'module',
			request: new CatalogExportRequest(
				facets: ['referenceComponent' => ['Zaakregistratiecomponent']],
				search: 'zaak',
				quickFilter: ['bbnLevel' => 'BBN2', '_limit' => 5000, 'bad key' => 'x'],
				columns: [['key' => 'name', 'label' => 'Name'], ['key' => 'supplier', 'label' => 'Supplier'], ['key' => 'tags', 'label' => 'Tags'], ['key' => 'bbnLevel', 'label' => 'BBN']],
				format: 'csv'
			)
		);

		self::assertSame(['schema' => 'module', 'filters' => ['referenceComponent' => ['Zaakregistratiecomponent']], 'search' => 'zaak'], $facetsCall);
		self::assertCount(1, $this->queries);
		self::assertSame(
			['bbnLevel' => 'BBN2', '@self' => ['register' => 7, 'schema' => 21], 'id' => ['m1', 'm2'], '_limit' => 2, '_page' => 1],
			$this->queries[0]
		);
		self::assertSame('text/csv; charset=utf-8', $file['contentType']);
		self::assertStringStartsWith('module-export-', $file['filename']);
		self::assertSame(
			"Name,Supplier,Tags,BBN\nZaaksysteem,\"Leverancier A\",\"zaak, dms\",BBN2\n\"'=HYPERLINK(\"\"x\"\")\",,,BBN2\n",
			$file['body']
		);
	}//end testTheFileHoldsTheMatchedRowsInThePagesColumns()

	/**
	 * A set cut at the facet ceiling ends with a row that says so; nothing matched reads nothing.
	 *
	 * @return void
	 */
	public function testACutSetSaysSoInItsLastRow(): void {
		$cut = $this->service(meta: ['matchedObjectIds' => ['m1'], 'truncated' => true], rows: [['name' => 'Zaaksysteem']]);
		$lines = explode("\n", trim($cut->export(schema: 'catalogService', request: new CatalogExportRequest())['body']));

		self::assertSame(['name', 'Zaaksysteem', '"' . sprintf(CatalogExportService::CUT_ROW, 1) . '"'], $lines);

		$this->queries = [];
		$empty = $this->service(meta: ['matchedObjectIds' => [], 'truncated' => false]);
		self::assertSame("name\n", $empty->export(schema: 'module', request: new CatalogExportRequest())['body']);
		self::assertSame([], $this->queries, 'no ids, no read');
	}//end testACutSetSaysSoInItsLastRow()

	/**
	 * Excel is an XLSX workbook whose sheet holds the same cells.
	 *
	 * @return void
	 */
	public function testExcelIsAWorkbookWithTheSameCells(): void {
		$service = $this->service(meta: ['matchedObjectIds' => ['m1']], rows: [['name' => 'Zaak & Co <1>']]);

		$file = $service->export(schema: 'module', request: new CatalogExportRequest(columns: [['key' => 'name', 'label' => 'Name']], format: 'xlsx'));

		self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $file['contentType']);
		self::assertStringEndsWith('.xlsx', $file['filename']);
		$path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
		file_put_contents($path, $file['body']);
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path));
		$sheet = (string)$zip->getFromName('xl/worksheets/sheet1.xml');
		$zip->close();
		unlink($path);
		self::assertStringContainsString('<c r="A1" t="inlineStr"><is><t xml:space="preserve">Name</t></is></c>', $sheet);
		self::assertStringContainsString('<c r="A2" t="inlineStr"><is><t xml:space="preserve">Zaak &amp; Co &lt;1&gt;</t></is></c>', $sheet);

		$this->expectException(InvalidArgumentException::class);
		$service->export(schema: 'module', request: new CatalogExportRequest(format: 'pdf'));
	}//end testExcelIsAWorkbookWithTheSameCells()

	/**
	 * A schema that names `export` admits its groups and administrators only; without it the read grant applies.
	 *
	 * @return void
	 */
	public function testANarrowedExportGrantIsHonoured(): void {
		$grant = ['read' => ['public'], 'export' => ['software-catalog-admins']];

		self::assertFalse($this->service(meta: [], authorization: $grant, groups: ['ambtenaar'])->mayExport(schema: 'module'));
		self::assertTrue($this->service(meta: [], authorization: $grant, groups: ['software-catalog-admins'])->mayExport(schema: 'module'));
		self::assertTrue($this->service(meta: [], authorization: $grant, groups: ['admin'])->mayExport(schema: 'module'));
		self::assertTrue($this->service(meta: [], authorization: ['read' => ['public']], groups: [])->mayExport(schema: 'module'));
		self::assertFalse($this->service(meta: [], authorization: null)->mayExport(schema: 'module'), 'an unreadable schema refuses');
	}//end testANarrowedExportGrantIsHonoured()

	/**
	 * Cells: lists joined, related objects by name or title, booleans in words.
	 *
	 * @return void
	 */
	public function testCellsReadAsText(): void {
		self::assertSame('', CatalogExportService::cell(value: null));
		self::assertSame('true', CatalogExportService::cell(value: true));
		self::assertSame('a, b', CatalogExportService::cell(value: ['a', 'b']));
		self::assertSame('Gemeente', CatalogExportService::cell(value: ['title' => 'Gemeente', 'id' => 'o1']));
		self::assertSame(['bbn' => ['BBN1', 'BBN2']], CatalogExportService::quickFilterQuery(quickFilter: ['bbn' => ['BBN1', 'BBN2'], 'x' => [['nested']], '@self' => 'x']));
	}//end testCellsReadAsText()
}//end class
