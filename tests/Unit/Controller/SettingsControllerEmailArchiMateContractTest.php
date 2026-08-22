<?php

/**
 * Wire-contract tests for the email and ArchiMate half of SettingsController's
 * registered public endpoints.
 *
 * Two contracts here are security-load-bearing and are asserted directly
 * rather than inferred:
 *
 *   * `GET /api/email/config` carries an ADMIN guard on top of the
 *     authentication guard. It used to be plain `@NoAdminRequired` and
 *     returned the SMTP password and provider API keys in plaintext to any
 *     authenticated user (see the method's own docblock). A test that only
 *     asserted "200 for a logged-in user" would have passed on the broken
 *     version, so the non-admin 403 is pinned explicitly.
 *   * `GET /api/archimate/download/{fileName}` rejects path traversal BEFORE
 *     it resolves anything on the filesystem — the guard is asserted by
 *     proving the DI container is never consulted.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
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
 * Contract tests for the settings email + ArchiMate endpoints.
 */
class SettingsControllerEmailArchiMateContractTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService|MockObject $settingsService;

	/**
	 * The mocked ArchiMate service.
	 *
	 * @var ArchiMateService|MockObject
	 */
	private ArchiMateService|MockObject $archiMateService;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * The mocked group manager.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager|MockObject $groupManager;

	/**
	 * The mocked DI container.
	 *
	 * @var ContainerInterface|MockObject
	 */
	private ContainerInterface|MockObject $container;

	/**
	 * Build the controller under test with fresh mocks.
	 *
	 * @param array<string,mixed> $params Query/body params the request reports.
	 *
	 * @return SettingsController The controller under test.
	 */
	private function makeController(array $params = []): SettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

		$this->settingsService = $this->createMock(SettingsService::class);
		$this->archiMateService = $this->createMock(ArchiMateService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->container = $this->createMock(ContainerInterface::class);

		return new SettingsController(
			'stackiq',
			$request,
			$this->createMock(IAppConfig::class),
			$this->container,
			$this->createMock(IAppManager::class),
			$this->groupManager,
			$this->userSession,
			$this->settingsService,
			$this->createMock(OrganizationSyncService::class),
			$this->archiMateService,
			$this->createMock(ProgressTracker::class),
			$this->createMock(EolSyncService::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end makeController()

	/**
	 * Authenticate the session as the given uid.
	 *
	 * @param string $uid The uid to report.
	 * @param bool $isAdmin Whether the group manager reports them as admin.
	 *
	 * @return void
	 */
	private function withUser(string $uid = 'alice', bool $isAdmin = false): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);

	}//end withUser()

	/**
	 * Every endpoint in this file answers an anonymous caller with the same
	 * 401 envelope.
	 *
	 * @return array<string, array{0: string, 1: array<int,mixed>}>
	 */
	public static function anonymousEndpointProvider(): array {
		return [
			'getEmailConfig' => ['getEmailConfig', []],
			'getEmailTemplates' => ['getEmailTemplates', []],
			'getEmailTemplate' => ['getEmailTemplate', ['welcome']],
			'updateEmailTemplate' => ['updateEmailTemplate', ['welcome']],
			'getEmailTemplateDefault' => ['getEmailTemplateDefault', ['welcome']],
			'getEmailTemplateVariables' => ['getEmailTemplateVariables', ['welcome']],
			'testEmailConnection' => ['testEmailConnection', []],
			'getArchiMateSettings' => ['getArchiMateSettings', []],
			'getArchiMateConfig' => ['getArchiMateConfig', []],
			'testArchiMateRoundTrip' => ['testArchiMateRoundTrip', []],
			'downloadArchiMate' => ['downloadArchiMate', ['model.xml']],
		];

	}//end anonymousEndpointProvider()

	/**
	 * An anonymous caller is rejected with the 401 envelope, and neither the
	 * settings service nor the ArchiMate service is consulted first.
	 *
	 * @param string $method The controller method name.
	 * @param array<int,mixed> $args Positional arguments for the call.
	 *
	 * @return void
	 *
	 * @dataProvider anonymousEndpointProvider
	 */
	public function testAnonymousCallerIsRejectedWith401(string $method, array $args): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);

		$this->settingsService->expects($this->never())->method($this->anything());
		$this->archiMateService->expects($this->never())->method($this->anything());
		$this->container->expects($this->never())->method('get');

		$response = $controller->$method(...$args);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());

	}//end testAnonymousCallerIsRejectedWith401()

	/**
	 * GET /api/email/config is admin-only: a plain authenticated user is
	 * refused 403 and the (secret-bearing) service read never happens.
	 *
	 * This is the regression this endpoint was fixed for — it once returned
	 * the SMTP password and provider API keys to any logged-in user.
	 *
	 * @return void
	 */
	public function testGetEmailConfigRefusesANonAdminWith403AndNeverReadsTheSecrets(): void {
		$controller = $this->makeController();
		$this->withUser('alice', false);

		$this->settingsService->expects($this->never())->method('getEmailConfigFocused');

		$response = $controller->getEmailConfig();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Admin privileges required'], $response->getData());

	}//end testGetEmailConfigRefusesANonAdminWith403AndNeverReadsTheSecrets()

	/**
	 * An admin reads the redacted email configuration.
	 *
	 * @return void
	 */
	public function testGetEmailConfigServesTheRedactedConfigToAnAdmin(): void {
		$controller = $this->makeController();
		$this->withUser('root', true);

		$this->settingsService->expects($this->once())
			->method('getEmailConfigFocused')
			->willReturn(['transportType' => 'smtp', 'hasPassword' => true]);

		$response = $controller->getEmailConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['transportType' => 'smtp', 'hasPassword' => true], $response->getData());

	}//end testGetEmailConfigServesTheRedactedConfigToAnAdmin()

	/**
	 * GET /api/email/templates lists the templates under the documented
	 * `{success, templates}` envelope.
	 *
	 * @return void
	 */
	public function testGetEmailTemplatesReturnsTheTemplateList(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->settingsService->method('getAllEmailTemplates')->willReturn(['welcome', 'invite']);

		$data = $controller->getEmailTemplates()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame(['welcome', 'invite'], $data['templates']);

	}//end testGetEmailTemplatesReturnsTheTemplateList()

	/**
	 * The three per-template read endpoints each call their OWN service read
	 * and echo the template name back, so a caller can correlate the response
	 * with the request it made.
	 *
	 * @return void
	 */
	public function testThePerTemplateReadsCallTheirOwnServiceAndEchoTheName(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->settingsService->expects($this->once())
			->method('getEmailTemplate')->with('welcome')->willReturn('<p>hi</p>');
		$this->settingsService->expects($this->once())
			->method('getDefaultEmailTemplate')->with('welcome')->willReturn('<p>default</p>');
		$this->settingsService->expects($this->once())
			->method('getEmailTemplateVariables')->with('welcome')->willReturn(['name']);

		$current = $controller->getEmailTemplate('welcome')->getData();
		$this->assertSame('<p>hi</p>', $current['template']);
		$this->assertSame('welcome', $current['templateName']);

		$default = $controller->getEmailTemplateDefault('welcome')->getData();
		$this->assertSame('<p>default</p>', $default['template']);
		$this->assertSame('welcome', $default['templateName']);

		$variables = $controller->getEmailTemplateVariables('welcome')->getData();
		$this->assertSame(['name'], $variables['variables']);
		$this->assertSame('welcome', $variables['templateName']);

	}//end testThePerTemplateReadsCallTheirOwnServiceAndEchoTheName()

	/**
	 * A service failure on a template read is a 500 naming the template, not
	 * an uncaught exception.
	 *
	 * @return void
	 */
	public function testGetEmailTemplateReportsAServiceFailureAs500(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->settingsService->method('getEmailTemplate')
			->willThrowException(new \Exception('unreadable'));

		$response = $controller->getEmailTemplate('welcome');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('welcome', $response->getData()['message']);

	}//end testGetEmailTemplateReportsAServiceFailureAs500()

	/**
	 * PUT /api/email/templates/{name} refuses an empty body with 400 and does
	 * not write — an empty template would silently blank the outgoing mail.
	 *
	 * @return void
	 */
	public function testUpdateEmailTemplateRefusesAnEmptyBodyWithoutWriting(): void {
		$controller = $this->makeController([]);
		$this->withUser();

		$this->settingsService->expects($this->never())->method('updateEmailTemplate');

		$response = $controller->updateEmailTemplate('welcome');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);

	}//end testUpdateEmailTemplateRefusesAnEmptyBodyWithoutWriting()

	/**
	 * A write accepts either the `template` or the legacy `content` key and
	 * forwards the body verbatim.
	 *
	 * @return void
	 */
	public function testUpdateEmailTemplateAcceptsTheLegacyContentKey(): void {
		$controller = $this->makeController(['content' => '<p>new</p>']);
		$this->withUser();

		$this->settingsService->expects($this->once())
			->method('updateEmailTemplate')
			->with('welcome', '<p>new</p>')
			->willReturn(true);

		$response = $controller->updateEmailTemplate('welcome');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);

	}//end testUpdateEmailTemplateAcceptsTheLegacyContentKey()

	/**
	 * A service that reports the write did NOT land is surfaced as
	 * `success:false` rather than being swallowed into a cheerful 200 body.
	 *
	 * @return void
	 */
	public function testUpdateEmailTemplateSurfacesAFailedWrite(): void {
		$controller = $this->makeController(['template' => '<p>new</p>']);
		$this->withUser();
		$this->settingsService->method('updateEmailTemplate')->willReturn(false);

		$data = $controller->updateEmailTemplate('welcome')->getData();

		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Failed to update', $data['message']);

	}//end testUpdateEmailTemplateSurfacesAFailedWrite()

	/**
	 * POST /api/email/test-connection unwraps the `emailSettings` envelope the
	 * settings UI posts and hands it to the service.
	 *
	 * @return void
	 */
	public function testTestEmailConnectionUnwrapsTheEmailSettingsEnvelope(): void {
		$controller = $this->makeController(['emailSettings' => ['transportType' => 'smtp']]);
		$this->withUser();

		$this->settingsService->expects($this->once())
			->method('testEmailConnection')
			->with(['transportType' => 'smtp'])
			->willReturn(['success' => true, 'message' => 'connected']);

		$data = $controller->testEmailConnection()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('connected', $data['message']);
		$this->assertArrayHasKey('details', $data);

	}//end testTestEmailConnectionUnwrapsTheEmailSettingsEnvelope()

	/**
	 * A thrown connection test is reported as a 500 with a message, never as a
	 * successful "connected".
	 *
	 * @return void
	 */
	public function testTestEmailConnectionReportsAThrownTestAs500(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->settingsService->method('testEmailConnection')
			->willThrowException(new \Exception('auth rejected'));

		$response = $controller->testEmailConnection();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertFalse($response->getData()['success']);

	}//end testTestEmailConnectionReportsAThrownTestAs500()

	/**
	 * GET /api/settings/archimate wraps the service status with a timestamp.
	 *
	 * @return void
	 */
	public function testGetArchiMateSettingsWrapsTheServiceStatus(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->settingsService->method('getArchiMateStatus')->willReturn(['configured' => true]);

		$data = $controller->getArchiMateSettings()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame(['configured' => true], $data['archimate']);
		$this->assertIsInt($data['timestamp']);

	}//end testGetArchiMateSettingsWrapsTheServiceStatus()

	/**
	 * GET /api/archimate/status returns the focused config verbatim — it is a
	 * distinct payload from `/api/settings/archimate`, not an alias.
	 *
	 * @return void
	 */
	public function testGetArchiMateConfigReturnsTheConfigVerbatim(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->settingsService->method('getArchiMateConfig')->willReturn(['register' => 'voorzieningen']);

		$response = $controller->getArchiMateConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['register' => 'voorzieningen'], $response->getData());

	}//end testGetArchiMateConfigReturnsTheConfigVerbatim()

	/**
	 * POST /api/archimate/test-round-trip forwards the service verdict,
	 * including the statistics block the settings UI renders.
	 *
	 * @return void
	 */
	public function testTestArchiMateRoundTripForwardsTheServiceVerdict(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->archiMateService->expects($this->once())
			->method('testRoundTrip')
			->willReturn(
				[
					'success' => true,
					'message' => 'round trip ok',
					'statistics' => ['elements' => 12],
				]
			);

		$data = $controller->testArchiMateRoundTrip()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame(['elements' => 12], $data['statistics']);

	}//end testTestArchiMateRoundTripForwardsTheServiceVerdict()

	/**
	 * A thrown round-trip test is a 500, not a silent success.
	 *
	 * @return void
	 */
	public function testTestArchiMateRoundTripReportsAThrownTestAs500(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->archiMateService->method('testRoundTrip')
			->willThrowException(new \Exception('parser blew up'));

		$response = $controller->testArchiMateRoundTrip();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertFalse($response->getData()['success']);

	}//end testTestArchiMateRoundTripReportsAThrownTestAs500()

	/**
	 * Path-traversal filenames are refused with 400 BEFORE the download
	 * resolves anything: the DI container — which is how the user folder is
	 * reached — is never consulted.
	 *
	 * @param string $fileName A filename a caller might supply.
	 *
	 * @return void
	 *
	 * @dataProvider traversalFileNameProvider
	 */
	public function testDownloadArchiMateRefusesTraversalBeforeTouchingTheFilesystem(string $fileName): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->container->expects($this->never())->method('get');

		$response = $controller->downloadArchiMate($fileName);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('INVALID_FILENAME', $response->getData()['error']);

	}//end testDownloadArchiMateRefusesTraversalBeforeTouchingTheFilesystem()

	/**
	 * Filenames that must never resolve to a filesystem lookup.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function traversalFileNameProvider(): array {
		return [
			'parent directory' => ['../config.php'],
			'nested traversal' => ['exports/../../config/config.php'],
			'absolute path' => ['/etc/passwd'],
			'subdirectory' => ['exports/model.xml'],
			'trailing traversal' => ['model.xml/..'],
		];

	}//end traversalFileNameProvider()
}//end class
