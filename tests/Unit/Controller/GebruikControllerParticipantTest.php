<?php

/**
 * A participant's reads on the gebruik API.
 *
 * GET /api/gebruik returns a gebruik-beheerder's own usages and the usages
 * shared with its organisation, the shared ones marked; outsiders, vendors and
 * filtered or later pages get no shared usages. GET /api/gebruik/shared-connections
 * answers only an authenticated gebruik user with an organisation.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\GebruikController;
use OCA\Stackiq\Service\GebruikService;
use OCA\Stackiq\Service\RelationRefs;
use OCA\Stackiq\Service\SharedUsageScope;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Participant reads through GebruikController.
 */
#[CoversClass(GebruikController::class)]
#[UsesClass(SharedUsageScope::class)]
#[UsesClass(RelationRefs::class)]
class GebruikControllerParticipantTest extends TestCase {

	/**
	 * The mocked gebruik service.
	 *
	 * @var GebruikService&MockObject
	 */
	private GebruikService&MockObject $gebruikService;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The mocked group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The mocked config.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * Build the controller with a request that reports the given params.
	 *
	 * @param array<string,mixed> $params Query params.
	 *
	 * @return GebruikController
	 */
	private function makeController(array $params = []): GebruikController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn($params);

		$this->gebruikService = $this->createMock(originalClassName: GebruikService::class);
		$this->userSession    = $this->createMock(originalClassName: IUserSession::class);
		$this->groupManager   = $this->createMock(originalClassName: IGroupManager::class);
		$this->config         = $this->createMock(originalClassName: IConfig::class);

