<?php

/**
 * Tests for the preview endpoint of the previous Softwarecatalogus export.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
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

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\LegacyCatalogueImportController;
use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\LegacyCatalogueMigrationService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The right is checked before the upload is read; the upload and the fields are checked before the service reads them.
 */
class LegacyCatalogueImportControllerTest extends TestCase {
	/**
	 * The migration service.
	 *
	 * @var LegacyCatalogueMigrationService&MockObject
	 */
	private LegacyCatalogueMigrationService&MockObject $service;

	/**
	 * Set up the service mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: LegacyCatalogueMigrationService::class);
		$this->service->method('maxFileBytes')->willReturn(1000);
	}//end setUp()

	/**
	 * The controller with an upload in the `file` field.
	 *
	 * @param array<string, mixed>|null $file The upload, or null for none.
	 * @param bool $readsUpload Whether the controller may read the upload.
	 *
	 * @return LegacyCatalogueImportController
	 */
	private function controller(?array $file, bool $readsUpload = true): LegacyCatalogueImportController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$expectation = $this->never();
		if ($readsUpload === true) {
			$expectation = $this->atMost(allowedInvocations: 1);
		}

		$request->expects($expectation)
			->method('getUploadedFile')
			->willReturnCallback(static fn (string $key): ?array => [$key => null, 'file' => $file][$key]);

