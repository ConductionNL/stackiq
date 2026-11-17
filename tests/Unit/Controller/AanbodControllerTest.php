<?php

/**
 * Unit tests for AanbodController::getAanbod()'s explicit anonymous branch.
 *
 * Covers vendor-visibility-rbac REQ-009 as decision 392 rewrote it: the
 * controller decides what an unauthenticated caller gets BEFORE
 * AanbodService::getAanbod() is ever invoked, rather than relying on the
 * service's internal getCurrentOrganisation() resolving to null as the
 * sole safeguard (deny-before-grant, REQ-001). An anonymous caller reads the
 * published entries only, in the open-data projection.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\AanbodController;
use OCA\Stackiq\Service\AanbodService;
use OCA\Stackiq\Service\PublishedAanbod;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Test class for the getAanbod() explicit auth guard.
 *
 * @spec openspec/changes/anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
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
	private function makeController(?PublishedAanbod $published = null): AanbodController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->method('getParam')->willReturn(null);

		$this->aanbodService = $this->createMock(AanbodService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		return new AanbodController(
			'stackiq',
			$request,
			$this->userSession,
			$this->aanbodService,
			$this->createMock(LoggerInterface::class),
			$published
		);

	}//end makeController()

	/**
	 * A real PublishedAanbod over an ObjectService double that holds one
	 * published module, one unpublished module and one withdrawn one.
	 *
	 * @param array<int, array<string, mixed>> $queries Collects the searches.
	 * @param array<int, bool>                 $rbac    Collects the _rbac flags.
	 *
	 * @return PublishedAanbod
	 */
	private function publishedAanbod(array &$queries, array &$rbac): PublishedAanbod {
		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => 7, 'module_schema' => 11]);

		$objectService = new class($queries, $rbac) {
			/**
			 * @param array<int, array<string, mixed>> $queries
			 * @param array<int, bool>                 $rbac
			 */
			public function __construct(private array &$queries, private array &$rbac) {
			}

			/**
			 * @param array<string, mixed> $query
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = $query;
				$this->rbac[] = $_rbac;

				return ['results' => [
					[
						'id' => 'zaaksysteem-x',
						'name' => 'Zaaksysteem X',
						'publicationDate' => '2020-01-01T00:00:00+00:00',
						'@self' => ['id' => 'zaaksysteem-x', 'organisation' => 'org-1', 'owner' => 'alice'],
					],
					['id' => 'concept-y', 'name' => 'Concept Y', '@self' => ['organisation' => 'org-2']],
					[
						'id' => 'withdrawn-z',
						'name' => 'Withdrawn Z',
						'publicationDate' => '2020-01-01T00:00:00+00:00',
						'depublicationDate' => '2021-01-01T00:00:00+00:00',
					],
				]];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

		return new PublishedAanbod(settingsService: $settings, container: $container, logger: new NullLogger());

	}//end publishedAanbod()

	/**
	 * REQ-009: an anonymous caller lists the published entry only, in the
	 * open-data projection, and AanbodService::getAanbod() is never invoked.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerGetsThePublishedEntriesOnly(): void {
		$queries = [];
		$rbac = [];
		$controller = $this->makeController(published: $this->publishedAanbod(queries: $queries, rbac: $rbac));
		$this->userSession->method('getUser')->willReturn(null);

		$this->aanbodService->expects($this->never())->method('getAanbod');

		$response = $controller->getAanbod();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(1, $data['total']);
		$this->assertSame(
			[['id' => 'zaaksysteem-x', 'name' => 'Zaaksysteem X', 'publicationDate' => '2020-01-01T00:00:00+00:00', '_aanbod_type' => 'module']],
			$data['results']
		);
		$this->assertStringNotContainsString('Concept Y', json_encode($data));
		$this->assertStringNotContainsString('org-1', json_encode($data));
		$this->assertSame([true], $rbac, 'the read keeps OpenRegister RBAC on');
		$this->assertSame(['register' => 7, 'schema' => 11], $queries[0]['@self']);
		$this->assertArrayNotHasKey('provider', $queries[0], 'no organisation filter is resolved');

	}//end testAnAnonymousCallerGetsThePublishedEntriesOnly()

	/**
	 * Without the published reader the anonymous branch fails closed: 401 and
	 * AanbodService::getAanbod() is never invoked.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRejectedWhenThePublishedReaderIsMissing(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);

		$this->aanbodService->expects($this->never())->method('getAanbod');

		$response = $controller->getAanbod();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([], $data['results']);
		$this->assertSame(0, $data['total']);
		$this->assertSame('Not authenticated', $data['message']);

	}//end testAnAnonymousCallerIsRejectedWhenThePublishedReaderIsMissing()

	/**
	 * An authenticated caller with no active organisation gets the empty
	 * envelope from the service, and the published reader is never used.
	 *
	 * @return void
	 */
	public function testAnAuthenticatedCallerWithNoOrganisationGetsTheEmptyEnvelope(): void {
		$queries = [];
		$rbac = [];
		$controller = $this->makeController(published: $this->publishedAanbod(queries: $queries, rbac: $rbac));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller-uid');
		$this->userSession->method('getUser')->willReturn($user);

		$empty = ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'limit' => 20, 'offset' => 0, 'message' => 'No current organization available'];
		$this->aanbodService->expects($this->once())->method('getAanbod')->willReturn($empty);

		$response = $controller->getAanbod();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($empty, $response->getData());
		$this->assertSame([], $queries, 'the published reader is not used for a logged-in caller');

	}//end testAnAuthenticatedCallerWithNoOrganisationGetsTheEmptyEnvelope()

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
