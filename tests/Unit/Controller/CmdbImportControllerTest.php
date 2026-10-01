<?php

/**
 * Tests for the CMDB import controller: auth posture, validation order and
 * the translation of every service exception into its contract status.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\CmdbImportController;
use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\CmdbExportImportService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The controller in front of CmdbExportImportService.
 */
class CmdbImportControllerTest extends TestCase {
	/**
	 * Nextcloud's annotation regex (ControllerMethodReflector), as AdminAuthPostureTest uses it.
	 */
	private const ANNOTATION = '/^\h+\*\h+@(?P<annotation>[A-Z]\w+)((?P<parameter>.*))?$/m';

	/**
	 * Temporary files of the test.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	/**
	 * Remove temporary files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}
	}//end tearDown()

	/**
	 * A temporary upload.
	 *
	 * @param string $content The file content.
	 *
	 * @return string The path.
	 */
	private function upload(string $content = "PK\x03\x04"): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'cmdb');
		file_put_contents($path, $content);
		$this->files[] = $path;
		return $path;
	}//end upload()

	/**
	 * The controller with a request carrying the given file and params.
	 *
	 * @param array<string, mixed>|null $file The uploaded file entry, or null.
	 * @param array<string, mixed> $params Form fields.
	 * @param CmdbExportImportService|MockObject|null $service The service.
	 *
	 * @return CmdbImportController
	 */
	private function controller(?array $file, array $params, CmdbExportImportService|MockObject|null $service = null): CmdbImportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturnCallback(fn (string $key) => $key === 'cmdbFile' ? $file : null);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));

		if ($service === null) {
			$service = $this->createMock(CmdbExportImportService::class);
			$service->method('maxFileBytes')->willReturn(10485760);
			$service->method('supportsMissingRecords')->willReturnCallback(fn (string $mode): bool => $mode === 'keep');
		}

		return new CmdbImportController(request: $request, importService: $service, l10n: $l10n, logger: $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * A service double with the defaults the controller reads before import().
	 *
	 * @return CmdbExportImportService|MockObject
	 */
	private function service(): CmdbExportImportService|MockObject {
		$service = $this->createMock(CmdbExportImportService::class);
		$service->method('maxFileBytes')->willReturn(10485760);
		$service->method('supportsMissingRecords')->willReturnCallback(fn (string $mode): bool => $mode === 'keep');
		return $service;
	}//end service()

	/**
	 * A file entry as PHP puts it in $_FILES.
	 *
	 * @param string $path The temporary file.
	 * @param string $name The original name.
	 * @param int $size The size.
	 *
	 * @return array<string, mixed>
	 */
	private function file(string $path, string $name = 'export.xlsx', int $size = 4): array {
		return ['tmp_name' => $path, 'name' => $name, 'size' => $size, 'error' => UPLOAD_ERR_OK];
	}//end file()

	/**
	 * Neither method declares NoAdminRequired or NoCSRFRequired, as attribute or annotation.
	 *
	 * @return void
	 */
	public function testBothRoutesAreAdminOnlyWithCsrf(): void {
		foreach (['import', 'cancel'] as $method) {
			$reflection = new ReflectionMethod(CmdbImportController::class, $method);
			$this->assertSame([], $reflection->getAttributes(), $method);

			preg_match_all(self::ANNOTATION, (string)$reflection->getDocComment(), $matches);
			foreach (['NoAdminRequired', 'NoCSRFRequired', 'PublicPage'] as $annotation) {
				$this->assertNotContains($annotation, $matches['annotation'], $method);
			}
		}

		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$byName = array_column($routes['routes'], null, 'name');
		$this->assertSame(['name' => 'cmdbImport#import', 'url' => '/api/cmdb-import', 'verb' => 'POST'], $byName['cmdbImport#import']);
		$this->assertSame(['name' => 'cmdbImport#cancel', 'url' => '/api/cmdb-import/{operationId}/cancel', 'verb' => 'POST'], $byName['cmdbImport#cancel']);
	}//end testBothRoutesAreAdminOnlyWithCsrf()

	/**
	 * No file is 400 NO_FILE_UPLOADED; a failed upload too.
	 *
	 * @return void
	 */
	public function testNoFileIsRefused(): void {
		$response = $this->controller(file: null, params: ['municipalityName' => 'Gemeente Voorbeeldstad'])->import();
		$this->assertSame(400, $response->getStatus());
		$this->assertSame('NO_FILE_UPLOADED', $response->getData()['error']);
		$this->assertFalse($response->getData()['success']);
		$this->assertNotSame('', $response->getData()['message']);

		$partial = ['tmp_name' => '', 'name' => 'export.xlsx', 'size' => 0, 'error' => UPLOAD_ERR_PARTIAL];
		$this->assertSame('NO_FILE_UPLOADED', $this->controller(file: $partial, params: [])->import()->getData()['error']);
	}//end testNoFileIsRefused()

	/**
	 * A file of 10 MB plus one byte is 413 FILE_TOO_LARGE and the reader is never invoked.
	 *
	 * @return void
	 */
	public function testAnOversizedFileIsRefusedBeforeReading(): void {
		$service = $this->service();
		$service->expects($this->never())->method('assertXlsx');
		$service->expects($this->never())->method('import');

		$response = $this->controller(file: $this->file(path: $this->upload(), size: 10485761), params: ['municipalityName' => 'X'], service: $service)->import();
		$this->assertSame(413, $response->getStatus());
		$this->assertSame('FILE_TOO_LARGE', $response->getData()['error']);

		$tooBig = ['tmp_name' => '', 'name' => 'export.xlsx', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE];
		$this->assertSame(413, $this->controller(file: $tooBig, params: [], service: $service)->import()->getStatus());
	}//end testAnOversizedFileIsRefusedBeforeReading()

	/**
	 * A file that is not xlsx is 400 NOT_XLSX, checked before missingRecords and the municipality.
	 *
	 * @return void
	 */
	public function testANonXlsxFileIsRefused(): void {
		$service = $this->service();
		$service->method('assertXlsx')->willThrowException(new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'no'));
		$service->expects($this->never())->method('import');

		$response = $this->controller(file: $this->file(path: $this->upload(content: 'Naam;Middel-ID'), name: 'applications.csv'), params: ['missingRecords' => 'remove'], service: $service)->import();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('NOT_XLSX', $response->getData()['error']);
	}//end testANonXlsxFileIsRefused()

	/**
	 * A reserved missingRecords value is 422 MISSING_RECORDS_UNSUPPORTED, before the municipality check.
	 *
	 * @return void
	 */
	public function testAReservedMissingRecordsValueIsRefused(): void {
		$service = $this->service();
		$service->expects($this->never())->method('import');

		foreach (['remove', 'mark'] as $mode) {
			$response = $this->controller(file: $this->file(path: $this->upload()), params: ['missingRecords' => $mode], service: $service)->import();
			$this->assertSame(422, $response->getStatus(), $mode);
			$this->assertSame('MISSING_RECORDS_UNSUPPORTED', $response->getData()['error'], $mode);
		}
	}//end testAReservedMissingRecordsValueIsRefused()

	/**
	 * Without a municipality the answer is 422 MUNICIPALITY_REQUIRED and nothing is imported.
	 *
	 * @return void
	 */
	public function testAMunicipalityIsRequired(): void {
		$service = $this->service();
		$service->expects($this->never())->method('import');

		$response = $this->controller(file: $this->file(path: $this->upload()), params: ['municipalityName' => '  '], service: $service)->import();
		$this->assertSame(422, $response->getStatus());
		$this->assertSame('MUNICIPALITY_REQUIRED', $response->getData()['error']);
	}//end testAMunicipalityIsRequired()

	/**
	 * Every service exception keeps its contract code, status and details.
	 *
	 * @return array<string, array{string, int, array<string, mixed>}>
	 */
	public static function serviceErrors(): array {
		return [
			'mapping' => [CmdbImportException::MAPPING_UNAVAILABLE, 503, []],
			'reader' => [CmdbImportException::READER_UNAVAILABLE, 503, []],
			'config' => [CmdbImportException::NOT_CONFIGURED, 503, []],
			'no sheet' => [CmdbImportException::NO_SOURCE_SHEET, 422, ['expected' => ['Invoer AIA data', 'Invoer APP data']]],
			'column' => [CmdbImportException::MISSING_COLUMN, 422, ['sheet' => 'Invoer APP data', 'column' => 'Middel-ID']],
			'rows' => [CmdbImportException::TOO_MANY_ROWS, 422, ['sheet' => 'Invoer APP data', 'limit' => 10000]],
			'municipality' => [CmdbImportException::MUNICIPALITY_INVALID, 422, []],
			'corrupt' => [CmdbImportException::NOT_XLSX, 400, []],
		];
	}//end serviceErrors()

	/**
	 * A service exception becomes its contract response.
	 *
	 * @param string $code The error code.
	 * @param int $status The HTTP status.
	 * @param array<string, mixed> $details The details.
	 *
	 * @return void
	 */
	#[DataProvider('serviceErrors')]
	public function testServiceErrorsAreTranslated(string $code, int $status, array $details): void {
		$service = $this->service();
		$service->method('import')->willThrowException(new CmdbImportException(errorCode: $code, message: 'internal', details: $details));

		$response = $this->controller(file: $this->file(path: $this->upload()), params: ['municipalityUuid' => '00000000-0000-0000-0000-000000000001'], service: $service)->import();

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($code, $response->getData()['error']);
		$this->assertEquals((object)$details, $response->getData()['details']);
		$this->assertStringNotContainsString('internal', $response->getData()['message']);
		if ($code === CmdbImportException::MISSING_COLUMN) {
			$this->assertStringContainsString('Invoer APP data', $response->getData()['message']);
			$this->assertStringContainsString('Middel-ID', $response->getData()['message']);
		}
	}//end testServiceErrorsAreTranslated()

	/**
	 * An unexpected error is 500 IMPORT_FAILED with a generic message.
	 *
	 * @return void
	 */
	public function testAnUnexpectedErrorIsAGeneric500(): void {
		$service = $this->service();
		$service->method('import')->willThrowException(new RuntimeException('SQLSTATE secret detail'));

		$response = $this->controller(file: $this->file(path: $this->upload()), params: ['municipalityName' => 'Gemeente Voorbeeldstad'], service: $service)->import();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('IMPORT_FAILED', $response->getData()['error']);
		$this->assertStringNotContainsString('SQLSTATE', $response->getData()['message']);
	}//end testAnUnexpectedErrorIsAGeneric500()

	/**
	 * A valid upload passes the options through and answers 200 with the report.
	 *
	 * @return void
	 */
	public function testAValidUploadReturnsTheReport(): void {
		$path = $this->upload();
		$service = $this->service();
		$service->expects($this->once())->method('assertXlsx')->with($path, 'export.xlsx');
		$service->expects($this->once())->method('import')
			->with(
				$path,
				[
					'municipalityUuid' => '',
					'municipalityName' => 'Gemeente Voorbeeldstad',
					'updateExisting' => false,
					'operationId' => 'cmdb-00000000-0000-0000-0000-000000000000',
				]
			)
			->willReturn(['success' => true, 'summary' => ['created' => 2]]);

		$response = $this->controller(
			file: $this->file(path: $path),
			params: ['municipalityName' => 'Gemeente Voorbeeldstad', 'updateExisting' => 'false', 'missingRecords' => 'keep', 'operationId' => 'cmdb-00000000-0000-0000-0000-000000000000'],
			service: $service
		)->import();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'summary' => ['created' => 2]], $response->getData());
	}//end testAValidUploadReturnsTheReport()

	/**
	 * Cancel answers 200 for a running import and 404 OPERATION_NOT_FOUND otherwise.
	 *
	 * @return void
	 */
	public function testCancel(): void {
		$service = $this->service();
		$service->method('requestCancel')->willReturnCallback(fn (string $id): bool => $id === 'cmdb-running-1');
		$controller = $this->controller(file: null, params: [], service: $service);

		$ok = $controller->cancel(operationId: 'cmdb-running-1');
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame(['success' => true, 'cancelRequested' => true], $ok->getData());

		$missing = $controller->cancel(operationId: 'cmdb-unknown-1');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame('OPERATION_NOT_FOUND', $missing->getData()['error']);
	}//end testCancel()
}//end class