		return new GebruikController(
			appName: 'stackiq',
			request: $request,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			config: $this->config,
			gebruikService: $this->gebruikService
		);
	}//end makeController()

	/**
	 * Log in a user in the given groups of the given organisation.
	 *
	 * @param array<int,string> $groups  Group ids.
	 * @param string            $orgUuid Organisation uuid.
	 *
	 * @return void
	 */
	private function login(array $groups, string $orgUuid): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$mocks = [];
		foreach ($groups as $gid) {
			$group = $this->createMock(originalClassName: IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$mocks[] = $group;
		}

		$this->groupManager->method('getUserGroups')->willReturn($mocks);
		$this->config->method('getUserValue')->willReturn($orgUuid);
	}//end login()

	/**
	 * A participant's list holds its own usages and the shared ones, marked.
	 *
	 * @return void
	 */
	public function testAParticipantGetsItsOwnAndTheSharedUsages(): void {
		$controller = $this->makeController(params: ['_limit' => 20]);
		$this->login(groups: ['gebruik-beheerder'], orgUuid: 'org-alb');

		$this->gebruikService->method('getGebruiken')->willReturn(
			['results' => [['id' => 'u-own', 'consumer' => 'org-alb']], 'total' => 1]
		);
		$shared = [
			'id' => 'u-mozard',
			'consumer' => 'org-zd',
			'participants' => ['org-alb', 'org-pap'],
			'_shared' => ['cooperation' => 'org-zd', 'participantCount' => 3],
		];
		$this->gebruikService->expects($this->once())
			->method('getSharedGebruiken')
			->with(['_limit' => 20, 'consumer' => 'org-alb'], 'org-alb')
			->willReturn([$shared]);

		$response = $controller->getGebruiken();
		$data     = $response->getData();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 2, actual: $data['total']);
		$this->assertSame(expected: ['u-own', 'u-mozard'], actual: array_column($data['results'], 'id'));
		$this->assertArrayNotHasKey(key: '_shared', array: $data['results'][0]);
		$this->assertSame(expected: ['cooperation' => 'org-zd', 'participantCount' => 3], actual: $data['results'][1]['_shared']);
	}//end testAParticipantGetsItsOwnAndTheSharedUsages()

	/**
	 * Admins, vendors and requests that filter on consumer or ask a later page get no shared usages added.
	 *
	 * @return array<string, array{0: array<int,string>, 1: array<string,mixed>}>
	 */
	public static function noSharedProvider(): array {
		return [
			'admin'                => [['admin', 'gebruik-beheerder'], []],
			'ambtenaar'            => [['ambtenaar', 'gebruik-beheerder'], []],
			'vendor'               => [['aanbod-beheerder'], []],
			'own consumer filter'  => [['gebruik-beheerder'], ['consumer' => 'org-alb']],
			'second page'          => [['gebruik-beheerder'], ['_page' => 2]],
			'offset'               => [['gebruik-beheerder'], ['_offset' => 20]],
		];
	}//end noSharedProvider()

	/**
	 * See noSharedProvider().
	 *
	 * @param array<int,string>   $groups Group ids.
	 * @param array<string,mixed> $params Query params.
	 *
	 * @return void
	 *
	 * @dataProvider noSharedProvider
	 */
	public function testNoSharedUsagesAreAddedOutsideTheParticipantsFirstPage(array $groups, array $params): void {
		$controller = $this->makeController(params: $params);
		$this->login(groups: $groups, orgUuid: 'org-alb');

		$this->gebruikService->method('getApplicationIds')->willReturn(['app-1']);
		$this->gebruikService->method('getGebruiken')->willReturn(['results' => [], 'total' => 0]);
		$this->gebruikService->expects($this->never())->method('getSharedGebruiken');

		$this->assertSame(expected: 0, actual: $controller->getGebruiken()->getData()['total']);
	}//end testNoSharedUsagesAreAddedOutsideTheParticipantsFirstPage()

	/**
	 * A gebruik-beheerder without an organisation gets no shared usages.
	 *
	 * @return void
	 */
	public function testNoOrganisationMeansNoSharedUsages(): void {
		$controller = $this->makeController();
		$this->login(groups: ['gebruik-beheerder'], orgUuid: '');

		$this->gebruikService->method('getGebruiken')->willReturn(['results' => [], 'total' => 0]);
		$this->gebruikService->expects($this->never())->method('getSharedGebruiken');

		$controller->getGebruiken();
	}//end testNoOrganisationMeansNoSharedUsages()

	/**
	 * A failure of the shared read is a 500, not a partial list.
	 *
	 * @return void
	 */
	public function testASharedReadFailureIsA500(): void {
		$controller = $this->makeController();
		$this->login(groups: ['gebruik-beheerder'], orgUuid: 'org-alb');

		$this->gebruikService->method('getGebruiken')->willReturn(['results' => [], 'total' => 0]);
		$this->gebruikService->method('getSharedGebruiken')->willThrowException(new \Exception('register down'));

		$response = $controller->getGebruiken();

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'register down'], actual: $response->getData());
	}//end testASharedReadFailureIsA500()

	/**
	 * Shared connections: 401 anonymous, 403 without a gebruik role or organisation, never reaching the service.
	 *
	 * @return void
	 */
	public function testSharedConnectionsRefusesAnonymous(): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);
		$this->gebruikService->expects($this->never())->method('getSharedConnections');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->getSharedConnections()->getStatus());
	}//end testSharedConnectionsRefusesAnonymous()

	/**
	 * Cases refused with 403.
	 *
	 * @return array<string, array{0: array<int,string>, 1: string}>
	 */
	public static function refusedConnectionsProvider(): array {
		return [
			'no gebruik role'  => [['aanbod-beheerder'], 'org-alb'],
			'no organisation'  => [['gebruik-beheerder'], ''],
		];
	}//end refusedConnectionsProvider()

	/**
	 * See refusedConnectionsProvider().
	 *
	 * @param array<int,string> $groups  Group ids.
	 * @param string            $orgUuid Organisation uuid.
	 *
	 * @return void
	 *
	 * @dataProvider refusedConnectionsProvider
	 */
	public function testSharedConnectionsRefusesWithoutRoleOrOrganisation(array $groups, string $orgUuid): void {
		$controller = $this->makeController();
		$this->login(groups: $groups, orgUuid: $orgUuid);
		$this->gebruikService->expects($this->never())->method('getSharedConnections');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->getSharedConnections()->getStatus());
	}//end testSharedConnectionsRefusesWithoutRoleOrOrganisation()

	/**
	 * A reader gets the connections of its own organisation, never one it names.
	 *
	 * @return void
	 */
	public function testSharedConnectionsUsesTheCallersOrganisation(): void {
		$controller = $this->makeController(params: ['organisation' => 'org-other']);
		$this->login(groups: ['gebruik-raadpleger'], orgUuid: 'org-pap');

		$this->gebruikService->expects($this->once())
			->method('getSharedConnections')
			->with('org-pap')
			->willReturn(['c-1' => ['sharedWith' => ['org-zd'], 'usages' => ['u-1']]]);

		$response = $controller->getSharedConnections();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['connections' => ['c-1' => ['sharedWith' => ['org-zd'], 'usages' => ['u-1']]]], actual: $response->getData());
	}//end testSharedConnectionsUsesTheCallersOrganisation()
}//end class
