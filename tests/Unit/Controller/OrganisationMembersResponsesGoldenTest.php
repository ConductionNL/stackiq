<?php

/**
 * Golden tests for the OrganisationMembersController grant/revoke responses.
 *
 * Pins the exact bodies, status codes and log calls of the allowed and the
 * refused grant and revoke outcomes before the endpoints are split into
 * named steps.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/multi-org-membership/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Controller\OrganisationMembersController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for grant/revoke responses.
 */
class OrganisationMembersResponsesGoldenTest extends TestCase {

	/**
	 * Every log call as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $logs = [];

	/**
	 * The OpenRegister organisation service double.
	 *
	 * @var OrganisationService|MockObject
	 */
	private OrganisationService|MockObject $organisations;

	/**
	 * Build the controller for a beheerder of org-a (or a non-beheerder).
	 *
	 * @param bool      $maintainer Whether the caller is in the maintainer group
	 * @param IUser|null $target    What the user manager answers for the target
	 *
	 * @return OrganisationMembersController
	 */
	private function controller(bool $maintainer, ?IUser $target): OrganisationMembersController {
		$caller = $this->createMock(IUser::class);
		$caller->method('getUID')->willReturn('caller');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($caller);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn($maintainer);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($target);

		$org = new Organisation();
		$org->setUuid('org-a');
		$this->organisations = $this->createMock(OrganisationService::class);
		$this->organisations->method('getUserOrganisations')->willReturn([$org]);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new OrganisationMembersController($this->createMock(IRequest::class), $session, $groups, $users, $logger, $this->organisations);

	}//end controller()

	/**
	 * Allowed grant and revoke answer 200 with the message and the user id.
	 *
	 * @return void
	 */
	public function testAllowedOutcomes(): void {
		$controller = $this->controller(true, $this->createMock(IUser::class));
		$this->organisations->expects($this->once())->method('joinOrganisation')->with('org-a', 'col');
		$this->organisations->expects($this->once())->method('leaveOrganisation')->with('org-a', 'col');

		$granted = $controller->grant(uuid: 'org-a', userId: 'col');
		$revoked = $controller->revoke(uuid: 'org-a', userId: 'col');

		$this->assertSame([200, ['message' => 'Successfully granted organisation access', 'userId' => 'col']], [$granted->getStatus(), $granted->getData()]);
		$this->assertSame([200, ['message' => 'Successfully revoked organisation access', 'userId' => 'col']], [$revoked->getStatus(), $revoked->getData()]);
		$this->assertSame([], $this->logs);

	}//end testAllowedOutcomes()

	/**
	 * Refused: a non-beheerder never reaches OpenRegister; an unknown target user is 404 for grant only.
	 *
	 * @return void
	 */
	public function testRefusedOutcomes(): void {
		$controller = $this->controller(false, null);
		$this->organisations->expects($this->never())->method('joinOrganisation');
		$this->organisations->expects($this->never())->method('leaveOrganisation');
		$this->assertSame(403, $controller->grant(uuid: 'org-a', userId: 'col')->getStatus());
		$this->assertSame(403, $controller->revoke(uuid: 'org-a', userId: 'col')->getStatus());

		$controller = $this->controller(true, null);
		$this->organisations->expects($this->never())->method('joinOrganisation');
		$missing = $controller->grant(uuid: 'org-a', userId: 'ghost');
		$this->assertSame([404, ['error' => 'User not found']], [$missing->getStatus(), $missing->getData()]);

	}//end testRefusedOutcomes()

	/**
	 * A failing OpenRegister call answers 400 with its message and logs an error.
	 *
	 * @return void
	 */
	public function testFailuresAre400WithAnErrorLog(): void {
		$controller = $this->controller(true, $this->createMock(IUser::class));
		$this->organisations->method('joinOrganisation')->willThrowException(new \Exception('join broke'));
		$this->organisations->method('leaveOrganisation')->willThrowException(new \Exception('leave broke'));

		$granted = $controller->grant(uuid: 'org-a', userId: 'col');
		$revoked = $controller->revoke(uuid: 'org-a', userId: 'col');

		$this->assertSame([400, ['error' => 'join broke']], [$granted->getStatus(), $granted->getData()]);
		$this->assertSame([400, ['error' => 'leave broke']], [$revoked->getStatus(), $revoked->getData()]);
		$this->assertSame(
			[
				['error', '[OrganisationMembersController] Failed to grant organisation access', ['organisationUuid' => 'org-a', 'userId' => 'col', 'error' => 'join broke']],
				['error', '[OrganisationMembersController] Failed to revoke organisation access', ['organisationUuid' => 'org-a', 'userId' => 'col', 'error' => 'leave broke']],
			],
			$this->logs
		);

	}//end testFailuresAre400WithAnErrorLog()
}//end class
