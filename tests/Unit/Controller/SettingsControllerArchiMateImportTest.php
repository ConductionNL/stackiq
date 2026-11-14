<?php

/**
 * The ArchiMate import endpoint: upload parsing and the import method it picks.
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
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\Settings\ArchiMateImportControl;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * importArchiMate(): admins only; an uploaded file becomes the import
 * options, the `useOptimized` parameter picks the import method, a request
 * without a file is a 400 and a failing import maps to an error status.
 *
 * @covers \OCA\Stackiq\Controller\SettingsController
 */
class SettingsControllerArchiMateImportTest extends TestCase {

	/**
	 * The uploaded file as PHP describes it.
	 *
	 * @var array<string, mixed>
	 */
	private const UPLOAD = ['tmp_name' => '/tmp/phpAbc', 'name' => 'model.xml', 'size' => 2048, 'type' => 'application/xml'];

	private ArchiMateService&MockObject $archiMate;

	private LoggerInterface&MockObject $logger;

	/**
	 * Request parameters, by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Wire the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->archiMate = $this->createMock(ArchiMateService::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		unset($_FILES['archiMateFile']);
	}//end setUp()

	/**
	 * Leave no upload behind for other tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset($_FILES['archiMateFile']);
		parent::tearDown();
	}//end tearDown()

	/**
	 * The controller under test.
	 *
	 * @param array<string, mixed>|null $upload  The file the request carries.
	 * @param bool                      $isAdmin Whether the caller is an admin.
	 *
	 * @return SettingsController
	 */
	private function controller(?array $upload, bool $isAdmin = true): SettingsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturnCallback(
			static fn (string $key): ?array => $key === 'archiMateFile' ? $upload : null
		);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => array_key_exists($key, $this->params) === true ? $this->params[$key] : $default
		);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => $name === 'Content-Type' ? 'multipart/form-data; boundary=x' : ''
		);

		return new SettingsController(
			'stackiq',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$groupManager,
			$userSession,
			$this->createMock(SettingsService::class),
			$this->createMock(CronjobSettings::class),
			$this->createMock(OrganizationSyncService::class),
			$this->archiMate,
			$this->createMock(RoleGroupSettings::class),
			$this->createMock(ProgressTracker::class),
			$this->createMock(ArchiMateImportControl::class),
			$this->createMock(EolSyncService::class),
			$this->createMock(EmailSettings::class),
			$this->logger
		);
	}//end controller()

	public function testANonAdminIsRefused(): void {
		$this->archiMate->expects($this->never())->method($this->anything());

		$response = $this->controller(upload: self::UPLOAD, isAdmin: false)->importArchiMate();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Admin privileges required'], $response->getData());
	}//end testANonAdminIsRefused()

	public function testAnUploadRunsTheOptimizedImportWithItsOptions(): void {
		$this->params = ['operationId' => 'archimate_import_abc', 'deleteOrphaned' => 'true', 'processingMode' => 'memory'];
		$this->archiMate->expects($this->never())->method('importArchiMateFileFromPath');
		$this->archiMate->expects($this->once())->method('importArchiMateFileFromPathOptimized')->with(
			[
				'updateExisting' => true,
				'deleteOrphaned' => true,
				'preserveIds' => true,
				'processingMode' => 'memory',
				'filePath' => '/tmp/phpAbc',
				'fileName' => 'model.xml',
				'fileSize' => 2048,
				'mimeType' => 'application/xml',
				'operationId' => 'archimate_import_abc',
			]
		)->willReturn(['success' => true, 'imported' => 12]);

		$response = $this->controller(upload: self::UPLOAD)->importArchiMate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true, 'imported' => 12], $response->getData());
	}//end testAnUploadRunsTheOptimizedImportWithItsOptions()

	public function testTheStandardImportRunsWhenOptimizedIsOff(): void {
		$this->params = ['useOptimized' => 'false', 'updateExisting' => 'false', 'preserveIds' => 'false'];
		$this->archiMate->expects($this->never())->method('importArchiMateFileFromPathOptimized');
		$this->archiMate->expects($this->once())->method('importArchiMateFileFromPath')->with(
			$this->callback(
				static fn (array $options): bool => $options['updateExisting'] === false
					&& $options['deleteOrphaned'] === false
					&& $options['preserveIds'] === false
					&& $options['processingMode'] === 'speed'
					&& $options['operationId'] === null
			)
		)->willReturn(['success' => true]);
		$this->logger->expects($this->once())->method('info')->with('Using STANDARD ArchiMate import method.');

		$response = $this->controller(upload: self::UPLOAD)->importArchiMate();

		$this->assertSame(['success' => true], $response->getData());
	}//end testTheStandardImportRunsWhenOptimizedIsOff()

	public function testAPhpFilesUploadIsUsedWhenTheRequestHasNone(): void {
		$_FILES['archiMateFile'] = ['tmp_name' => '/tmp/phpXyz', 'name' => 'other.xml'];
		$this->archiMate->expects($this->once())->method('importArchiMateFileFromPathOptimized')->with(
			$this->callback(
				static fn (array $options): bool => $options['filePath'] === '/tmp/phpXyz'
					&& $options['fileName'] === 'other.xml'
					&& $options['mimeType'] === 'text/xml'
			)
		)->willReturn(['success' => true]);

		// No size given: it is read from the file, which does not exist here.
		$response = @$this->controller(upload: null)->importArchiMate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAPhpFilesUploadIsUsedWhenTheRequestHasNone()

	public function testWithoutAFileTheRequestIsRefused(): void {
		$this->archiMate->expects($this->never())->method($this->anything());

		$response = $this->controller(upload: null)->importArchiMate();
		$data     = $response->getData();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('NO_FILE_UPLOADED_OR_PATH', $data['error']);
		$this->assertSame(
			['contentType' => 'multipart/form-data; boundary=x', 'isMultipart' => true, 'filesKeys' => []],
			$data['debug']
		);
	}//end testWithoutAFileTheRequestIsRefused()

	public function testAnInvalidImportIsABadRequest(): void {
		$this->archiMate->method('importArchiMateFileFromPathOptimized')
			->willThrowException(new \InvalidArgumentException('not an ArchiMate model'));
		$this->logger->expects($this->once())->method('error')->with('ArchiMate import failed');

		$response = $this->controller(upload: self::UPLOAD)->importArchiMate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Import failed: not an ArchiMate model', 'error' => 'not an ArchiMate model'],
			$response->getData()
		);
	}//end testAnInvalidImportIsABadRequest()
}//end class
