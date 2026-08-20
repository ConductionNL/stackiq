<?php

/**
 * Wire-contract tests for ViewController's three registered public endpoints.
 *
 * Gate-25 (contract-coverage) requires an automated proof of the wire contract
 * for every registered, publicly-reachable controller method. These assert the
 * three things a caller of `/api/views`, `/api/views/{viewId}` and
 * `/api/views/docs` actually depends on:
 *
 *   1. the deny-before-grant guard — an unauthenticated caller gets 401 and the
 *      ViewService is NEVER reached;
 *   2. the status mapping the controller derives from the service envelope
 *      (200 success, 404 view-missing, 500 service failure / thrown);
 *   3. the response body shape each status carries.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/dashboard-views-api/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\ViewController;
use OCA\SoftwareCatalog\Service\ViewService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for view#getAllViews, view#getView and view#getApiDocumentation.
 */
class ViewControllerContractTest extends TestCase {

	/**
	 * The mocked view service.
	 *
	 * @var ViewService|MockObject
	 */
	private ViewService|MockObject $viewService;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * Build the controller under test with fresh mocks.
	 *
	 * @param array<string,mixed> $params Query params the request reports.
	 *
	 * @return ViewController The controller under test.
	 */
	private function makeController(array $params = []): ViewController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

		$this->viewService = $this->createMock(ViewService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		return new ViewController(
			'softwarecatalog',
			$request,
			$this->viewService,
			$this->createMock(LoggerInterface::class),
			$this->userSession
		);

	}//end makeController()

	/**
	 * Mark the session as authenticated.
	 *
	 * @return void
	 */
	private function withUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

	}//end withUser()

	/**
	 * GET /api/views — an anonymous caller is rejected 401 and the service is
	 * never reached.
	 *
	 * @return void
	 */
	public function testGetAllViewsRejectsAnonymousBeforeTheServiceIsInvoked(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);
		$this->viewService->expects($this->never())->method('getAllViews');

		$response = $controller->getAllViews();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());

	}//end testGetAllViewsRejectsAnonymousBeforeTheServiceIsInvoked()

	/**
	 * GET /api/views — a successful service envelope is returned verbatim
	 * with status 200.
	 *
	 * @return void
	 */
	public function testGetAllViewsReturns200AndTheServiceEnvelopeOnSuccess(): void {
		$controller = $this->makeController();
		$this->withUser();

		$envelope = [
			'success' => true,
			'views' => [['id' => 'v-1', 'name' => 'Landscape']],
			'count' => 1,
			'enrichments_applied' => [],
		];
		$this->viewService->expects($this->once())
			->method('getAllViews')
			->willReturn($envelope);

		$response = $controller->getAllViews();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($envelope, $response->getData());

	}//end testGetAllViewsReturns200AndTheServiceEnvelopeOnSuccess()

	/**
	 * GET /api/views — a failed service envelope maps to 500 while still
	 * carrying the envelope, so a caller can read the error.
	 *
	 * @return void
	 */
	public function testGetAllViewsMapsAFailedEnvelopeTo500(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->viewService->method('getAllViews')->willReturn(
			[
				'success' => false,
				'error' => 'register unavailable',
				'views' => [],
				'count' => 0,
			]
		);

		$response = $controller->getAllViews();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertFalse($response->getData()['success']);

	}//end testGetAllViewsMapsAFailedEnvelopeTo500()

	/**
	 * GET /api/views — a thrown service exception is converted into the
	 * documented 500 error payload rather than escaping as a stack trace.
	 *
	 * @return void
	 */
	public function testGetAllViewsConvertsAThrownServiceErrorIntoThe500Payload(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->viewService->method('getAllViews')
			->willThrowException(new \RuntimeException('boom'));

		$response = $controller->getAllViews();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('boom', $data['error']);
		$this->assertSame([], $data['views']);
		$this->assertSame(0, $data['count']);

	}//end testGetAllViewsConvertsAThrownServiceErrorIntoThe500Payload()

	/**
	 * GET /api/views/{viewId} — an anonymous caller is rejected 401 and the
	 * service is never reached.
	 *
	 * @return void
	 */
	public function testGetViewRejectsAnonymousBeforeTheServiceIsInvoked(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);
		$this->viewService->expects($this->never())->method('getView');

		$response = $controller->getView('view-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());

	}//end testGetViewRejectsAnonymousBeforeTheServiceIsInvoked()

	/**
	 * GET /api/views/{viewId} — an empty id is a 400 client error, decided by
	 * the controller without calling the service.
	 *
	 * @return void
	 */
	public function testGetViewRejectsAnEmptyIdWith400(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->viewService->expects($this->never())->method('getView');

		$response = $controller->getView('');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertNull($data['view']);

	}//end testGetViewRejectsAnEmptyIdWith400()

	/**
	 * GET /api/views/{viewId} — the requested id is passed through to the
	 * service and a successful envelope returns 200.
	 *
	 * @return void
	 */
	public function testGetViewPassesTheIdThroughAndReturns200OnSuccess(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->viewService->expects($this->once())
			->method('getView')
			->with('view-42', $this->isType('array'))
			->willReturn(
				[
					'success' => true,
					'view' => ['id' => 'view-42'],
				]
			);

		$response = $controller->getView('view-42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('view-42', $response->getData()['view']['id']);

	}//end testGetViewPassesTheIdThroughAndReturns200OnSuccess()

	/**
	 * GET /api/views/{viewId} — a missing view is 404, not 500. The
	 * distinction is the whole point of `determineViewStatusCode()`.
	 *
	 * @return void
	 */
	public function testGetViewMapsAMissingViewTo404(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->viewService->method('getView')->willReturn(
			[
				'success' => false,
				'view' => null,
				'error' => 'not found',
			]
		);

		$response = $controller->getView('nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testGetViewMapsAMissingViewTo404()

	/**
	 * GET /api/views/docs — an anonymous caller is rejected 401.
	 *
	 * @return void
	 */
	public function testGetApiDocumentationRejectsAnonymous(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);

		$response = $controller->getApiDocumentation();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());

	}//end testGetApiDocumentationRejectsAnonymous()

	/**
	 * GET /api/views/docs — the documentation payload describes the two view
	 * endpoints this controller actually registers, so the docs cannot drift
	 * silently away from the routes.
	 *
	 * @return void
	 */
	public function testGetApiDocumentationDescribesTheRegisteredViewEndpoints(): void {
		$controller = $this->makeController();
		$this->withUser();

		$response = $controller->getApiDocumentation();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('/api/views', $data['base_url']);

		$paths = array_column($data['endpoints'], 'path');
		$this->assertContains('/api/views', $paths);
		$this->assertContains('/api/views/{viewId}', $paths);

	}//end testGetApiDocumentationDescribesTheRegisteredViewEndpoints()
}//end class
