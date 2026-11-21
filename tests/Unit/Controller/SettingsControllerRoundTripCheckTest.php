<?php

/**
 * The admin route of the ArchiMate round-trip check.
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
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-003-only-an-admin-shall-run-the-check-and-the-old-round-trip-endpoint-shall-be-removed
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
 * @covers \OCA\Stackiq\Controller\SettingsController
 */
final class SettingsControllerRoundTripCheckTest extends TestCase {

	/**
	 * An uploaded exchange file.
	 */
	private const UPLOAD = ['tmp_name' => '/tmp/phpRoundTrip', 'name' => 'model.xml', 'size' => 2048, 'type' => 'application/xml'];

	/**
	 * The ArchiMate service double.
	 *
	 * @var ArchiMateService&MockObject
	 */
	private ArchiMateService&MockObject $archiMate;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Fresh doubles, and no upload left in the superglobal.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->archiMate = $this->createMock(originalClassName: ArchiMateService::class);
		unset($_FILES['archiMateFile']);
	}//end setUp()

	/**
	 * The controller for an admin or another user, with or without an upload.
	 *
	 * @param array<string, mixed>|null $upload  The uploaded file.
	 * @param bool                      $isAdmin Whether the user is an admin.
	 *
	 * @return SettingsController
	 */
	private function controller(?array $upload, bool $isAdmin = true): SettingsController {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('someone');
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getUploadedFile')->willReturnCallback(
			static function (string $key) use ($upload): ?array {
				if ($key === 'archiMateFile') {
					return $upload;
				}

				return null;
			}
		);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null): mixed {
				return $this->params[$key] ?? $default;
			}
		);

		return new SettingsController(
			appName: 'stackiq',
			request: $request,
			config: $this->createMock(originalClassName: IAppConfig::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			appManager: $this->createMock(originalClassName: IAppManager::class),
			groupManager: $groupManager,
			userSession: $userSession,
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			cronjobSettings: $this->createMock(originalClassName: CronjobSettings::class),
			orgSyncSvc: $this->createMock(originalClassName: OrganizationSyncService::class),
			archiMateService: $this->archiMate,
			roleGroupSettings: $this->createMock(originalClassName: RoleGroupSettings::class),
			progressTracker: $this->createMock(originalClassName: ProgressTracker::class),
			archiMateImportControl: $this->createMock(originalClassName: ArchiMateImportControl::class),
			eolSyncService: $this->createMock(originalClassName: EolSyncService::class),
			emailSettings: $this->createMock(originalClassName: EmailSettings::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A user who is not an admin gets 403 and the service does not run.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefusedBeforeTheServiceRuns(): void {
		$this->archiMate->expects($this->never())->method('checkRoundTrip');

		$response = $this->controller(upload: self::UPLOAD, isAdmin: false)->checkArchiMateRoundTrip();

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testANonAdminIsRefusedBeforeTheServiceRuns()

	/**
	 * A file path in the body is refused with 400.
	 *
	 * @return void
	 */
	public function testAFilePathIsRefused(): void {
		$this->params = ['file_path' => '/etc/passwd'];
		$this->archiMate->expects($this->never())->method('checkRoundTrip');

		$response = $this->controller(upload: self::UPLOAD)->checkArchiMateRoundTrip();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'FILE_PATH_NOT_ALLOWED', actual: $response->getData()['error']);
	}//end testAFilePathIsRefused()

	/**
	 * An unknown mode is refused with 400.
	 *
	 * @return void
	 */
	public function testAnUnknownModeIsRefused(): void {
		$this->params = ['mode' => 'write-everything'];
		$this->archiMate->expects($this->never())->method('checkRoundTrip');

		$response = $this->controller(upload: self::UPLOAD)->checkArchiMateRoundTrip();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'UNKNOWN_MODE', actual: $response->getData()['error']);
	}//end testAnUnknownModeIsRefused()

	/**
	 * Without an upload the answer is 400.
	 *
	 * @return void
	 */
	public function testNoUploadIsRefused(): void {
		$this->archiMate->expects($this->never())->method('checkRoundTrip');

		$response = $this->controller(upload: null)->checkArchiMateRoundTrip();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'NO_FILE_UPLOADED', actual: $response->getData()['error']);
	}//end testNoUploadIsRefused()

	/**
	 * The upload is checked before import by default, and the report comes back.
	 *
	 * @return void
	 */
	public function testAnUploadIsCheckedBeforeImportByDefault(): void {
		$this->archiMate->expects($this->once())->method('checkRoundTrip')
			->with('/tmp/phpRoundTrip', 'before-import')
			->willReturn(['mode' => 'before-import', 'imported' => true, 'lossless' => true, 'categories' => []]);

		$response = $this->controller(upload: self::UPLOAD)->checkArchiMateRoundTrip();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(
			expected: ['success' => true, 'mode' => 'before-import', 'imported' => true, 'lossless' => true, 'categories' => []],
			actual: $response->getData()
		);
	}//end testAnUploadIsCheckedBeforeImportByDefault()

	/**
	 * The against-imported mode is passed on.
	 *
	 * @return void
	 */
	public function testTheAgainstImportedModeIsPassedOn(): void {
		$this->params = ['mode' => 'against-imported'];
		$this->archiMate->expects($this->once())->method('checkRoundTrip')
			->with('/tmp/phpRoundTrip', 'against-imported')
			->willReturn(['mode' => 'against-imported', 'imported' => false, 'message' => 'This model is not imported.']);

		$response = $this->controller(upload: self::UPLOAD)->checkArchiMateRoundTrip();

		$this->assertSame(expected: false, actual: $response->getData()['imported']);
	}//end testTheAgainstImportedModeIsPassedOn()

	/**
	 * A failing check answers with the exception's status.
	 *
	 * @return void
	 */
	public function testAFailingCheckIsReported(): void {
		$this->archiMate->method('checkRoundTrip')->willThrowException(new \InvalidArgumentException('not XML'));

		$response = $this->controller(upload: self::UPLOAD)->checkArchiMateRoundTrip();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: false, actual: $response->getData()['success']);
	}//end testAFailingCheckIsReported()
}//end class
