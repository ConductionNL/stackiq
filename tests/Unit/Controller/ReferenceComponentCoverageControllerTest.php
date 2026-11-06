<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ReferenceComponentCoverageController;
use OCA\Stackiq\Service\ReferenceComponentCoverageService;
use OCA\Stackiq\Service\OrganisationReportAccess;
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

	/**
	 * @param array<int,string> $groups The user's groups.
	 */
	private function controller(array $groups, string $ownOrganisation, ReferenceComponentCoverageService $service): ReferenceComponentCoverageController {
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
			userSession: $session,
			reportService: $service,
			access: new OrganisationReportAccess(groupManager: $groupManager, config: $config)
		);
	}//end controller()
}//end class
