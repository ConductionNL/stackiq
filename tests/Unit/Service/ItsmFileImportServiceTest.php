<?php

/**
 * The file import: rows read by header, a missing record id refused by row number, one run per file.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/CmdbTestSupport.php';

use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use OCA\Stackiq\Service\ItsmFileImportService;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Asserts the reading, the refusals and the run.
 */
class ItsmFileImportServiceTest extends TestCase {

	/**
	 * OpenRegister's flow store.
	 *
	 * @var ItsmFlowGateway&MockObject
	 */
	private ItsmFlowGateway&MockObject $gateway;

	/**
	 * Files written by a test.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	/**
	 * A gateway double with only the real methods.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->gateway = $this->getMockBuilder(ItsmFlowGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['run'])
			->getMock();
	}//end setUp()

	/**
	 * Remove the files a test wrote.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
	}//end tearDown()

	/**
	 * The service, with or without a set-up file flow.
	 *
	 * @param string|null $flow The file flow's uuid, or null when the exchange is not set up.
	 *
	 * @return ItsmFileImportService
	 */
	private function service(?string $flow): ItsmFileImportService {
		$config  = $this->createMock(IAppConfig::class);
		$setting = '{}';
		if ($flow !== null) {
			$setting = (string) json_encode(['desk' => 'topdesk', 'flows' => ['file' => $flow]]);
		}

		$config->method('getValueString')->willReturn($setting);

		return new ItsmFileImportService(gateway: $this->gateway, appConfig: $config, logger: $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Write a CSV file.
	 *
	 * @param string $content The content.
	 *
	 * @return string The path.
	 */
	private function csv(string $content): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'itsm');
		file_put_contents($path, $content);
		$this->files[] = $path;
		return $path;
	}//end csv()

