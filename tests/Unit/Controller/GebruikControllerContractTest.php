<?php

/**
 * Wire-contract tests for GebruikController's two registered public endpoints.
 *
 * `GET /api/gebruik` is a `@PublicPage` route, so its contract is the most
 * security-relevant in this controller: an anonymous or role-less caller MUST
 * receive the documented empty envelope and the RBAC-bypassing
 * `GebruikService::getGebruiken()` query MUST NOT be issued at all
 * (deny-before-grant, vendor-visibility-rbac REQ-001). A `gebruik-beheerder`
 * MUST be narrowed to their own organisation before the query is issued
 * (REQ-003) and MUST NOT be able to widen it by supplying somebody else's
 * `afnemer`.
 *
 * `GET /api/gebruik/deelnemer` is `@NoAdminRequired`: 401 for anonymous, and
 * the caller's own organisation is forced into `deelnemers` regardless of what
 * the query string asked for.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/vendor-visibility-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\GebruikController;
use OCA\SoftwareCatalog\Service\GebruikService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for gebruik#getGebruiken and gebruik#getGebruikenForDeelnemer.
 */
class GebruikControllerContractTest extends TestCase {

	/**
	 * The mocked gebruik service.
	 *
	 * @var GebruikService|MockObject
	 */
	private GebruikService|MockObject $gebruikService;

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
	 * The mocked config.
	 *
	 * @var IConfig|MockObject
	 */
	private IConfig|MockObject $config;

	/**
	 * Build the controller under test with fresh mocks.
	 *
	 * @param array<string,mixed> $params Query params the request reports.
	 *
	 * @return GebruikController The controller under test.
	 */
	private function makeController(array $params = []): GebruikController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$this->gebruikService = $this->createMock(GebruikService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(IConfig::class);

		return new GebruikController(
			'softwarecatalog',
			$request,
			$this->userSession,
			$this->groupManager,
			$this->config,
			$this->gebruikService
		);

	}//end makeController()

	/**
	 * Authenticate the session as a user in the given groups, belonging to the
	 * given organisation.
	 *
	 * @param array<int,string> $groups The group ids the user is a member of.
	 * @param string $orgUuid The organisation uuid on the account.
	 *
	 * @return void
	 */
	private function withUserInGroups(array $groups, string $orgUuid = ''): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$groupMocks = [];
		foreach ($groups as $gid) {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$groupMocks[] = $group;
		}

