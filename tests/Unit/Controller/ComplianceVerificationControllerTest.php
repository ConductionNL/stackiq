<?php

/**
 * The verify endpoint passes the signed-in reviewer to the action and returns its answer.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ComplianceVerificationController;
use OCA\Stackiq\Service\ComplianceVerificationService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Covers ComplianceVerificationController.
 *
 * @covers \OCA\Stackiq\Controller\ComplianceVerificationController
 */
final class ComplianceVerificationControllerTest extends TestCase {

	/**
	 * The controller over a session with or without a user.
	 *
	 * @param ComplianceVerificationService $service The action.
	 * @param bool                          $signedIn Whether a user is signed in.
	 *
	 * @return ComplianceVerificationController
	 */
	private function controller(ComplianceVerificationService $service, bool $signedIn=true): ComplianceVerificationController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('rita');
		$user->method('getDisplayName')->willReturn('Rita Reviewer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('functioneel-beheerder');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroups')->willReturn([$group]);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->with('rita', 'core', 'organisation')->willReturn('org-g');

		return new ComplianceVerificationController(request: $this->createMock(IRequest::class), verification: $service, userSession: $session, groupManager: $groups, config: $config);
	}//end controller()

	public function testTheReviewerGroupsAndOrganisationReachTheAction(): void {
		$service = $this->createMock(ComplianceVerificationService::class);
		$service->expects($this->once())->method('verify')
			->with('c1', 'Rita Reviewer', ['functioneel-beheerder'], 'org-g', $this->matchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/'))
			->willReturn(['status' => 403, 'body' => ['message' => 'A supplier cannot verify its own claim']]);

		$response = $this->controller(service: $service)->verify(id: 'c1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'A supplier cannot verify its own claim'], $response->getData());
	}

	public function testWithoutASessionNothingIsAsked(): void {
		$service = $this->createMock(ComplianceVerificationService::class);
		$service->expects($this->never())->method('verify');

		$this->assertSame(401, $this->controller(service: $service, signedIn: false)->verify(id: 'c1')->getStatus());
	}
}
