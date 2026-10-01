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

use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use OCA\Stackiq\Service\ItsmFileImportService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

		return new ItsmFileImportService(gateway: $this->gateway, appConfig: $config);
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
}//end class
