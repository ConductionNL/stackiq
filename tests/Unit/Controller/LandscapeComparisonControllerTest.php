<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use DomainException;
use Exception;
use InvalidArgumentException;
use OCA\Stackiq\Controller\LandscapeComparisonController;
use OCA\Stackiq\Controller\OrganisationReportRequest;
use OCA\Stackiq\Kernel\Contracts\LandscapeComparisonServiceInterface;
use OCA\Stackiq\Service\OrganisationReportAccess;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * REQ-FSS-002: the comparison is organisation-scoped and refused before the service runs.
 */
final class LandscapeComparisonControllerTest extends TestCase {
	public function testAnotherOrganisationIsRefusedBeforeTheServiceRuns(): void {
		$service = $this->createMock(LandscapeComparisonServiceInterface::class);
		$service->expects($this->never())->method('compare');

		$response = $this->controller(groups: ['gebruik-beheerder'], ownOrganisation: 'alblasserdam', service: $service)->index();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Not authorised for this organisation'], $response->getData());
	}//end testAnotherOrganisationIsRefusedBeforeTheServiceRuns()

	public function testWithoutASignedInUserNothingRuns(): void {
		$service = $this->createMock(LandscapeComparisonServiceInterface::class);
		$service->expects($this->never())->method('compare');

		$response = $this->controller(groups: [], ownOrganisation: 'zuiddrecht', service: $service, signedIn: false)->index();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testWithoutASignedInUserNothingRuns()

	public function testWithoutAnOrganisationNothingRuns(): void {
		$service = $this->createMock(LandscapeComparisonServiceInterface::class);
		$service->expects($this->never())->method('compare');

		$response = $this->controller(groups: ['ambtenaar'], ownOrganisation: '', service: $service, params: ['date' => '2027-06-30'])->index();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testWithoutAnOrganisationNothingRuns()

	public function testOwnOrganisationGetsTheComparisonForTheDateAndScenario(): void {
		$answer = ['organisation' => 'zuiddrecht', 'applications' => [['moduleName' => 'Zaaksysteem A', 'change' => 'removed', 'source' => 'plan']]];
		$service = $this->createMock(LandscapeComparisonServiceInterface::class);
		$service->expects($this->once())->method('compare')->with('zuiddrecht', '2027-06-30', 's1')->willReturn($answer);

		$response = $this->controller(groups: ['gebruik-beheerder'], ownOrganisation: 'zuiddrecht', service: $service, params: ['organisation' => 'zuiddrecht', 'date' => '2027-06-30', 'scenario' => 's1'])->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($answer, $response->getData());
	}//end testOwnOrganisationGetsTheComparisonForTheDateAndScenario()

	public function testWithoutAScenarioThePlanAloneIsAskedFor(): void {
		$service = $this->createMock(LandscapeComparisonServiceInterface::class);
		$service->expects($this->once())->method('compare')->with('zuiddrecht', '2027-06-30', '')->willReturn([]);

		$response = $this->controller(groups: ['ambtenaar'], ownOrganisation: '', service: $service)->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testWithoutAScenarioThePlanAloneIsAskedFor()

	/**
	 * @return array<string,array{0:Exception,1:int}>
	 */
	public static function failures(): array {
		return [
			'bad date' => [new InvalidArgumentException('date must be a date as YYYY-MM-DD'), Http::STATUS_BAD_REQUEST],
			'scenario of another organisation' => [new DomainException('The scenario belongs to another organisation'), Http::STATUS_FORBIDDEN],
			'unknown scenario' => [new OutOfBoundsException('Scenario not found'), Http::STATUS_NOT_FOUND],
			'register not configured' => [new Exception('Voorzieningen configuration not found.'), Http::STATUS_INTERNAL_SERVER_ERROR],
		];
	}//end failures()

	#[DataProvider('failures')]
	public function testAFailureAnswersItsStatus(Exception $failure, int $status): void {
		$service = $this->createMock(LandscapeComparisonServiceInterface::class);
		$service->method('compare')->willThrowException($failure);

		$response = $this->controller(groups: ['ambtenaar'], ownOrganisation: '', service: $service)->index();

		self::assertSame($status, $response->getStatus());
		self::assertContains($failure->getMessage(), $response->getData());
	}//end testAFailureAnswersItsStatus()

	/**
	 * @param array<int,string>   $groups The user's groups.
	 * @param array<string,mixed> $params The request parameters.
	 */
	private function controller(
		array $groups,
		string $ownOrganisation,
		LandscapeComparisonServiceInterface $service,
		array $params = ['organisation' => 'zuiddrecht', 'date' => '2027-06-30'],
		bool $signedIn = true,
	): LandscapeComparisonController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn(array_map(function (string $gid): IGroup {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			return $group;
		}, $groups));
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($ownOrganisation);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		return new LandscapeComparisonController(
			appName: 'stackiq',
			request: $request,
			comparison: $service,
			reportRequest: new OrganisationReportRequest(userSession: $session, request: $request, access: new OrganisationReportAccess(groupManager: $groupManager, config: $config))
		);
	}//end controller()
}//end class