	/**
	 * Write a one-sheet XLSX file by hand, so its formula cells carry exactly the cached value given.
	 *
	 * @param string $sheetRows The `row` elements of the sheet.
	 *
	 * @return string The path.
	 */
	private function xlsx(string $sheetRows): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'itsm');
		$this->files[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
		$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
		$zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
		$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
		$zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>');
		$zip->close();

		return $path;
	}//end xlsx()

	/**
	 * A semicolon CSV with a byte order mark reads as rows keyed by header, empty cells left out.
	 *
	 * @return void
	 */
	public function testACsvReadsAsRowsKeyedByHeader(): void {
		$path = $this->csv("\xEF\xBB\xBFrecordId;name;supplierName;installedVersion;status\nA-1;Zaaksysteem X;Leverancier B;4.2;In production\nA-2;Burgerzaken;Leverancier C;;Planned\n;;;;\n");

		$rows = $this->service(flow: null)->readRows(path: $path, name: 'landscape.csv');

		$this->assertSame(
			[
				['recordId' => 'A-1', 'name' => 'Zaaksysteem X', 'supplierName' => 'Leverancier B', 'installedVersion' => '4.2', 'status' => 'In production'],
				['recordId' => 'A-2', 'name' => 'Burgerzaken', 'supplierName' => 'Leverancier C', 'status' => 'Planned'],
			],
			$rows
		);
	}//end testACsvReadsAsRowsKeyedByHeader()

	/**
	 * A file is imported as one run of the file flow, with every row in its payload.
	 *
	 * @return void
	 */
	public function testAFileStartsOneRunWithItsRows(): void {
		$path = $this->csv("recordId,name,supplierName\nA-1,Zaaksysteem X,Leverancier B\nA-2,Burgerzaken,Leverancier C\n");
		$this->gateway->expects($this->once())->method('run')
			->with('file-flow', ['rows' => [
				['recordId' => 'A-1', 'name' => 'Zaaksysteem X', 'supplierName' => 'Leverancier B'],
				['recordId' => 'A-2', 'name' => 'Burgerzaken', 'supplierName' => 'Leverancier C'],
			]])
			->willReturn('run-1');

		$result = $this->service(flow: 'file-flow')->import(path: $path, name: 'landscape.csv');

		$this->assertSame(['started' => true, 'run' => 'run-1', 'rows' => 2], $result);
	}//end testAFileStartsOneRunWithItsRows()

	/**
	 * A row without a record id stops the import and is named as the spreadsheet numbers it.
	 *
	 * @return void
	 */
	public function testARowWithoutARecordIdIsNamed(): void {
		$path = $this->csv("recordId,name\nA-1,Zaaksysteem X\n,Burgerzaken\n");
		$this->gateway->expects($this->never())->method('run');

		$result = $this->service(flow: 'file-flow')->import(path: $path, name: 'landscape.csv');

		$this->assertFalse($result['started']);
		$this->assertStringStartsWith('Row 3 has no recordId', $result['message']);
	}//end testARowWithoutARecordIdIsNamed()

	/**
	 * Without a set-up there is no file flow, and another file type is refused.
	 *
	 * @return void
	 */
	public function testNoSetUpAndAnotherTypeAreRefused(): void {
		$this->gateway->expects($this->never())->method('run');
		$path = $this->csv("recordId\nA-1\n");

		$this->assertStringContainsString('Set up the exchange first', $this->service(flow: null)->import(path: $path, name: 'a.csv')['message']);
		$this->assertStringContainsString('only .csv and .xlsx', $this->service(flow: 'file-flow')->import(path: $path, name: 'a.ods')['message']);
	}//end testNoSetUpAndAnotherTypeAreRefused()

	/**
	 * A file over the byte limit is refused before it is read.
	 *
	 * @return void
	 */
	public function testAFileOverTheSizeLimitIsRefused(): void {
		$this->gateway->expects($this->never())->method('run');
		$path = $this->csv("recordId\n" . str_repeat('A', ItsmFileImportService::MAX_FILE_BYTES) . "\n");

		$result = $this->service(flow: 'file-flow')->import(path: $path, name: 'big.csv');

		$this->assertFalse($result['started']);
		$this->assertStringContainsString('larger than 10 MB', $result['message']);
	}//end testAFileOverTheSizeLimitIsRefused()

	/**
	 * Reading stops one row past the row cap, and the file is refused.
	 *
	 * @return void
	 */
	public function testReadingStopsOneRowPastTheCap(): void {
		$this->gateway->expects($this->never())->method('run');
		$lines = ['recordId'];
		for ($i = 1; $i <= (ItsmFileImportService::MAX_ROWS + 50); $i++) {
			$lines[] = 'A-' . $i;
		}

		$path = $this->csv(implode("\n", $lines) . "\n");
		$service = $this->service(flow: 'file-flow');

		$this->assertCount(ItsmFileImportService::MAX_ROWS + 1, $service->readRows(path: $path, name: 'many.csv'));
		$this->assertStringContainsString('more than ' . ItsmFileImportService::MAX_ROWS . ' rows', $service->import(path: $path, name: 'many.csv')['message']);
	}//end testReadingStopsOneRowPastTheCap()

	/**
	 * A flow the engine refuses to run is the refusal envelope, not an error.
	 *
	 * @return void
	 */
	public function testAFlowThatCannotRunIsRefused(): void {
		$path = $this->csv("recordId\nA-1\n");
		$this->gateway->method('run')->willThrowException(new RuntimeException('flow is not runnable'));

		$result = $this->service(flow: 'file-flow')->import(path: $path, name: 'a.csv');

		$this->assertFalse($result['started']);
		$this->assertStringContainsString('flow is not runnable', $result['message']);
	}//end testAFlowThatCannotRunIsRefused()

	/**
	 * An XLSX file gives the value a formula cached, not a recalculated one, and a CSV named .xlsx is not read as CSV.
	 *
	 * @return void
	 */
	public function testAnXlsxGivesCachedFormulaValuesAndOnlyXlsxIsRead(): void {
		if (CmdbTestSupport::loadPhpSpreadsheet() === false) {
			$this->markTestSkipped('PhpSpreadsheet comes from an OpenRegister vendor directory, which is not available.');
		}

		$path = $this->xlsx(
			'<row r="1"><c r="A1" t="inlineStr"><is><t>recordId</t></is></c><c r="B1" t="inlineStr"><is><t>name</t></is></c></row>'
			. '<row r="2"><c r="A2" t="inlineStr"><is><t>A-1</t></is></c><c r="B2" t="str"><f>CONCATENATE("Zaak","systeem")</f><v>Cached name</v></c></row>'
		);

		$this->assertSame([['recordId' => 'A-1', 'name' => 'Cached name']], $this->service(flow: null)->readRows(path: $path, name: 'landscape.xlsx'));

		$csv = $this->csv("recordId\nA-1\n");
		$this->assertStringContainsString('could not be read', $this->service(flow: 'file-flow')->import(path: $csv, name: 'landscape.xlsx')['message']);
	}//end testAnXlsxGivesCachedFormulaValuesAndOnlyXlsxIsRead()
}//end class
