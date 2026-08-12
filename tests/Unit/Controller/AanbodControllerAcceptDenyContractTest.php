<?php

/**
 * Wire-contract tests for AanbodController's accept/deny endpoints.
 *
 * `PUT /api/aanbod/{uuid}/accept` and `DELETE /api/aanbod/{uuid}/deny` are the
 * two mutating routes on the aanbod surface. Both are `@NoAdminRequired`, and
 * both translate a service envelope into an HTTP status the frontend branches
 * on. The mapping is the contract:
 *
 *     success                       -> 200
 *     'Aanbod object not found'     -> 404
 *     '...Operation not allowed...' -> 403   (the authorisation refusal)
 *     anything else / thrown        -> 500
 *
 * A refusal that collapsed into a 500 would be indistinguishable from a server
 * fault, and the UI would offer a retry for something that can never succeed —
 * so the 403 branch in particular is asserted directly.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/aanbod-listings/spec.md
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
 * Contract tests for aanbod#acceptAanbod and aanbod#denyAanbod.
 */
class AanbodControllerAcceptDenyContractTest extends TestCase {

	/**
	 * The mocked aanbod service.
	 *
	 * @var AanbodService|MockObject
	 */
	private AanbodService|MockObject $aanbodService;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * Build the controller under test with fresh mocks.
	 *
	 * @param array<string,mixed> $params Body params the request reports.
	 *
	 * @return AanbodController The controller under test.
	 */
	private function makeController(array $params = []): AanbodController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

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
	 * Authenticate the session.
	 *
	 * @return void
	 */
	private function withUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

	}//end withUser()

	/**
	 * Both mutating endpoints refuse an anonymous caller before the service is
	 * reached — nothing may be accepted or deleted without a session.
	 *
	 * @param string $method The controller method name.
	 *
	 * @return void
	 *
	 * @dataProvider mutatingMethodProvider
	 */
	public function testAnonymousCallerCannotMutate(string $method): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);

		$this->aanbodService->expects($this->never())->method('acceptAanbod');
		$this->aanbodService->expects($this->never())->method('denyAanbod');

		$response = $controller->$method('uuid-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());

	}//end testAnonymousCallerCannotMutate()

	/**
	 * The two mutating endpoints.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function mutatingMethodProvider(): array {
		return [
			'acceptAanbod' => ['acceptAanbod'],
			'denyAanbod' => ['denyAanbod'],
		];

	}//end mutatingMethodProvider()

	/**
	 * An empty uuid is a 400 decided by the controller, and the service is not
	 * asked to accept "everything".
	 *
	 * @param string $method The controller method name.
	 *
	 * @return void
	 *
	 * @dataProvider mutatingMethodProvider
	 */
	public function testAnEmptyUuidIsRejectedWith400(string $method): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->aanbodService->expects($this->never())->method('acceptAanbod');
		$this->aanbodService->expects($this->never())->method('denyAanbod');

		$response = $controller->$method('');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);

	}//end testAnEmptyUuidIsRejectedWith400()

	/**
	 * Accept forwards the uuid and returns 200 with the service envelope.
	 *
	 * @return void
	 */
	public function testAcceptReturns200AndTheServiceEnvelope(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->aanbodService->expects($this->once())
			->method('acceptAanbod')
			->with('uuid-1', $this->isType('array'))
			->willReturn(['success' => true, 'aanbod' => ['uuid' => 'uuid-1']]);

		$response = $controller->acceptAanbod('uuid-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);

	}//end testAcceptReturns200AndTheServiceEnvelope()

	/**
	 * The path parameter is not forwarded as a body option — a `uuid` key in
	 * the options would shadow the path value inside the service.
	 *
	 * @return void
	 */
	public function testAcceptStripsThePathParameterFromTheForwardedOptions(): void {
		$controller = $this->makeController(['uuid' => 'uuid-other', 'reason' => 'ok']);
		$this->withUser();

		$this->aanbodService->expects($this->once())
			->method('acceptAanbod')
			->with('uuid-1', ['reason' => 'ok'])
			->willReturn(['success' => true]);

		$controller->acceptAanbod('uuid-1');

	}//end testAcceptStripsThePathParameterFromTheForwardedOptions()

	/**
	 * A missing aanbod is 404, not 500.
	 *
	 * @return void
	 */
	public function testAcceptMapsAMissingAanbodTo404(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->aanbodService->method('acceptAanbod')
			->willReturn(['success' => false, 'error' => 'Aanbod object not found']);

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$controller->acceptAanbod('uuid-1')->getStatus()
		);

	}//end testAcceptMapsAMissingAanbodTo404()

	/**
	 * An authorisation refusal is 403 — distinguishable from a server fault so
	 * the UI does not offer a pointless retry.
	 *
	 * @return void
	 */
	public function testAcceptMapsAnAuthorisationRefusalTo403(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->aanbodService->method('acceptAanbod')
			->willReturn(
				[
					'success' => false,
					'error' => 'Operation not allowed: active organisation is not the aanbieder',
				]
			);

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->acceptAanbod('uuid-1')->getStatus()
		);

	}//end testAcceptMapsAnAuthorisationRefusalTo403()

	/**
	 * Any other failure envelope is a 500.
	 *
	 * @return void
	 */
	public function testAcceptMapsAnUnclassifiedFailureTo500(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->aanbodService->method('acceptAanbod')
			->willReturn(['success' => false, 'error' => 'register write failed']);

		$this->assertSame(
			Http::STATUS_INTERNAL_SERVER_ERROR,
			$controller->acceptAanbod('uuid-1')->getStatus()
		);

	}//end testAcceptMapsAnUnclassifiedFailureTo500()

	/**
	 * Deny returns 200 and reports the deletion in the envelope.
	 *
	 * @return void
	 */
	public function testDenyReturns200AndReportsTheDeletion(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->aanbodService->expects($this->once())
			->method('denyAanbod')
			->with('uuid-1', $this->isType('array'))
			->willReturn(['success' => true, 'deleted' => true]);

		$response = $controller->denyAanbod('uuid-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['deleted']);

	}//end testDenyReturns200AndReportsTheDeletion()

	/**
	 * Deny maps a refusal to 403 rather than deleting anything.
	 *
	 * @return void
	 */
	public function testDenyMapsAnAuthorisationRefusalTo403(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->aanbodService->method('denyAanbod')
			->willReturn(
				[
					'success' => false,
					'error' => 'Operation not allowed: active organisation is not the afnemer',
				]
			);

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->denyAanbod('uuid-1')->getStatus()
		);

	}//end testDenyMapsAnAuthorisationRefusalTo403()

	/**
	 * A thrown service error is converted into the documented 500 payload.
	 *
	 * @return void
	 */
	public function testDenyConvertsAThrownServiceErrorIntoThe500Payload(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->aanbodService->method('denyAanbod')
			->willThrowException(new \Exception('register down'));

		$response = $controller->denyAanbod('uuid-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('register down', $data['error']);

	}//end testDenyConvertsAThrownServiceErrorIntoThe500Payload()
}//end class