		$this->groupManager->method('getUserGroups')->willReturn($groupMocks);
		$this->config->method('getUserValue')->willReturn($orgUuid);

	}//end withUserInGroups()

	/**
	 * The documented empty envelope shape, asserted once so every "denied"
	 * branch below proves it returns the SAME shape a caller can parse.
	 *
	 * @param array<string,mixed> $data The response payload.
	 *
	 * @return void
	 */
	private function assertEmptyEnvelope(array $data): void {
		$this->assertSame([], $data['results']);
		$this->assertSame(0, $data['total']);
		$this->assertSame(0, $data['pages']);
		$this->assertFalse($data['@self']['rbac']);

	}//end assertEmptyEnvelope()

	/**
	 * GET /api/gebruik is a PublicPage: an anonymous caller gets 200 with the
	 * empty envelope, and the RBAC-bypassing service query is NEVER issued.
	 *
	 * @return void
	 */
	public function testAnonymousGetsTheEmptyEnvelopeAndNeverReachesTheService(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);
		$this->gebruikService->expects($this->never())->method('getGebruiken');

		$response = $controller->getGebruiken();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertEmptyEnvelope($response->getData());

	}//end testAnonymousGetsTheEmptyEnvelopeAndNeverReachesTheService()

	/**
	 * An authenticated user in none of the catalogue roles is denied the same
	 * way — empty envelope, service never invoked.
	 *
	 * @return void
	 */
	public function testARolelessUserIsDeniedBeforeTheServiceIsInvoked(): void {
		$controller = $this->makeController();
		$this->withUserInGroups(['users']);
		$this->gebruikService->expects($this->never())->method('getGebruiken');

		$response = $controller->getGebruiken();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertEmptyEnvelope($response->getData());

	}//end testARolelessUserIsDeniedBeforeTheServiceIsInvoked()

	/**
	 * REQ-003: a `gebruik-beheerder` read is narrowed to the caller's own
	 * organisation BEFORE the bypass query is issued.
	 *
	 * @return void
	 */
	public function testGebruikBeheerderIsScopedToTheirOwnOrganisation(): void {
		$controller = $this->makeController();
		$this->withUserInGroups(['gebruik-beheerder'], 'org-alice');

		$this->gebruikService->expects($this->once())
			->method('getGebruiken')
			->with($this->callback(
				static function (array $options): bool {
					return ($options['afnemer'] ?? null) === 'org-alice';
				}
			))
			->willReturn(['results' => [], 'total' => 0]);

		$response = $controller->getGebruiken();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testGebruikBeheerderIsScopedToTheirOwnOrganisation()

	/**
	 * REQ-003: a `gebruik-beheerder` asking for ANOTHER organisation's afnemer
	 * is denied outright rather than silently widened or silently narrowed —
	 * the query is never issued.
	 *
	 * @return void
	 */
	public function testGebruikBeheerderCannotReadAnotherOrganisationsAfnemer(): void {
		$controller = $this->makeController(['afnemer' => 'org-bob']);
		$this->withUserInGroups(['gebruik-beheerder'], 'org-alice');

		$this->gebruikService->expects($this->never())->method('getGebruiken');

		$response = $controller->getGebruiken();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertEmptyEnvelope($response->getData());

	}//end testGebruikBeheerderCannotReadAnotherOrganisationsAfnemer()

	/**
	 * An `ambtenaar` keeps the unrestricted read: options reach the service
	 * without an injected `afnemer` narrowing.
	 *
	 * @return void
	 */
	public function testAmbtenaarRetainsTheUnrestrictedRead(): void {
		$controller = $this->makeController(['limit' => 10]);
		$this->withUserInGroups(['ambtenaar'], 'org-alice');

		$this->gebruikService->expects($this->once())
			->method('getGebruiken')
			->with($this->callback(
				static function (array $options): bool {
					return (array_key_exists('afnemer', $options) === false
						&& ($options['limit'] ?? null) === 10);
				}
			))
			->willReturn(['results' => [], 'total' => 0]);

		$controller->getGebruiken();

	}//end testAmbtenaarRetainsTheUnrestrictedRead()

	/**
	 * A service failure is reported as a 500 with the error message, not as an
	 * uncaught exception.
	 *
	 * @return void
	 */
	public function testAServiceFailureIsReportedAs500(): void {
		$controller = $this->makeController();
		$this->withUserInGroups(['admin'], 'org-alice');

		$this->gebruikService->method('getGebruiken')
			->willThrowException(new \Exception('register down'));

		$response = $controller->getGebruiken();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'register down'], $response->getData());

	}//end testAServiceFailureIsReportedAs500()

	/**
	 * GET /api/gebruik/deelnemer rejects an anonymous caller with 401 and
	 * never reaches the service.
	 *
	 * @return void
	 */
	public function testDeelnemerEndpointRejectsAnonymousWith401(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);
		$this->gebruikService->expects($this->never())->method('getGebruiken');

		$response = $controller->getGebruikenForDeelnemer();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());

	}//end testDeelnemerEndpointRejectsAnonymousWith401()

	/**
	 * GET /api/gebruik/deelnemer forces the caller's OWN organisation into
	 * `deelnemers`, overriding whatever the query string supplied.
	 *
	 * @return void
	 */
	public function testDeelnemerEndpointForcesTheCallersOwnOrganisation(): void {
		$controller = $this->makeController(['deelnemers' => ['org-bob']]);
		$this->withUserInGroups([], 'org-alice');

		$this->gebruikService->expects($this->once())
			->method('getGebruiken')
			->with($this->callback(
				static function (array $options): bool {
					return ($options['deelnemers'] ?? null) === ['org-alice'];
				}
			))
			->willReturn(['results' => [], 'total' => 0]);

		$response = $controller->getGebruikenForDeelnemer();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testDeelnemerEndpointForcesTheCallersOwnOrganisation()
}//end class
