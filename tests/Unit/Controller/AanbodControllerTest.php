<?php

/**
 * Unit tests for AanbodController::getAanbod()'s explicit authentication
 * guard.
 *
 * Covers vendor-visibility-rbac REQ-009 (schema-rbac-hardening): the
 * controller MUST explicitly reject an unauthenticated caller BEFORE
 * AanbodService::getAanbod() is ever invoked, rather than relying on the
 * service's internal getCurrentOrganisation() resolving to null as the
 * sole safeguard — the same implicit-guard anti-pattern REQ-004 already
 * eliminated on AangebodenGebruikController::getGebruiksWhereAfnemer()
 * (deny-before-grant, REQ-001).
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-must-require-authentication-explicitly-not-implicitly-req-009
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\AanbodController;
use OCA\SoftwareCatalog\Service\AanbodService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for the getAanbod() explicit auth guard.
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-must-require-authentication-explicitly-not-implicitly-req-009
 */
class AanbodControllerTest extends TestCase {

	/** @var AanbodService|MockObject */
	private AanbodService|MockObject $aanbodService;

	/** @var IUserSession|MockObject */
	private IUserSession|MockObject $userSession;

	/**
	 * Build the controller with the current mocks.
	 *
	 * @return AanbodController The controller under test.
	 */
	private function makeController(): AanbodController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->method('getParam')->willReturn(null);

		$this->aanbodService = $this->createMock(AanbodService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		return new AanbodController(
			'softwarecatalog',
			$request,
			$this->userSession,
			$this->aanbodService,
			$this->createMock(LoggerInterface::class)
		);

	}//end makeController()

	/**
	 * REQ-009: an unauthenticated caller is rejected by the controller
	 * itself — AanbodService::getAanbod() MUST NEVER be invoked.
	 *
	 * @return void
	 */
	public function testUnauthenticatedCallerIsRejectedBeforeServiceIsInvoked(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);

		$this->aanbodService->expects($this->never())->method('getAanbod');

		$response = $controller->getAanbod();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([], $data['results']);
		$this->assertSame(0, $data['total']);
		$this->assertSame('Not authenticated', $data['message']);

	}//end testUnauthenticatedCallerIsRejectedBeforeServiceIsInvoked()

	/**
	 * REQ-009: an authenticated caller still reaches the service — the
	 * guard only blocks the fully-anonymous case. The service's own "no
	 * active organisation" handling (existing behaviour, per the
	 * pre-existing docs/security/vendor-visibility-rbac.md audit entry for
	 * this route) is responsible for that narrower case.
	 *
	 * @return void
	 */
	public function testAuthenticatedCallerReachesService(): void {
		$controller = $this->makeController();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller-uid');
		$this->userSession->method('getUser')->willReturn($user);

		$this->aanbodService->expects($this->once())
			->method('getAanbod')
			->willReturn(
				[
					'results' => [],
					'total' => 0,
					'page' => 1,
					'pages' => 0,
					'limit' => 20,
					'offset' => 0,
				]
			);

		$response = $controller->getAanbod();

		$this->assertSame(200, $response->getStatus());

	}//end testAuthenticatedCallerReachesService()

}//end class
