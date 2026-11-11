<?php

/**
 * Unit tests for ItsmExchangeController.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Thin controller over ItsmExchangeService; status codes and the public status shape are pinned here.
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ItsmExchangeController;
use OCA\Stackiq\Service\ItsmExchangeService;
use OCA\Stackiq\Service\ItsmFileImportService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What a non-admin may see, and how set-up and import answer.
 *
 * @covers \OCA\Stackiq\Controller\ItsmExchangeController
 *
 * @spec exclude Thin controller over ItsmExchangeService; status codes and the public status shape are pinned here.
 */
class ItsmExchangeControllerTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var ItsmExchangeService&MockObject
	 */
	private ItsmExchangeService&MockObject $exchange;

	/**
	 * @var ItsmFileImportService&MockObject
	 */
	private ItsmFileImportService&MockObject $fileImport;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->exchange = $this->createMock(originalClassName: ItsmExchangeService::class);
		$this->fileImport = $this->createMock(originalClassName: ItsmFileImportService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return ItsmExchangeController
	 */
	private function controller(): ItsmExchangeController {
		return new ItsmExchangeController(request: $this->request, exchange: $this->exchange, fileImport: $this->fileImport, userSession: $this->session);
	}//end controller()

	/**
	 * The public status shows only the five public keys, never the admin details.
	 *
	 * @return void
	 */
	public function testStatusExposesOnlyThePublicKeys(): void {
		$this->exchange->method('status')->willReturn(
			[
				'available' => true,
				'enabled' => true,
				'desk' => 'servicedesk',
				'setUpAt' => '2026-11-01',
				'desks' => ['servicedesk'],
				'templateId' => 'secret-template',
				'runAs' => 'admin',
			]
		);

		$response = $this->controller()->status();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(
			expected: ['available' => true, 'enabled' => true, 'desk' => 'servicedesk', 'setUpAt' => '2026-11-01', 'desks' => ['servicedesk']],
			actual: $response->getData()
		);
	}//end testStatusExposesOnlyThePublicKeys()

	/**
	 * The admin config returns the full status.
	 *
	 * @return void
	 */
	public function testConfigReturnsTheFullStatus(): void {
		$this->exchange->method('status')->willReturn(['available' => false, 'runAs' => 'admin']);
		$this->assertSame(expected: ['available' => false, 'runAs' => 'admin'], actual: $this->controller()->config()->getData());
	}//end testConfigReturnsTheFullStatus()

	/**
	 * Set-up without a signed-in user is refused before the service is called.
	 *
	 * @return void
	 */
	public function testSetUpWithoutAUserIsUnauthorised(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->exchange->expects($this->never())->method('setUp');

		$response = $this->controller()->setUp('servicedesk', 'org-1', 'tpl');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['created' => false, 'message' => 'Sign in first.'], actual: $response->getData());
	}//end testSetUpWithoutAUserIsUnauthorised()

	/**
	 * Set-up runs as the signed-in user with a trimmed template id.
	 *
	 * @return void
	 */
	public function testSetUpRunsAsTheSignedInUser(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$this->session->method('getUser')->willReturn($user);
		$this->exchange->expects($this->once())->method('setUp')
			->with('servicedesk', 'org-1', 'beheerder', 'tpl-9')
			->willReturn(['created' => true, 'desk' => 'servicedesk']);

		$response = $this->controller()->setUp('servicedesk', 'org-1', '  tpl-9 ');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['created' => true, 'desk' => 'servicedesk'], actual: $response->getData());
	}//end testSetUpRunsAsTheSignedInUser()

	/**
	 * A set-up the service refuses answers 422 with the service's reason.
	 *
	 * @return void
	 */
	public function testRefusedSetUpIsUnprocessable(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$this->session->method('getUser')->willReturn($user);
		$this->exchange->method('setUp')->willReturn(['created' => false, 'message' => 'Desk exists.']);

		$response = $this->controller()->setUp('servicedesk');

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(expected: ['created' => false, 'message' => 'Desk exists.'], actual: $response->getData());
	}//end testRefusedSetUpIsUnprocessable()

	/**
	 * Upload shapes that never reach the importer.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function badUploads(): array {
		return [
			'no file' => [null],
			'upload error' => [['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => '/tmp/x', 'name' => 'a.csv']],
			'no error key' => [['tmp_name' => '/tmp/x']],
			'no temp path' => [['error' => UPLOAD_ERR_OK, 'name' => 'a.csv']],
		];
	}//end badUploads()

	/**
	 * A missing or broken upload answers 400 without importing.
	 *
	 * @param mixed $file The uploaded file entry.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('badUploads')]
	public function testBrokenUploadIsABadRequest(mixed $file): void {
		$this->request->method('getUploadedFile')->with('file')->willReturn($file);
		$this->fileImport->expects($this->never())->method('import');

		$response = $this->controller()->import();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['started' => false, 'message' => 'Choose a .csv or .xlsx file to import.'], actual: $response->getData());
	}//end testBrokenUploadIsABadRequest()

	/**
	 * A good upload is imported from its temp path under its own name.
	 *
	 * @return void
	 */
	public function testGoodUploadIsImported(): void {
		$this->request->method('getUploadedFile')->willReturn(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/php1', 'name' => 'cmdb.xlsx']);
		$this->fileImport->expects($this->once())->method('import')->with('/tmp/php1', 'cmdb.xlsx')
			->willReturn(['started' => true, 'rows' => 12]);

		$response = $this->controller()->import();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['started' => true, 'rows' => 12], actual: $response->getData());
	}//end testGoodUploadIsImported()

	/**
	 * An import the service refuses answers 422.
	 *
	 * @return void
	 */
	public function testRefusedImportIsUnprocessable(): void {
		$this->request->method('getUploadedFile')->willReturn(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/php1']);
		$this->fileImport->method('import')->with('/tmp/php1', '')->willReturn(['started' => false, 'message' => 'Unknown format.']);

		$response = $this->controller()->import();

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
	}//end testRefusedImportIsUnprocessable()
}//end class
