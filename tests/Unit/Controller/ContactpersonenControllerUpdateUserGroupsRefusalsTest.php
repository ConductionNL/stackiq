<?php

/**
 * Unit tests for the refusals of ContactpersonenController::updateUserGroups().
 *
 * Asserts that a request without a session is answered 401 and a user who is
 * neither a Nextcloud administrator nor an organisation administrator is
 * answered 403, and that neither reads or changes the target user.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contactpersonen-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Controller\ContactpersonenController;
use OCA\Stackiq\Service\Contactpersoon\ContactPersonOrganisationGuard;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ContactpersonenController::class)]
class ContactpersonenControllerUpdateUserGroupsRefusalsTest extends TestCase {

	/**
	 * The user manager; a refusal never reads the target user.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The controller under test.
	 *
	 * @var ContactpersonenController
	 */
	private ContactpersonenController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->userManager  = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession  = $this->createMock(IUserSession::class);

		$this->controller = new ContactpersonenController(
			'stackiq',
			$this->createMock(IRequest::class),
			$this->createMock(SettingsService::class),
			$this->createMock(ContactPersonHandler::class),
			$this->createMock(ContactpersoonService::class),
			$this->userManager,
			$this->groupManager,
			$this->userSession,
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
			organisationService: $this->createMock(OrganisationService::class),
			organisationGuard: $this->createMock(ContactPersonOrganisationGuard::class),
		);
	}//end setUp()

	/**
	 * Without a session the request is 401 and nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheAnswerIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->groupManager->expects(self::never())->method('isAdmin');
		$this->userManager->expects(self::never())->method('get');

		$response = $this->controller->updateUserGroups(username: 'jan', groups: ['gebruik-beheerder']);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testWithoutASessionTheAnswerIs401()

	/**
	 * A user who is neither administrator nor organisation administrator gets 403.
	 *
	 * @return void
	 */
	public function testAUserWithoutAnAdminRoleIsRefused(): void {
		$caller = $this->createMock(IUser::class);
		$caller->method('getUID')->willReturn('piet');
		$this->userSession->method('getUser')->willReturn($caller);
		$this->groupManager->expects(self::once())->method('isAdmin')->with('piet')->willReturn(false);
		$this->groupManager->expects(self::exactly(2))->method('isInGroup')->willReturnMap(
			[
				['piet', 'gebruik-beheerder', false],
				['piet', 'aanbod-beheerder', false],
			]
		);
		$this->userManager->expects(self::never())->method('get');

		$response = $this->controller->updateUserGroups(username: 'jan', groups: ['gebruik-beheerder']);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Insufficient permissions'], $response->getData());
	}//end testAUserWithoutAnAdminRoleIsRefused()
}//end class
