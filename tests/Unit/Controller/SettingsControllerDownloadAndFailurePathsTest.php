<?php

/**
 * Unit tests for the ArchiMate download and the failure answers of SettingsController.
 *
 * Asserts every answer of downloadArchiMate (refusals, missing and wrong-type
 * files, the stream and its headers, a failing lookup), and the 500 answers
 * of status, heartbeat, getProgress and syncOrganisations when their
 * collaborator throws.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
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
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Settings\ArchiMateImportControl;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * ArchiMate download and failure answers.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */
#[CoversClass(SettingsController::class)]
class SettingsControllerDownloadAndFailurePathsTest extends TestCase {

	/**
	 * Request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Settings service mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Progress tracker mock.
	 *
	 * @var ProgressTracker&MockObject
	 */
	private ProgressTracker&MockObject $progressTracker;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fresh mocks for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request         = $this->createMock(IRequest::class);
		$this->container       = $this->createMock(ContainerInterface::class);
		$this->userSession     = $this->createMock(IUserSession::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->progressTracker = $this->createMock(ProgressTracker::class);
		$this->logger          = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller with the current mocks.
	 *
	 * @return SettingsController
	 */
	private function controller(): SettingsController {
		return new SettingsController(
			appName: 'stackiq',
			request: $this->request,
			config: $this->createMock(IAppConfig::class),
			container: $this->container,
			appManager: $this->createMock(IAppManager::class),
			groupManager: $this->createMock(IGroupManager::class),
			userSession: $this->userSession,
			settingsService: $this->settingsService,
			cronjobSettings: $this->createMock(CronjobSettings::class),
			orgSyncSvc: $this->createMock(OrganizationSyncService::class),
			archiMateService: $this->createMock(ArchiMateService::class),
			roleGroupSettings: $this->createMock(RoleGroupSettings::class),
			progressTracker: $this->progressTracker,
			archiMateImportControl: $this->createMock(ArchiMateImportControl::class),
			eolSyncService: $this->createMock(EolSyncService::class),
			emailSettings: $this->createMock(EmailSettings::class),
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * Sign in a user called alice.
	 *
	 * @return IUser&MockObject
	 */
	private function signIn(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}//end signIn()

	/**
	 * Wire the container to answer alice's files folder.
	 *
	 * @param Folder&MockObject $folder The user folder.
	 *
	 * @return void
	 */
	private function userFolder(Folder&MockObject $folder): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);
		$this->container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				IUserSession::class => $this->userSession,
				IRootFolder::class => $rootFolder,
			}
		);
	}//end userFolder()

	/**
	 * The JSON body of a response.
	 *
	 * @param mixed $response The response.
	 *
	 * @return array<string, mixed>
	 */
	private function data(mixed $response): array {
		$this->assertInstanceOf(JSONResponse::class, $response);
		return $response->getData();
	}//end data()

	/**
	 * The headers the controller set on a response.
	 *
	 * Read from the property, because Response::getHeaders() merges in
	 * defaults that need a running Nextcloud server.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private function headers(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headers()

	/**
	 * A download needs a session.
	 *
	 * @return void
	 */
	public function testDownloadNeedsASession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->container->expects($this->never())->method('get');

		$response = $this->controller()->downloadArchiMate('export.xml');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $this->data($response));
	}//end testDownloadNeedsASession()

	/**
	 * File names that leave the user folder.
	 *
	 * @return array<string, array{string}>
	 */
	public static function traversalNames(): array {
		return [
			'parent directory' => ['../secret.xml'],
			'sub path' => ['exports/model.xml'],
			'dots inside name' => ['model..xml'],
		];
	}//end traversalNames()

	/**
	 * A name with a path separator or `..` is refused before any file lookup.
	 *
	 * @param string $fileName The requested name.
	 *
	 * @return void
	 */
	#[DataProvider('traversalNames')]
	public function testDownloadRefusesPathTraversal(string $fileName): void {
		$this->signIn();
		$this->container->expects($this->never())->method('get');

		$response = $this->controller()->downloadArchiMate($fileName);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Invalid filename', 'error' => 'INVALID_FILENAME'],
			$this->data($response)
		);
	}//end testDownloadRefusesPathTraversal()

	/**
	 * A file that is not in the user folder answers 404.
	 *
	 * @return void
	 */
	public function testDownloadOfAMissingFileAnswers404(): void {
		$this->signIn();
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->with('export.xml')->willReturn(false);
		$folder->expects($this->never())->method('get');
		$this->userFolder($folder);

		$response = $this->controller()->downloadArchiMate('export.xml');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'File not found', 'error' => 'FILE_NOT_FOUND'],
			$this->data($response)
		);
	}//end testDownloadOfAMissingFileAnswers404()

	/**
	 * A node that is not a file (a folder) answers 400.
	 *
	 * @return void
	 */
	public function testDownloadOfAFolderAnswers400(): void {
		$this->signIn();
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->with('exports')->willReturn($this->createMock(Folder::class));
		$this->userFolder($folder);

		$response = $this->controller()->downloadArchiMate('exports');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Invalid file type', 'error' => 'INVALID_FILE_TYPE'],
			$this->data($response)
		);
	}//end testDownloadOfAFolderAnswers400()

	/**
	 * File names and the content type each is served as.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function contentTypes(): array {
		return [
			'xml' => ['model.xml', 'application/xml'],
			'archimate upper case' => ['Model.ARCHIMATE', 'application/xml'],
			'json' => ['model.json', 'application/json'],
			'other' => ['model.zip', 'application/octet-stream'],
		];
	}//end contentTypes()

	/**
	 * A file is streamed with its content type, disposition and length.
	 *
	 * @param string $fileName    The requested name.
	 * @param string $contentType The expected content type.
	 *
	 * @return void
	 */
	#[DataProvider('contentTypes')]
	public function testDownloadStreamsTheFile(string $fileName, string $contentType): void {
		$this->signIn();
		$file = $this->createMock(File::class);
		$file->expects($this->once())->method('fopen')->with('r')->willReturn(fopen('php://memory', 'r'));
		$file->method('getSize')->willReturn(2048);
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->with($fileName)->willReturn(true);
		$folder->method('get')->with($fileName)->willReturn($file);
		$this->userFolder($folder);

		$response = $this->controller()->downloadArchiMate($fileName);

		$this->assertInstanceOf(StreamResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$headers = $this->headers($response);
		$this->assertSame($contentType, $headers['Content-Type']);
		$this->assertSame(
			'attachment; filename="' . $fileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName),
			$headers['Content-Disposition']
		);
		$this->assertSame('2048', $headers['Content-Length']);
	}//end testDownloadStreamsTheFile()

	/**
	 * A quote in the name is escaped in the plain filename and percent-encoded in the extended one.
	 *
	 * @return void
	 */
	public function testDownloadEscapesTheFileName(): void {
		$this->signIn();
		$file = $this->createMock(File::class);
		$file->method('fopen')->willReturn(fopen('php://memory', 'r'));
		$file->method('getSize')->willReturn(1);
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($file);
		$this->userFolder($folder);

		$response = $this->controller()->downloadArchiMate('my "model".xml');

		$this->assertSame(
			'attachment; filename="my \"model\".xml"; filename*=UTF-8\'\'my%20%22model%22.xml',
			$this->headers($response)['Content-Disposition']
		);
	}//end testDownloadEscapesTheFileName()

	/**
	 * A failing file lookup answers 500 and logs the file name.
	 *
	 * @return void
	 */
	public function testDownloadFailureAnswers500(): void {
		$this->signIn();
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willThrowException(new RuntimeException('storage offline'));
		$this->userFolder($folder);
		$this->logger->expects($this->once())->method('error')->with(
			'ArchiMate download failed',
			['fileName' => 'export.xml', 'error' => 'storage offline']
		);

		$response = $this->controller()->downloadArchiMate('export.xml');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Download failed: storage offline', 'error' => 'storage offline'],
			$this->data($response)
		);
	}//end testDownloadFailureAnswers500()

	/**
	 * A failing status lookup answers 500 with the error.
	 *
	 * @return void
	 */
	public function testStatusFailureAnswers500(): void {
		$this->signIn();
		$this->settingsService->method('getConfigurationStatus')->willThrowException(new RuntimeException('config unreadable'));
		$this->logger->expects($this->once())->method('error')->with(
			'SettingsController: Failed to get configuration status',
			$this->callback(static fn (array $context): bool => $context['exception_message'] === 'config unreadable')
		);

		$response = $this->controller()->status();

		$this->assertSame(500, $response->getStatus());
		$data = $this->data($response);
		$this->assertSame('config unreadable', $data['error']);
		$this->assertIsInt($data['timestamp']);
	}//end testStatusFailureAnswers500()

	/**
	 * A heartbeat echoes the client timestamp.
	 *
	 * @return void
	 */
	public function testHeartbeatEchoesTheClientTimestamp(): void {
		$this->signIn();
		$this->request->method('getParam')->with('timestamp')->willReturn(1700000000000);

		$response = $this->controller()->heartbeat();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $this->data($response);
		$this->assertTrue($data['success']);
		$this->assertSame('Heartbeat received', $data['message']);
		$this->assertSame(1700000000000, $data['timestamp']);
		$this->assertIsInt($data['server_time']);
	}//end testHeartbeatEchoesTheClientTimestamp()

	/**
	 * A failing heartbeat answers 500 with the error.
	 *
	 * @return void
	 */
	public function testHeartbeatFailureAnswers500(): void {
		$this->signIn();
		$this->request->method('getParam')->willThrowException(new RuntimeException('request broken'));

		$response = $this->controller()->heartbeat();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Heartbeat failed', 'error' => 'request broken'],
			$this->data($response)
		);
	}//end testHeartbeatFailureAnswers500()

	/**
	 * A failing progress lookup answers 500 with the error and logs the operation.
	 *
	 * @return void
	 */
	public function testProgressFailureAnswers500(): void {
		$this->signIn();
		$this->progressTracker->method('getProgress')->willThrowException(new RuntimeException('cache down'));
		$this->logger->expects($this->once())->method('error')->with(
			'Failed to get progress',
			['operation_id' => 'op-1', 'error' => 'cache down']
		);

		$response = $this->controller()->getProgress('op-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Failed to get progress: cache down', 'error' => 'cache down'],
			$this->data($response)
		);
	}//end testProgressFailureAnswers500()

	/**
	 * A failing organisation sync answers 500 with the error.
	 *
	 * @return void
	 */
	public function testOrganisationSyncFailureAnswers500(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['batch_size' => '50', 'dry_run' => 'true']);
		$this->settingsService->expects($this->once())->method('syncOrganisationsToVoorzieningenOptimized')
			->with(['batch_size' => 50, 'dry_run' => true])
			->willThrowException(new RuntimeException('register locked'));

		$response = $this->controller()->syncOrganisations();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			[
				'success' => false,
				'message' => 'Organisation sync failed: register locked',
				'error' => 'register locked',
			],
			$this->data($response)
		);
	}//end testOrganisationSyncFailureAnswers500()
}//end class
