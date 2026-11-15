<?php

/**
 * Unit tests for CatalogExportController: the schema guard, the export grant,
 * and how the facet keys, quick filter, columns and format reach the service.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/tasks.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Stackiq\Controller\CatalogExportController;
use OCA\Stackiq\Service\CatalogExportService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The faceted catalogue export endpoint.
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/tasks.md
 */
#[CoversClass(CatalogExportController::class)]
class CatalogExportControllerTest extends TestCase {

	/**
	 * The controller over request parameters and a service mock.
	 *
	 * @param array<string, mixed>  $params  The request parameters.
	 * @param CatalogExportService  $exports The service.
	 *
	 * @return CatalogExportController
	 */
	private function controller(array $params, CatalogExportService $exports): CatalogExportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name, mixed $default = null): mixed => ($params[$name] ?? $default));

		return new CatalogExportController(appName: 'stackiq', request: $request, exports: $exports, logger: $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * Another schema answers 400 and nothing is exported.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIs400(): void {
		$exports = $this->createMock(CatalogExportService::class);
		$exports->method('assertSchema')->willThrowException(new InvalidArgumentException('Only module and catalogService can be exported here.'));
		$exports->expects(self::never())->method('mayExport');
		$exports->expects(self::never())->method('export');

		$response = $this->controller(params: [], exports: $exports)->export(schema: 'contactPerson');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(400, $response->getStatus());
		self::assertSame(['module', 'catalogService'], $response->getData()['supportedSchemas']);
	}//end testAnotherSchemaIs400()

	/**
	 * A caller outside the export grant answers 403 and nothing is exported.
	 *
	 * @return void
	 */
	public function testACallerOutsideTheGrantIs403(): void {
		$exports = $this->createMock(CatalogExportService::class);
		$exports->method('mayExport')->with('module')->willReturn(false);
		$exports->expects(self::never())->method('export');

		$response = $this->controller(params: [], exports: $exports)->export(schema: 'module');

		self::assertSame(403, $response->getStatus());
	}//end testACallerOutsideTheGrantIs403()

	/**
	 * The `_gf_` keys, the quick filter, the search, the columns and the format reach the service; the file comes back.
	 *
	 * @return void
	 */
	public function testTheListsFilterReachesTheExport(): void {
		$exports = $this->createMock(CatalogExportService::class);
		$exports->method('mayExport')->willReturn(true);
		$exports->expects(self::once())->method('export')->with(
			'module',
			['referenceComponent' => ['Zaakregistratiecomponent'], 'domain' => ['Zaken', 'Burgerzaken']],
			'zaak',
			['bbnLevel' => 'BBN2'],
			[['key' => 'name', 'label' => 'Name'], ['key' => 'bbnLevel', 'label' => 'bbnLevel']],
			'xlsx'
		)->willReturn(['body' => 'PK', 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'filename' => 'module-export.xlsx']);

		$response = $this->controller(
			params: [
				'_gf_referenceComponent' => 'Zaakregistratiecomponent',
				'domain' => ['Zaken', 'Burgerzaken', ''],
				'search' => ' zaak ',
				'quickFilter' => '{"bbnLevel":"BBN2"}',
				'columns' => [['key' => 'name', 'label' => 'Name'], 'bbnLevel', ['key' => 'x;drop', 'label' => 'bad']],
				'format' => 'xlsx',
			],
			exports: $exports
		)->export(schema: 'module');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame('PK', $response->render());
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		self::assertSame('attachment; filename="module-export.xlsx"', $headers['Content-Disposition']);
		self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $headers['Content-Type']);
	}//end testTheListsFilterReachesTheExport()

	/**
	 * A bad format is 400, a failing export 500 without its message, and CSV is the default.
	 *
	 * @return void
	 */
	public function testFailuresAndTheDefaultFormat(): void {
		$bad = $this->createMock(CatalogExportService::class);
		$bad->method('mayExport')->willReturn(true);
		$bad->method('export')->willThrowException(new InvalidArgumentException('The format is csv or xlsx.'));
		self::assertSame(400, $this->controller(params: ['format' => 'pdf'], exports: $bad)->export(schema: 'module')->getStatus());

		$broken = $this->createMock(CatalogExportService::class);
		$broken->method('mayExport')->willReturn(true);
		$broken->method('export')->willThrowException(new RuntimeException('database password in here'));
		$response = $this->controller(params: [], exports: $broken)->export(schema: 'module');
		self::assertSame(500, $response->getStatus());
		self::assertSame(['message' => 'The export could not be made.'], $response->getData());

		$csv = $this->createMock(CatalogExportService::class);
		$csv->method('mayExport')->willReturn(true);
		$csv->expects(self::once())->method('export')->with('catalogService', [], null, [], [], 'csv')
			->willReturn(['body' => "name\n", 'contentType' => 'text/csv; charset=utf-8', 'filename' => 'c.csv']);
		self::assertSame("name\n", $this->controller(params: [], exports: $csv)->export(schema: 'catalogService')->render());
	}//end testFailuresAndTheDefaultFormat()
}//end class