		return new LegacyCatalogueImportController(
			request: $request,
			service: $this->service,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A caller who may not create usages gets 403, and the upload is never read.
	 *
	 * @return void
	 */
	public function testACallerWithoutTheRightIsRefusedBeforeTheUploadIsRead(): void {
		$this->service->method('assertMayImport')->willThrowException(
			new CmdbImportException(errorCode: CmdbImportException::FORBIDDEN, message: 'no')
		);
		$this->service->expects($this->never())->method('preview');

		$response = $this->controller(file: ['tmp_name' => '/tmp/x', 'name' => 'x.xlsx', 'size' => 1], readsUpload: false)->preview();

		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'FORBIDDEN', 'details' => []], actual: $response->getData());
	}//end testACallerWithoutTheRightIsRefusedBeforeTheUploadIsRead()

	/**
	 * No upload is 400; an upload over the profile's limit, or over PHP's, is 413.
	 *
	 * @return void
	 */
	public function testAMissingOrTooLargeUploadIsRefused(): void {
		$this->service->expects($this->never())->method('preview');

		$this->assertSame(expected: 400, actual: $this->controller(file: null)->preview()->getStatus());
		$this->assertSame(
			expected: ['error' => 'FILE_TOO_LARGE', 'details' => ['maxBytes' => 1000]],
			actual: $this->controller(file: ['tmp_name' => '/tmp/x', 'name' => 'x.xlsx', 'size' => 1001, 'error' => UPLOAD_ERR_OK])->preview()->getData()
		);
		$this->assertSame(
			expected: 413,
			actual: $this->controller(file: ['tmp_name' => '', 'name' => 'x.xlsx', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE])->preview()->getStatus()
		);
	}//end testAMissingOrTooLargeUploadIsRefused()

	/**
	 * A stored upload is previewed; a contract error keeps its code and status; anything else is PREVIEW_FAILED.
	 *
	 * @return void
	 */
	public function testTheUploadIsPreviewed(): void {
		$this->service->method('preview')->willReturnCallback(
			static function (string $path, string $fileName): array {
				if ($fileName === 'broken.xlsx') {
					throw new RuntimeException('boom');
				}

				if ($fileName === 'no-supplier.xlsx') {
					throw new CmdbImportException(
						errorCode: CmdbImportException::MISSING_COLUMN,
						message: 'missing',
						details: ['sheet' => 'Applicaties', 'column' => 'Leverancier']
					);
				}

				return ['rows' => [], 'summary' => ['rows' => 0], 'path' => $path];
			}
		);

		$ok = $this->controller(file: ['tmp_name' => '/tmp/up', 'name' => 'export.xlsx', 'size' => 10, 'error' => UPLOAD_ERR_OK])->preview();
		$this->assertSame(expected: 200, actual: $ok->getStatus());
		$this->assertSame(expected: '/tmp/up', actual: $ok->getData()['path']);

		$missing = $this->controller(file: ['tmp_name' => '/tmp/up', 'name' => 'no-supplier.xlsx', 'size' => 10])->preview();
		$this->assertSame(expected: 422, actual: $missing->getStatus());
		$this->assertSame(expected: ['sheet' => 'Applicaties', 'column' => 'Leverancier'], actual: $missing->getData()['details']);

		$broken = $this->controller(file: ['tmp_name' => '/tmp/up', 'name' => 'broken.xlsx', 'size' => 10])->preview();
		$this->assertSame(expected: 500, actual: $broken->getStatus());
		$this->assertSame(expected: 'PREVIEW_FAILED', actual: $broken->getData()['error']);
	}//end testTheUploadIsPreviewed()
	/**
	 * Apply passes the left-out ids on; a `leftOut` that is no JSON array of strings is 400 before anything is read.
	 *
	 * @return void
	 */
	public function testApplyPassesTheLeftOutIds(): void {
		$this->service->expects($this->once())
			->method('apply')
			->with('/tmp/up', 'export.xlsx', ['swc-4'])
			->willReturn(['created' => 1, 'updated' => 0, 'skipped' => 1, 'skippedRows' => []]);

		$ok = $this->applying(leftOut: '["swc-4"]')->apply();
		$this->assertSame(expected: 200, actual: $ok->getStatus());
		$this->assertSame(expected: 1, actual: $ok->getData()['created']);

		foreach (['not json', '{"a": 1}', '[1]'] as $leftOut) {
			$refused = $this->applying(leftOut: $leftOut)->apply();
			$this->assertSame(expected: ['error' => 'INVALID_LEFT_OUT', 'details' => []], actual: $refused->getData(), message: $leftOut);
		}
	}//end testApplyPassesTheLeftOutIds()

	/**
	 * Apply refuses a caller without the right with 403 before reading any field.
	 *
	 * @return void
	 */
	public function testApplyRefusesACallerWithoutTheRight(): void {
		$this->service->method('assertMayImport')->willThrowException(
			new CmdbImportException(errorCode: CmdbImportException::FORBIDDEN, message: 'no')
		);
		$this->service->expects($this->never())->method('apply');
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->expects($this->never())->method('getParam');
		$request->expects($this->never())->method('getUploadedFile');

		$controller = new LegacyCatalogueImportController(
			request: $request,
			service: $this->service,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(expected: 403, actual: $controller->apply()->getStatus());
	}//end testApplyRefusesACallerWithoutTheRight()

	/**
	 * Access answers whether the caller may import, and never reads the request.
	 *
	 * @return void
	 */
	public function testAccessAnswersWhetherTheCallerMayImport(): void {
		$this->service->method('assertMayImport')->willReturnOnConsecutiveCalls(
			'org-9',
			$this->throwException(exception: new CmdbImportException(errorCode: CmdbImportException::FORBIDDEN, message: 'no'))
		);
		$controller = $this->controller(file: null, readsUpload: false);

		$this->assertSame(expected: ['mayImport' => true], actual: $controller->access()->getData());
		$this->assertSame(expected: ['mayImport' => false], actual: $controller->access()->getData());
	}//end testAccessAnswersWhetherTheCallerMayImport()

	/**
	 * The controller with an upload and a `leftOut` field.
	 *
	 * @param string $leftOut The field.
	 *
	 * @return LegacyCatalogueImportController
	 */
	private function applying(string $leftOut): LegacyCatalogueImportController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key): string => [$key => '', 'leftOut' => $leftOut][$key]);
		$request->method('getUploadedFile')->willReturn(['tmp_name' => '/tmp/up', 'name' => 'export.xlsx', 'size' => 10, 'error' => UPLOAD_ERR_OK]);

		return new LegacyCatalogueImportController(
			request: $request,
			service: $this->service,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end applying()
}//end class
