<?php

/**
 * SettingsControllerRoundTripCheckTest
 *
 * The round-trip check route is admin only and upload only
 * (architecture-round-trip-check REQ-ART-003, task 4).
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Service\ArchiMateRoundTripService;
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
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for SettingsController::checkArchiMateRoundTrip().
 */
class SettingsControllerRoundTripCheckTest extends TestCase {

	/**
	 * The round-trip service.
	 *
	 * @var ArchiMateRoundTripService&MockObject
	 */
	private ArchiMateRoundTripService $roundTrip;

	/**
	 * A stand-in uploaded file.
	 *
	 * @var string
	 */
	private string $upload = '';

	/**
	 * Prepare the service double and the upload.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->roundTrip = $this->createMock(ArchiMateRoundTripService::class);
		$this->upload = (string) tempnam(sys_get_temp_dir(), 'amef');
	}//end setUp()

	/**
	 * Remove the upload.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->upload);
	}//end tearDown()

	/**
	 * The controller as a user, with the given request parameters and upload.
	 *
	 * @param bool                 $admin  Whether the user is an admin.
	 * @param array<string, mixed> $params Request parameters.
	 * @param bool                 $file   Whether a file was uploaded.
	 *
	 * @return SettingsController
	 */
	private function controller(bool $admin, array $params = [], bool $file = true): SettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		$request->method('getUploadedFile')->willReturn($file === true ? ['tmp_name' => $this->upload, 'name' => 'gemma.xml', 'size' => 10, 'type' => 'text/xml'] : null);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($admin === true ? 'admin' : 'pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new SettingsController(
			'stackiq',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$groups,
			$session,
			$this->createMock(SettingsService::class),
			$this->createMock(CronjobSettings::class),
			$this->createMock(OrganizationSyncService::class),
			$this->createMock(ArchiMateService::class),
			$this->createMock(RoleGroupSettings::class),
			$this->createMock(ProgressTracker::class),
			$this->createMock(ArchiMateImportControl::class),
			$this->createMock(EolSyncService::class),
			$this->createMock(EmailSettings::class),
			new NullLogger(),
			roundTrip: $this->roundTrip
		);
	}//end controller()

	/**
	 * A user without admin rights gets 403 and the service does not run.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefusedBeforeTheServiceRuns(): void {
		$this->roundTrip->expects($this->never())->method('check');

		$response = $this->controller(admin: false)->checkArchiMateRoundTrip();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'Admin privileges required'], $response->getData());
	}//end testANonAdminIsRefusedBeforeTheServiceRuns()

	/**
	 * A file_path parameter is refused with 400, even for an admin, before the service runs.
	 *
	 * @return void
	 */
	public function testAFilePathIsRefused(): void {
		$this->roundTrip->expects($this->never())->method('check');

		$response = $this->controller(admin: true, params: ['file_path' => '/etc/passwd'])->checkArchiMateRoundTrip();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('FILE_PATH_NOT_ALLOWED', $response->getData()['error']);
	}//end testAFilePathIsRefused()

	/**
	 * Without an upload the answer is 400; with one the service checks the uploaded file in the asked mode.
	 *
	 * @return void
	 */
	public function testAnUploadIsCheckedInTheAskedMode(): void {
		$this->assertSame(400, $this->controller(admin: true, file: false)->checkArchiMateRoundTrip()->getStatus());

		$this->roundTrip->expects($this->once())->method('check')
			->with($this->upload, 'against-imported')
			->willReturn(['mode' => 'against-imported', 'imported' => false, 'message' => 'The model is not imported']);

		$response = $this->controller(admin: true, params: ['mode' => 'against-imported'])->checkArchiMateRoundTrip();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['success' => true, 'fileName' => 'gemma.xml', 'mode' => 'against-imported', 'imported' => false, 'message' => 'The model is not imported'],
			$response->getData()
		);
	}//end testAnUploadIsCheckedInTheAskedMode()

	/**
	 * A refused mode is 400 and a failing check 500, each with its message.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailuresMapToTheirStatus(): void {
		$this->roundTrip->method('check')->willReturnCallback(
			static fn (string $path, string $mode): array => $mode === 'odd'
				? throw new \InvalidArgumentException('mode must be before-import or against-imported')
				: throw new \RuntimeException('OpenRegister is not available')
		);

		$refused = $this->controller(admin: true, params: ['mode' => 'odd'])->checkArchiMateRoundTrip();
		$this->assertSame(400, $refused->getStatus());
		$this->assertSame('mode must be before-import or against-imported', $refused->getData()['message']);

		$failed = $this->controller(admin: true)->checkArchiMateRoundTrip();
		$this->assertSame(500, $failed->getStatus());
		$this->assertSame('The round-trip check failed: OpenRegister is not available', $failed->getData()['message']);
	}//end testRefusalsAndFailuresMapToTheirStatus()
}//end class
