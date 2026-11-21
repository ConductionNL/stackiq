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
	 * @param PublishedAanbod|null $published The anonymous reader, or none.
	 *
	 * @return AanbodController The controller under test.
	 */
	private function makeController(?PublishedAanbod $published = null): AanbodController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->method('getParam')->willReturn(null);

		$this->aanbodService = $this->createMock(originalClassName: AanbodService::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);

		return new AanbodController(
			appName: 'stackiq',
			request: $request,
			userSession: $this->userSession,
			aanbodService: $this->aanbodService,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			publishedAanbod: $published
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
		$settings = $this->createMock(originalClassName: RegisterConfigReaderInterface::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => 7, 'module_schema' => 11]);

		$objectService = new class($queries, $rbac) {
			/**
			 * The double that records each search.
			 *
			 * @param array<int, array<string, mixed>> $queries The searches received.
			 * @param array<int, bool>                 $rbac    The _rbac flag of each search.
			 */
			public function __construct(private array &$queries, private array &$rbac) {
			}

			/**
			 * Record the search and answer one published module.
			 *
			 * @param array<string, mixed> $query         The query.
			 * @param bool                 $_rbac         The _rbac flag.
			 * @param bool                 $_multitenancy The _multitenancy flag.
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

		$container = $this->createMock(originalClassName: ContainerInterface::class);
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

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame(expected: 1, actual: $data['total']);
		$this->assertSame(
			expected: [['id' => 'zaaksysteem-x', 'name' => 'Zaaksysteem X', 'publicationDate' => '2020-01-01T00:00:00+00:00', '_aanbod_type' => 'module']],
			actual: $data['results']
		);
		$this->assertStringNotContainsString(needle: 'Concept Y', haystack: json_encode(value: $data));
		$this->assertStringNotContainsString(needle: 'org-1', haystack: json_encode(value: $data));
		$this->assertSame(expected: [true], actual: $rbac, message: 'the read keeps OpenRegister RBAC on');
		$this->assertSame(expected: ['register' => 7, 'schema' => 11], actual: $queries[0]['@self']);
		$this->assertArrayNotHasKey(key: 'provider', array: $queries[0], message: 'no organisation filter is resolved');

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

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame(expected: [], actual: $data['results']);
		$this->assertSame(expected: 0, actual: $data['total']);
		$this->assertSame(expected: 'Not authenticated', actual: $data['message']);

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

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('caller-uid');
		$this->userSession->method('getUser')->willReturn($user);

		$empty = ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'limit' => 20, 'offset' => 0, 'message' => 'No current organization available'];
		$this->aanbodService->expects($this->once())->method('getAanbod')->willReturn($empty);

		$response = $controller->getAanbod();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: $empty, actual: $response->getData());
		$this->assertSame(expected: [], actual: $queries, message: 'the published reader is not used for a logged-in caller');

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

		$user = $this->createMock(originalClassName: IUser::class);
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

		$this->assertSame(expected: 200, actual: $response->getStatus());

	}//end testAuthenticatedCallerReachesService()

}//end class
