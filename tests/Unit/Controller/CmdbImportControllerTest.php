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
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
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

		parent::tearDown();
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
	 * @param LoggerInterface|MockObject|null $logger The logger.
	 * @param array<string, string> $headers Request headers.
	 * @param array<string, int|null>|null $ini PHP size settings in bytes, standing in for php.ini; null reads php.ini.
	 *
	 * @return CmdbImportController
	 */
	private function controller(
		?array $file,
		array $params,
		CmdbExportImportService|MockObject|null $service = null,
		LoggerInterface|MockObject|null $logger = null,
		array $headers = [],
		?array $ini = null,
	): CmdbImportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturnCallback(fn (string $key) => $key === 'cmdbFile' ? $file : null);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => ($headers[$name] ?? ''));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));

		if ($service === null) {
			$service = $this->createMock(CmdbExportImportService::class);
			$service->method('maxFileBytes')->willReturn(10485760);
			$service->method('supportsMissingRecords')->willReturnCallback(fn (string $mode): bool => $mode === 'keep');
		}

		$logger = ($logger ?? $this->createMock(LoggerInterface::class));
		if ($ini === null) {
			return new CmdbImportController(request: $request, importService: $service, l10n: $l10n, logger: $logger);
		}

		return new class($request, $service, $l10n, $logger, $ini) extends CmdbImportController {
			/**
			 * Constructor.
			 *
			 * @param IRequest $request The request.
			 * @param CmdbExportImportService $service The service.
			 * @param IL10N $l10n Translations.
			 * @param LoggerInterface $logger Logger.
			 * @param array<string, int|null> $ini The size settings.
			 */
			public function __construct(IRequest $request, CmdbExportImportService $service, IL10N $l10n, LoggerInterface $logger, private array $ini) {
				parent::__construct(request: $request, importService: $service, l10n: $l10n, logger: $logger);
			}

			/**
			 * The stand-in setting.
			 *
			 * @param string $name The ini setting.
			 *
			 * @return int|null
			 */
			protected function iniBytes(string $name): ?int {
				return ($this->ini[$name] ?? null);
			}
		};
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
	 * Both methods are for Nextcloud admins only, and declare that with a reason.
	 *
	 * Nextcloud's default (no auth attribute) is the admin gate, so the
	 * declaration is the `@auth admin-only <reason>` tag: the import writes with
	 * RBAC and multitenancy off, across tenants.
	 *
	 * @return void
	 */
	public function testBothRoutesAreForNextcloudAdminsOnly(): void {
		foreach (['import', 'cancel'] as $method) {
			$reflection = new ReflectionMethod(CmdbImportController::class, $method);

			$this->assertSame([], $reflection->getAttributes(), $method . ' carries no attribute');
			$this->assertMatchesRegularExpression(
				'/^\h+\*\h+@auth admin-only \S.{19,}$/m',
				(string)$reflection->getDocComment(),
				$method . ' declares @auth admin-only with a reason'
			);
		}
	}//end testBothRoutesAreForNextcloudAdminsOnly()

	/**
	 * Neither method admits delegated admins, every user, anonymous users or requests without CSRF.
	 *
	 * Checked on the class and both methods, as attribute and as the annotation
	 * Nextcloud's regex reads, so a comment line that starts with one of these
	 * tokens fails too. `AuthorizedAdminSetting` is in the list because it would
	 * admit the groups an admin delegated stackiq's settings to.
	 *
	 * @return void
	 */
	public function testNeitherRouteDeclaresAnExemption(): void {
		$exemptions = [
			'AuthorizedAdminSetting' => AuthorizedAdminSetting::class,
			'NoAdminRequired' => NoAdminRequired::class,
			'NoCSRFRequired' => NoCSRFRequired::class,
			'PublicPage' => PublicPage::class,
		];

		foreach ([CmdbImportController::class, 'import', 'cancel'] as $target) {
			$reflection = $target === CmdbImportController::class ? new ReflectionClass($target) : new ReflectionMethod(CmdbImportController::class, $target);

			preg_match_all(self::ANNOTATION, (string)$reflection->getDocComment(), $matches);
			foreach ($exemptions as $annotation => $attribute) {
				$this->assertSame([], $reflection->getAttributes($attribute), $target . ' ' . $annotation);
				$this->assertNotContains($annotation, $matches['annotation'], $target . ' ' . $annotation);
			}
		}
	}//end testNeitherRouteDeclaresAnExemption()

	/**
	 * The two routes keep their paths and verbs.
	 *
	 * @return void
	 */
	public function testTheRoutesAreRegistered(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$byName = array_column($routes['routes'], null, 'name');
		$this->assertSame(['name' => 'cmdbImport#import', 'url' => '/api/cmdb-import', 'verb' => 'POST'], $byName['cmdbImport#import']);
		$this->assertSame(['name' => 'cmdbImport#cancel', 'url' => '/api/cmdb-import/{operationId}/cancel', 'verb' => 'POST'], $byName['cmdbImport#cancel']);
	}//end testTheRoutesAreRegistered()

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
	 * An upload stopped by upload_max_filesize reports that limit when it is lower than the profile's.
	 *
	 * @return void
	 */
	public function testAnUploadOverPhpsLimitReportsThatLimit(): void {
		$service = $this->service();
		$service->expects($this->never())->method('import');
		$tooBig = ['tmp_name' => '', 'name' => 'export.xlsx', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE];

		$response = $this->controller(file: $tooBig, params: [], service: $service, ini: ['upload_max_filesize' => 2097152])->import();

		$this->assertSame(413, $response->getStatus());
		$this->assertEquals((object)['maxBytes' => 2097152], $response->getData()['details']);
		$this->assertSame('The file is larger than the maximum of 2 MB.', $response->getData()['message']);

		$higher = $this->controller(file: $tooBig, params: [], service: $service, ini: ['upload_max_filesize' => 52428800])->import();
		$this->assertEquals((object)['maxBytes' => 10485760], $higher->getData()['details']);
	}//end testAnUploadOverPhpsLimitReportsThatLimit()

	/**
	 * A body over post_max_size arrives without files or fields; it is 413, not "no file".
	 *
	 * @return void
	 */
	public function testABodyOverPostMaxSizeIsTooLarge(): void {
		$ini = ['post_max_size' => 8388608];

		$over = $this->controller(file: null, params: [], headers: ['Content-Length' => '9000000'], ini: $ini)->import();
		$this->assertSame(413, $over->getStatus());
		$this->assertSame('FILE_TOO_LARGE', $over->getData()['error']);
		$this->assertEquals((object)['maxBytes' => 8388608], $over->getData()['details']);

		$under = $this->controller(file: null, params: [], headers: ['Content-Length' => '1000'], ini: $ini)->import();
		$this->assertSame('NO_FILE_UPLOADED', $under->getData()['error']);
	}//end testABodyOverPostMaxSizeIsTooLarge()

	/**
	 * A server-side upload failure is 500 UPLOAD_FAILED and logged, not "no file was uploaded".
	 *
	 * @return array<string, array{int}>
	 */
	public static function serverUploadErrors(): array {
		return [
			'no tmp dir' => [UPLOAD_ERR_NO_TMP_DIR],
			'cannot write' => [UPLOAD_ERR_CANT_WRITE],
			'extension' => [UPLOAD_ERR_EXTENSION],
		];
	}//end serverUploadErrors()

	/**
	 * A server-side upload error answers 500 UPLOAD_FAILED and logs the PHP error.
	 *
	 * @param int $error The PHP upload error.
	 *
	 * @return void
	 */
	#[DataProvider('serverUploadErrors')]
	public function testAServerSideUploadErrorIsUploadFailed(int $error): void {
		$service = $this->service();
		$service->expects($this->never())->method('assertXlsx');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->anything(), ['uploadError' => $error]);
		$file = ['tmp_name' => '', 'name' => 'export.xlsx', 'size' => 0, 'error' => $error];

		$response = $this->controller(file: $file, params: ['municipalityName' => 'Gemeente Voorbeeldstad'], service: $service, logger: $logger)->import();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('UPLOAD_FAILED', $response->getData()['error']);
	}//end testAServerSideUploadErrorIsUploadFailed()

	/**
	 * A file that is not xlsx is 400 NOT_XLSX, checked before missingRecords and the municipality.
	 *
	 * @return void
	 */
	public function testANonXlsxFileIsRefused(): void {
		$service = $this->service();
		$service->method('assertXlsx')->willThrowException(new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'no'));
		$service->expects($this->never())->method('import');

		$response = $this->controller(file: $this->file(path: $this->upload(content: 'Applicatie Naam;APPID'), name: 'applications.csv'), params: ['missingRecords' => 'remove'], service: $service)->import();

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
			'no sheet' => [CmdbImportException::NO_SOURCE_SHEET, 422, ['expected' => ['Onbeh Applicaties CMDB', 'Beheerde Applicaties CMDB']]],
			'column' => [CmdbImportException::MISSING_COLUMN, 422, ['sheet' => 'Beheerde Applicaties CMDB', 'column' => 'APPID']],
			'rows' => [CmdbImportException::TOO_MANY_ROWS, 422, ['sheet' => 'Beheerde Applicaties CMDB', 'limit' => 10000]],
			'municipality' => [CmdbImportException::MUNICIPALITY_INVALID, 422, []],
			'corrupt' => [CmdbImportException::NOT_XLSX, 400, []],
			'unpacked size' => [CmdbImportException::WORKBOOK_TOO_LARGE, 413, ['maxUncompressedBytes' => 104857600]],
			'schema' => [CmdbImportException::SCHEMA_OUTDATED, 503, ['schema' => 'module', 'missing' => ['externalKey']]],
			'running' => [CmdbImportException::IMPORT_IN_PROGRESS, 409, []],
			'ambiguous' => [CmdbImportException::MUNICIPALITY_AMBIGUOUS, 422, ['matches' => ['00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002']]],
		];
	}//end serviceErrors()

	/**
	 * The engine codes added for the workbook, register, lock and municipality checks, with their words.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function engineMessages(): array {
		return [
			'unpacked size' => [CmdbImportException::WORKBOOK_TOO_LARGE, 'The workbook is too large to read once unpacked.'],
			'schema' => [CmdbImportException::SCHEMA_OUTDATED, 'The stackiq register is out of date; import its configuration again.'],
			'running' => [CmdbImportException::IMPORT_IN_PROGRESS, 'Another CMDB import is running; try again when it has finished.'],
			'ambiguous' => [CmdbImportException::MUNICIPALITY_AMBIGUOUS, 'Several municipalities have this name; choose one from the list.'],
		];
	}//end engineMessages()

	/**
	 * Each of these codes has its own translated message, not the generic one.
	 *
	 * @param string $code The error code.
	 * @param string $message The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('engineMessages')]
	public function testEngineCodesHaveTheirOwnMessage(string $code, string $message): void {
		$service = $this->service();
		$service->method('import')->willThrowException(new CmdbImportException(errorCode: $code, message: 'internal'));

		$response = $this->controller(file: $this->file(path: $this->upload()), params: ['municipalityName' => 'Gemeente Voorbeeldstad'], service: $service)->import();

		$this->assertSame($message, $response->getData()['message']);
	}//end testEngineCodesHaveTheirOwnMessage()

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
			$this->assertStringContainsString('Beheerde Applicaties CMDB', $response->getData()['message']);
			$this->assertStringContainsString('APPID', $response->getData()['message']);
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
	 * A PHP Error (not an Exception) from the import is the same generic 500, not a bare one.
	 *
	 * @return void
	 */
	public function testAnUnexpectedPhpErrorIsAGeneric500(): void {
		$service = $this->service();
		$service->method('import')->willThrowException(new \TypeError('internal detail'));

		$response = $this->controller(file: $this->file(path: $this->upload()), params: ['municipalityName' => 'Gemeente Voorbeeldstad'], service: $service)->import();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('IMPORT_FAILED', $response->getData()['error']);
	}//end testAnUnexpectedPhpErrorIsAGeneric500()

	/**
	 * A PHP Error while the upload is checked is the same generic 500, and nothing is imported.
	 *
	 * @return void
	 */
	public function testAnUnexpectedErrorWhileCheckingTheUploadIsAGeneric500(): void {
		$service = $this->service();
		$service->method('assertXlsx')->willThrowException(new \ValueError('zip internals'));
		$service->expects($this->never())->method('import');

		$response = $this->controller(file: $this->file(path: $this->upload()), params: ['municipalityName' => 'Gemeente Voorbeeldstad'], service: $service)->import();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('IMPORT_FAILED', $response->getData()['error']);
		$this->assertStringNotContainsString('zip internals', $response->getData()['message']);
	}//end testAnUnexpectedErrorWhileCheckingTheUploadIsAGeneric500()

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
					'publish' => true,
					'operationId' => 'cmdb-00000000-0000-0000-0000-000000000000',
					'fileName' => 'export.xlsx',
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
	 * Both municipality fields, trimmed, and an operationId outside the pattern reach the service unchanged.
	 *
	 * The service decides: the uuid wins over the name, and an id outside the
	 * pattern is replaced by a generated one.
	 *
	 * @return void
	 */
	public function testTheFieldsReachTheServiceAsSent(): void {
		$service = $this->service();
		$service->expects($this->once())->method('import')
			->with(
				$this->anything(),
				[
					'municipalityUuid' => '00000000-0000-0000-0000-000000000001',
					'municipalityName' => 'Gemeente Voorbeeldstad',
					'updateExisting' => true,
					'publish' => true,
					'operationId' => 'not-a-cmdb-id',
					'fileName' => 'export.xlsx',
				]
			)
			->willReturn(['success' => true]);

		$params = [
			'municipalityUuid' => ' 00000000-0000-0000-0000-000000000001 ',
			'municipalityName' => ' Gemeente Voorbeeldstad',
			'operationId' => 'not-a-cmdb-id',
		];
		$response = $this->controller(file: $this->file(path: $this->upload()), params: $params, service: $service)->import();

		$this->assertSame(200, $response->getStatus());
	}//end testTheFieldsReachTheServiceAsSent()

	/**
	 * A refusal from the service is logged at info with its code; an unexpected error at error with the exception.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailuresAreLogged(): void {
		$refusal = new CmdbImportException(errorCode: CmdbImportException::MISSING_COLUMN, message: 'no APPID', details: ['sheet' => 'S', 'column' => 'APPID']);
		$service = $this->service();
		$service->method('import')->willThrowException($refusal);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$logger->expects($this->once())->method('info')
			->with($this->anything(), ['error' => 'MISSING_COLUMN', 'details' => ['sheet' => 'S', 'column' => 'APPID'], 'reason' => 'no APPID']);

		$this->controller(file: $this->file(path: $this->upload()), params: ['municipalityName' => 'X'], service: $service, logger: $logger)->import();

		$failure = new RuntimeException("first owner@example.nl\nsecond", 0, new RuntimeException('previous jan@example.nl'));
		$service = $this->service();
		$service->method('import')->willThrowException($failure);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$logged): void {
				$logged = $context;
			}
		);

		$this->controller(file: $this->file(path: $this->upload()), params: ['municipalityName' => 'X'], service: $service, logger: $logger)->import();

		$this->assertSame(RuntimeException::class, $logged['exception'], 'the class is logged, not the exception object');
		$this->assertSame('first <e-mail>', $logged['error'], 'only the first line, without the e-mail address');
		$flat = json_encode($logged);
		$this->assertStringNotContainsString('example.nl', $flat);
		$this->assertStringNotContainsString('second', $flat);
		$this->assertStringNotContainsString('previous', $flat);
	}//end testRefusalsAndFailuresAreLogged()

	/**
	 * A failing cancel request answers with the contract envelope and logs no exception text.
	 *
	 * @return void
	 */
	public function testACancelThatFailsAnswersImportFailed(): void {
		$service = $this->service();
		$service->method('requestCancel')->willThrowException(new RuntimeException("cache down for owner@example.nl\nsecond"));
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$logged): void {
				$logged = $context;
			}
		);

		$response = $this->controller(file: null, params: [], service: $service, logger: $logger)->cancel(operationId: 'cmdb-running-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('IMPORT_FAILED', $response->getData()['error']);
		$this->assertStringNotContainsString('cache down', $response->getData()['message']);
		$this->assertSame(RuntimeException::class, $logged['exception']);
		$this->assertStringNotContainsString('example.nl', json_encode($logged));
		$this->assertStringNotContainsString('second', json_encode($logged));
	}//end testACancelThatFailsAnswersImportFailed()

	/**
	 * The upload's base name reaches the service as fileName, without any directory part the client sent.
	 *
	 * @return void
	 */
	public function testTheUploadsBaseNameReachesTheService(): void {
		$service = $this->service();
		$service->expects($this->once())->method('import')
			->with($this->anything(), $this->callback(fn (array $options): bool => $options['fileName'] === 'CMDB export.xlsx'))
			->willReturn(['success' => true]);

		$file = $this->file(path: $this->upload(), name: 'C:\\Users\\beheer\\CMDB export.xlsx');
		$response = $this->controller(file: $file, params: ['municipalityName' => 'Gemeente Voorbeeldstad'], service: $service)->import();

		$this->assertSame(200, $response->getStatus());
	}//end testTheUploadsBaseNameReachesTheService()

	/**
	 * The spellings of updateExisting and what each one means; null is refused.
	 *
	 * @return array<string, array{mixed, bool|null}>
	 */
	public static function updateExistingValues(): array {
		return [
			'absent' => [null, true],
			'empty' => ['', true],
			'true' => ['true', true],
			'TRUE' => ['TRUE', true],
			'one' => ['1', true],
			'false' => ['false', false],
			'padded false' => [' false', false],
			'False' => ['False ', false],
			'zero' => ['0', false],
			'typo' => ['flase', null],
			'off' => ['off', null],
			'no' => ['no', null],
			'yes' => ['yes', null],
			'blank' => ['  ', null],
			'array' => [['false'], null],
		];
	}//end updateExistingValues()

	/**
	 * Only true/false and 1/0 select a mode; anything else is 400 FIELD_INVALID and nothing is imported.
	 *
	 * @param mixed $value The form value, or null for an absent field.
	 * @param bool|null $expected The mode passed to the import, or null for a refusal.
	 *
	 * @return void
	 */
	#[DataProvider('updateExistingValues')]
	public function testUpdateExistingAcceptsOnlyExplicitValues(mixed $value, ?bool $expected): void {
		$service = $this->service();
		$params = ['municipalityName' => 'Gemeente Voorbeeldstad'];
		if ($value !== null) {
			$params['updateExisting'] = $value;
		}

		if ($expected === null) {
			$service->expects($this->never())->method('import');
		} else {
			$service->expects($this->once())->method('import')
				->with($this->anything(), $this->callback(fn (array $options): bool => $options['updateExisting'] === $expected))
				->willReturn(['success' => true]);
		}

		$response = $this->controller(file: $this->file(path: $this->upload()), params: $params, service: $service)->import();

		if ($expected === null) {
			$this->assertSame(400, $response->getStatus());
			$this->assertSame('FIELD_INVALID', $response->getData()['error']);
			$this->assertEquals((object)['field' => 'updateExisting', 'accepted' => ['true', 'false']], $response->getData()['details']);
			$this->assertSame('Field "updateExisting" must be one of: true, false.', $response->getData()['message']);
			return;
		}

		$this->assertSame(200, $response->getStatus());
	}//end testUpdateExistingAcceptsOnlyExplicitValues()

	/**
	 * Only true/false and 1/0 decide whether created modules are published; anything else is 400 FIELD_INVALID.
	 *
	 * The spellings are those of updateExisting: a typo never publishes what the admin chose to keep unpublished.
	 *
	 * @param mixed $value The form value, or null for an absent field.
	 * @param bool|null $expected The value passed to the import, or null for a refusal.
	 *
	 * @return void
	 */
	#[DataProvider('updateExistingValues')]
	public function testPublishAcceptsOnlyExplicitValues(mixed $value, ?bool $expected): void {
		$service = $this->service();
		$params = ['municipalityName' => 'Gemeente Voorbeeldstad'];
		if ($value !== null) {
			$params['publish'] = $value;
		}

		if ($expected === null) {
			$service->expects($this->never())->method('import');
		} else {
			$service->expects($this->once())->method('import')
				->with($this->anything(), $this->callback(fn (array $options): bool => $options['publish'] === $expected))
				->willReturn(['success' => true]);
		}

		$response = $this->controller(file: $this->file(path: $this->upload()), params: $params, service: $service)->import();

		if ($expected === null) {
			$this->assertSame(400, $response->getStatus());
			$this->assertSame('FIELD_INVALID', $response->getData()['error']);
			$this->assertEquals((object)['field' => 'publish', 'accepted' => ['true', 'false']], $response->getData()['details']);
			$this->assertSame('Field "publish" must be one of: true, false.', $response->getData()['message']);
			return;
		}

		$this->assertSame(200, $response->getStatus());
	}//end testPublishAcceptsOnlyExplicitValues()

	/**
	 * A text field sent as an array is 400 FIELD_INVALID naming it, not the string "Array".
	 *
	 * @return array<string, array{string}>
	 */
	public static function textFields(): array {
		return [
			'missingRecords' => ['missingRecords'],
			'municipalityUuid' => ['municipalityUuid'],
			'municipalityName' => ['municipalityName'],
		];
	}//end textFields()

	/**
	 * An array-valued text field is refused and nothing is imported.
	 *
	 * @param string $field The field sent as an array.
	 *
	 * @return void
	 */
	#[DataProvider('textFields')]
	public function testAnArrayValuedFieldIsRefused(string $field): void {
		$service = $this->service();
		$service->expects($this->never())->method('import');
		$params = ['municipalityName' => 'Gemeente Voorbeeldstad', $field => ['x']];

		$response = $this->controller(file: $this->file(path: $this->upload()), params: $params, service: $service)->import();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('FIELD_INVALID', $response->getData()['error']);
		$this->assertEquals((object)['field' => $field], $response->getData()['details']);
		$this->assertSame('Field "' . $field . '" has an invalid value.', $response->getData()['message']);
	}//end testAnArrayValuedFieldIsRefused()

	/**
	 * An upload field sent as `cmdbFile[]` is no upload: 400 NO_FILE_UPLOADED, and the ZIP check never runs.
	 *
	 * @return void
	 */
	public function testAnArrayValuedUploadIsNoUpload(): void {
		$service = $this->service();
		$service->expects($this->never())->method('assertXlsx');
		$file = ['tmp_name' => [$this->upload()], 'name' => ['export.xlsx'], 'size' => [4], 'error' => [UPLOAD_ERR_OK]];

		$response = $this->controller(file: $file, params: ['municipalityName' => 'Gemeente Voorbeeldstad'], service: $service)->import();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('NO_FILE_UPLOADED', $response->getData()['error']);
	}//end testAnArrayValuedUploadIsNoUpload()

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

	/**
	 * A malformed id is passed to the service as sent, which refuses it: 404 with the generic envelope.
	 *
	 * @return void
	 */
	public function testCancelWithAMalformedIdIsNotFound(): void {
		$service = $this->service();
		$service->expects($this->once())->method('requestCancel')->with("../cmdb-x\n")->willReturn(false);

		$response = $this->controller(file: null, params: [], service: $service)->cancel(operationId: "../cmdb-x\n");

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('OPERATION_NOT_FOUND', $response->getData()['error']);
		$this->assertEquals((object)[], $response->getData()['details']);
	}//end testCancelWithAMalformedIdIsNotFound()
}//end class
