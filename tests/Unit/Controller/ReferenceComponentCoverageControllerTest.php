<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ReferenceComponentCoverageController;
use OCA\Stackiq\Controller\OrganisationReportRequest;
use OCA\Stackiq\Service\ReferenceComponentCoverageService;
use OCA\Stackiq\Service\OrganisationReportAccess;
use OCA\Stackiq\Service\PeerUsageService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RCC-001: the coverage is organisation-scoped and refused before any query.
 */
final class ReferenceComponentCoverageControllerTest extends TestCase {
	public function testAnotherOrganisationIsRefusedBeforeAnyQuery(): void {
		$service = $this->createMock(ReferenceComponentCoverageService::class);
		$service->expects($this->never())->method('build');

		$response = $this->controller(groups: ['gebruik-beheerder'], ownOrganisation: 'alblasserdam', service: $service)->index();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnotherOrganisationIsRefusedBeforeAnyQuery()

	public function testOwnOrganisationGetsTheReport(): void {
		$service = $this->createMock(ReferenceComponentCoverageService::class);
		$service->expects($this->once())->method('build')->with('zuiddrecht')->willReturn(['summary' => ['overlap' => 1]]);

		$response = $this->controller(groups: ['gebruik-beheerder'], ownOrganisation: 'zuiddrecht', service: $service)->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(1, $response->getData()['summary']['overlap']);
	}//end testOwnOrganisationGetsTheReport()

	public function testACivilServantMayReadAnyOrganisation(): void {
		$service = $this->createMock(ReferenceComponentCoverageService::class);
		$service->expects($this->once())->method('build')->willReturn([]);

		$response = $this->controller(groups: ['ambtenaar'], ownOrganisation: '', service: $service)->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testACivilServantMayReadAnyOrganisation()

	public function testPeersOfAnotherOrganisationAreRefusedBeforeAnyQuery(): void {
		$peers = $this->createMock(PeerUsageService::class);
		$peers->expects($this->never())->method('build');

		$response = $this->controller(groups: ['gebruik-beheerder'], ownOrganisation: 'alblasserdam', service: $this->createMock(ReferenceComponentCoverageService::class), peers: $peers)->peers();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testPeersOfAnotherOrganisationAreRefusedBeforeAnyQuery()

	public function testAMemberGetsThePeerCountsOfTheirOrganisation(): void {
		$answer = ['organisation' => 'zuiddrecht', 'components' => [['referenceComponent' => 'zac', 'applications' => [['moduleId' => 'm', 'moduleName' => 'OpenZaak-UI', 'count' => 21]]]]];
		$peers = $this->createMock(PeerUsageService::class);
		$peers->expects($this->once())->method('build')->with('zuiddrecht')->willReturn($answer);

		$response = $this->controller(groups: ['gebruik-beheerder'], ownOrganisation: 'zuiddrecht', service: $this->createMock(ReferenceComponentCoverageService::class), peers: $peers)->peers();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($answer, $response->getData());
	}//end testAMemberGetsThePeerCountsOfTheirOrganisation()

	public function testPeersWithoutTheServiceOrOnAFailureAnswerAnError(): void {
		$service = $this->createMock(ReferenceComponentCoverageService::class);
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller(groups: ['ambtenaar'], ownOrganisation: '', service: $service)->peers()->getStatus());

		$peers = $this->createMock(PeerUsageService::class);
		$peers->method('build')->willThrowException(new \Exception('Voorzieningen configuration not found.'));
		$response = $this->controller(groups: ['ambtenaar'], ownOrganisation: '', service: $service, peers: $peers)->peers();
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}//end testPeersWithoutTheServiceOrOnAFailureAnswerAnError()

	/**
	 * @param array<int,string> $groups The user's groups.
	 */
	private function controller(array $groups, string $ownOrganisation, ReferenceComponentCoverageService $service, ?PeerUsageService $peers = null): ReferenceComponentCoverageController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn(array_map(function (string $gid): IGroup {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			return $group;
		}, $groups));
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($ownOrganisation);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ['organisation' => 'zuiddrecht'][$key] ?? $default);

		return new ReferenceComponentCoverageController(
			appName: 'stackiq',
			request: $request,
			reportService: $service,
			reportRequest: new OrganisationReportRequest(userSession: $session, request: $request, access: new OrganisationReportAccess(groupManager: $groupManager, config: $config)),
			peerUsage: $peers
		);
	}//end controller()
}//end class
