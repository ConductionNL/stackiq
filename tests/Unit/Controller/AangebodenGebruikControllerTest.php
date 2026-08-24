<?php

/**
 * Unit tests for AangebodenGebruikController::getGebruiksWhereAfnemer()'s
 * explicit authentication guard.
 *
 * Covers vendor-visibility-rbac REQ-004: the controller MUST explicitly
 * reject an unauthenticated caller BEFORE
 * AangebodenGebruikService::getGebruiksWhereAfnemer() is ever invoked,
 * rather than relying on the service's internal getCurrentOrganisation()
 * resolving to null as the sole safeguard (deny-before-grant, REQ-001).
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-the-offered-usage-afnemer-endpoint-must-require-authentication-explicitly-not-implicitly-req-004
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\AangebodenGebruikController;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for the getGebruiksWhereAfnemer() explicit auth guard.
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-the-offered-usage-afnemer-endpoint-must-require-authentication-explicitly-not-implicitly-req-004
 */
class AangebodenGebruikControllerTest extends TestCase {

	/** @var AangebodenGebruikService|MockObject */
	private AangebodenGebruikService|MockObject $gebruikSvc;

	/** @var IUserSession|MockObject */
	private IUserSession|MockObject $userSession;

	/**
	 * Build the controller with the current mocks.
	 *
	 * @return AangebodenGebruikController The controller under test.
	 */
	private function makeController(): AangebodenGebruikController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$this->gebruikSvc = $this->createMock(AangebodenGebruikService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);

		return new AangebodenGebruikController(
			'stackiq',
			$request,
			$this->userSession,
			$this->gebruikSvc,
			$this->createMock(LoggerInterface::class),
			$groupManager
		);

	}//end makeController()

	/**
	 * REQ-004 / TC-9: an unauthenticated caller is rejected by the
	 * controller itself — AangebodenGebruikService::getGebruiksWhereAfnemer()
	 * MUST NEVER be invoked.
	 *
	 * @return void
	 */
	public function testUnauthenticatedCallerIsRejectedBeforeServiceIsInvoked(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);

		$this->gebruikSvc->expects($this->never())->method('getGebruiksWhereAfnemer');

		$response = $controller->getGebruiksWhereAfnemer();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([], $data['results']);
		$this->assertSame(0, $data['total']);

	}//end testUnauthenticatedCallerIsRejectedBeforeServiceIsInvoked()

	/**
	 * REQ-004 / TC-10: an authenticated caller still reaches the service —
	 * the guard only blocks the fully-anonymous case. The service's own
	 * "no active organisation" handling (existing behaviour) is
	 * responsible for that narrower case.
	 *
	 * @return void
	 */
	public function testAuthenticatedCallerReachesService(): void {
		$controller = $this->makeController();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller-uid');
		$this->userSession->method('getUser')->willReturn($user);

		$this->gebruikSvc->expects($this->once())
			->method('getGebruiksWhereAfnemer')
			->willReturn(
				[
					'results' => [],
					'total' => 0,
					'page' => 1,
					'pages' => 0,
					'limit' => 20,
					'offset' => 0,
					'message' => 'No current organization available',
				]
			);

		$response = $controller->getGebruiksWhereAfnemer();

		$this->assertSame(200, $response->getStatus());

	}//end testAuthenticatedCallerReachesService()

}//end class
