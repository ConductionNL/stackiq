<?php

/**
 * Unit tests for OwnershipTransferController.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-002-only-an-administrator-of-both-organisations-moves-entries
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Controller\OwnershipTransferController;
use OCA\Stackiq\Service\OwnershipTransferService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Only an administrator of both organisations moves entries; a refusal changes nothing.
 *
 * @covers \OCA\Stackiq\Controller\OwnershipTransferController
 *
 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-002-only-an-administrator-of-both-organisations-moves-entries
 */
class OwnershipTransferControllerTest extends TestCase {

	/**
	 * The transfer service.
	 *
	 * @var OwnershipTransferService&MockObject
	 */
	private OwnershipTransferService $transfers;

	/**
	 * A transfer of one application from A to B.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [
		'objects' => [['schema' => 'module', 'id' => 'app-1']],
		'sourceOrganisation' => 'org-a',
		'targetOrganisation' => 'org-b',
	];

	/**
	 * The service, which no refused call may reach.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->transfers = $this->createMock(OwnershipTransferService::class);
	}//end setUp()

	/**
	 * An administrator of A only is refused, on plan and on execute, and nothing is read or moved.
	 *
	 * @return void
	 */
	public function testAnAdministratorOfOneSideIsRefused(): void {
		$this->transfers->expects($this->never())->method('plan');
		$this->transfers->expects($this->never())->method('execute');

		$controller = $this->controller(uid: 'beheer-a', admin: false, maintainer: true, memberOf: ['org-a']);
		foreach ([$controller->plan(), $controller->execute()] as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame(['message' => 'Only an administrator of both organisations may move entries'], $response->getData());
		}
	}//end testAnAdministratorOfOneSideIsRefused()

	/**
	 * A member of both who is not an organisation administrator is refused too.
	 *
	 * @return void
	 */
	public function testAMemberOfBothWithoutTheAdministratorRoleIsRefused(): void {
		$this->transfers->expects($this->never())->method('execute');

		$response = $this->controller(uid: 'lid', admin: false, maintainer: false, memberOf: ['org-a', 'org-b'])->execute();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAMemberOfBothWithoutTheAdministratorRoleIsRefused()

	/**
	 * An administrator of both organisations, and a Nextcloud administrator, may move.
	 *
	 * @return void
	 */
	public function testAnAdministratorOfBothOrANextcloudAdministratorMoves(): void {
		$this->transfers->expects($this->exactly(2))->method('execute')
			->with([['schema' => 'module', 'id' => 'app-1']], 'org-a', 'org-b')
			->willReturn(['moved' => 1]);
		$this->transfers->expects($this->once())->method('plan')->willReturn(['items' => []]);

		$this->assertSame(['moved' => 1], $this->controller(uid: 'beheer-ab', admin: false, maintainer: true, memberOf: ['org-b', 'org-a'])->execute()->getData());
		$this->assertSame(['moved' => 1], $this->controller(uid: 'admin', admin: true, maintainer: false, memberOf: [])->execute()->getData());
		$this->assertSame(['items' => []], $this->controller(uid: 'admin', admin: true, maintainer: false, memberOf: [])->plan()->getData());
	}//end testAnAdministratorOfBothOrANextcloudAdministratorMoves()

	/**
	 * A malformed request is 400 and an anonymous one 401, before anything else.
	 *
	 * @return void
	 */
	public function testMalformedAndAnonymousRequests(): void {
		$this->transfers->expects($this->never())->method('plan');

		$this->params['objects'] = [];
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(uid: 'admin', admin: true, maintainer: false, memberOf: [])->plan()->getStatus());

		$this->params['objects'] = [['schema' => 'module', 'id' => 'app-1']];
		$this->params['targetOrganisation'] = ' ';
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(uid: 'admin', admin: true, maintainer: false, memberOf: [])->plan()->getStatus());

		$this->params['targetOrganisation'] = 'org-b';
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null, admin: false, maintainer: false, memberOf: [])->plan()->getStatus());
	}//end testMalformedAndAnonymousRequests()

	/**
	 * Both routes are open to non-admins, so the guard in the method is what protects them.
	 *
	 * @return void
	 */
	public function testBothRoutesDeclareNoAdminRequired(): void {
		foreach (['plan', 'execute'] as $method) {
			$this->assertCount(1, (new ReflectionMethod(OwnershipTransferController::class, $method))->getAttributes(NoAdminRequired::class), $method);
		}
	}//end testBothRoutesDeclareNoAdminRequired()

	/**
	 * The controller for this caller.
	 *
	 * @param string|null  $uid        The caller, null when signed out
	 * @param bool         $admin      Whether they are a Nextcloud admin
	 * @param bool         $maintainer Whether they are in the maintainer group
	 * @param list<string> $memberOf   Their organisations
	 *
	 * @return OwnershipTransferController
	 */
	private function controller(?string $uid, bool $admin, bool $maintainer, array $memberOf): OwnershipTransferController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $u, string $group): bool => $group === 'maintainer' && $maintainer);

		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserOrganisations')->willReturn(array_map(
			static function (string $uuid): Organisation {
				$organisation = new Organisation();
				$organisation->setUuid($uuid);
				return $organisation;
			},
			$memberOf
		));

		return new OwnershipTransferController($request, $this->transfers, $session, $groups, $organisations, $this->createMock(LoggerInterface::class));
	}//end controller()
}//end class
