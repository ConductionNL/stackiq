<?php

/**
 * Wire-contract tests for DashboardController's registered public endpoints.
 *
 * `dashboard#page` (url `/`) is the SPA entrypoint: every route in the app is
 * served by it, so its contract is that it renders the `index` template and
 * attaches the Content-Security-Policy the bundle needs. A regression here
 * does not fail loudly — it produces a blank app — so it is asserted directly
 * rather than inferred from a browser test.
 *
 * `dashboard#index` is the companion JSON probe and carries the same
 * deny-before-grant guard as the rest of the API surface.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/dashboard-views-api/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\DashboardController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for dashboard#page and dashboard#index.
 */
class DashboardControllerContractTest extends TestCase {

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * Build the controller under test with fresh mocks.
	 *
	 * @return DashboardController The controller under test.
	 */
	private function makeController(): DashboardController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$this->userSession = $this->createMock(IUserSession::class);

		return new DashboardController(
			'stackiq',
			$request,
			$this->userSession
		);

	}//end makeController()

	/**
	 * GET / renders the `index` template of this app — the single entrypoint
	 * every SPA route is served from.
	 *
	 * @return void
	 */
	public function testPageRendersTheAppIndexTemplate(): void {
		$controller = $this->makeController();

		$response = $controller->page(null);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('index', $response->getTemplateName());
		$this->assertSame('stackiq', $response->getApp());
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testPageRendersTheAppIndexTemplate()

	/**
	 * GET / attaches a Content-Security-Policy that permits the outbound
	 * connections the bundle makes. Without it the SPA boots and then fails
	 * every fetch, which reads as a product outage rather than a policy bug.
	 *
	 * @return void
	 */
	public function testPageAttachesAContentSecurityPolicyAllowingAppConnections(): void {
		$controller = $this->makeController();

		$response = $controller->page(null);
		$csp = $response->getContentSecurityPolicy();

		$this->assertNotNull($csp);
		$this->assertStringContainsString('connect-src', $csp->buildPolicy());
		$this->assertStringContainsString('*', $csp->buildPolicy());

	}//end testPageAttachesAContentSecurityPolicyAllowingAppConnections()

	/**
	 * The entrypoint does not depend on the optional query parameter — a
	 * deep-link with one renders the same template.
	 *
	 * @return void
	 */
	public function testPageIgnoresTheOptionalQueryParameter(): void {
		$controller = $this->makeController();

		$response = $controller->page('anything');

		$this->assertSame('index', $response->getTemplateName());

	}//end testPageIgnoresTheOptionalQueryParameter()

	/**
	 * The JSON probe rejects an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testIndexRejectsAnonymousWith401(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);

		$response = $controller->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());

	}//end testIndexRejectsAnonymousWith401()

	/**
	 * The JSON probe answers an authenticated caller with the documented
	 * `{results: []}` envelope.
	 *
	 * @return void
	 */
	public function testIndexReturnsTheResultsEnvelopeForAnAuthenticatedCaller(): void {
		$controller = $this->makeController();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$response = $controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => []], $response->getData());

	}//end testIndexReturnsTheResultsEnvelopeForAnAuthenticatedCaller()
}//end class
