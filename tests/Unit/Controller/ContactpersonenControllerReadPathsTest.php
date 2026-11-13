<?php

/**
 * The read paths of ContactpersonenController that answer account data:
 * the catalog groups on offer, the bulk user info guard and input checks,
 * and the organisation's contact list with each contact's account.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Controller\ContactpersonenController;
use OCA\Stackiq\Service\Contactpersoon\ContactPersonOrganisationGuard;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCP\AppFramework\Http;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Stackiq\Controller\ContactpersonenController
 */
class ContactpersonenControllerReadPathsTest extends TestCase {

	private const ORG = 'org-uuid-A';

	private IGroupManager&MockObject $groupManager;

	private IUserManager&MockObject $userManager;

	private IUserSession&MockObject $userSession;

	private ObjectServiceInterface&MockObject $objectService;

	private ContactpersoonService&MockObject $contactSvc;

	private LoggerInterface&MockObject $logger;

	private ContactpersonenController $controller;

	/**
	 * Build the controller over doubles of its boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->contactSvc = $this->createMock(ContactpersoonService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ContactpersonenController(
			'stackiq',
			$this->createMock(IRequest::class),
			$this->createMock(SettingsService::class),
			$this->createMock(ContactPersonHandler::class),
			$this->contactSvc,
			$this->userManager,
			$this->groupManager,
			$this->userSession,
			$this->createMock(ISecureRandom::class),
			$this->logger,
			objectService: $this->objectService,
			organisationService: $this->createMock(OrganisationService::class),
			organisationGuard: $this->createMock(ContactPersonOrganisationGuard::class),
		);
	}//end setUp()

	/**
	 * Sign in a caller.
	 *
	 * @param string             $uid    The caller uid.
	 * @param bool               $admin  Whether the caller is an instance admin.
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return void
	 */
	private function signIn(string $uid, bool $admin, array $groups = []): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($admin);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $group): bool => $who === $uid && in_array($group, $groups, true)
		);
	}//end signIn()

	/**
	 * A contactpersoon record.
	 *
	 * @param array<string, mixed> $data The object data.
	 * @param int                  $id   The record id.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function contact(array $data, int $id): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getId')->willReturn($id);
		$entity->method('getUuid')->willReturn('contact-' . $id);
		return $entity;
	}//end contact()

	/**
	 * A group double with a gid.
	 *
	 * @param string $gid The group id.
	 *
	 * @return IGroup&MockObject
	 */
	private function group(string $gid): IGroup&MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		return $group;
	}//end group()

	/**
	 * Without a session the groups on offer are not answered.
	 *
	 * @return void
	 */
	public function testAvailableGroupsNeedASession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->groupManager->expects(self::never())->method('get');

		$response = $this->controller->getAvailableGroups();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testAvailableGroupsNeedASession()

	/**
	 * Only the catalog groups that exist on this instance are offered, in order.
	 *
	 * @return void
	 */
	public function testOnlyExistingCatalogGroupsAreOffered(): void {
		$this->signIn(uid: 'beheer', admin: false);
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => in_array($gid, ['gebruik-beheerder', 'gebruik-raadpleger'], true) ? $this->group(gid: $gid) : null
		);

		$response = $this->controller->getAvailableGroups();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['success']);
		self::assertSame(['gebruik-beheerder', 'gebruik-raadpleger'], array_column($response->getData()['groups'], 'id'));
		self::assertSame('Gebruik Raadpleger', $response->getData()['groups'][1]['name']);
	}//end testOnlyExistingCatalogGroupsAreOffered()

	/**
	 * A failing group lookup is logged and answered as a 500.
	 *
	 * @return void
	 */
	public function testAFailingGroupLookupAnswers500(): void {
		$this->signIn(uid: 'beheer', admin: false);
		$this->groupManager->method('get')->willThrowException(new RuntimeException('ldap down'));
		$this->logger->expects(self::once())->method('error')->with('Failed to get available groups: ldap down');

		$response = $this->controller->getAvailableGroups();

		self::assertSame(500, $response->getStatus());
		self::assertSame(['success' => false, 'message' => 'Failed to retrieve available groups: ldap down'], $response->getData());
	}//end testAFailingGroupLookupAnswers500()

	/**
	 * Bulk user info needs a session, then an admin or an organisation administrator.
	 *
	 * @return void
	 */
	public function testBulkUserInfoIsGuarded(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->contactSvc->expects(self::never())->method('getBulkUserInfo');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->getBulkUserInfo()->getStatus());
	}//end testBulkUserInfoIsGuarded()

	/**
	 * A plain user without an administrator group is refused bulk user info.
	 *
	 * @return void
	 */
	public function testBulkUserInfoRefusesAPlainUser(): void {
		$this->signIn(uid: 'plain', admin: false, groups: ['gebruik-raadpleger']);
		$this->contactSvc->expects(self::never())->method('getBulkUserInfo');

		$response = $this->controller->getBulkUserInfo();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Insufficient permissions'], $response->getData());
	}//end testBulkUserInfoRefusesAPlainUser()

	/**
	 * An organisation administrator passes the guard; a request naming no ids is a 400.
	 *
	 * @return void
	 */
	public function testBulkUserInfoWithoutIdsAnswers400(): void {
		$this->signIn(uid: 'beheer', admin: false, groups: ['aanbod-beheerder']);
		$this->contactSvc->expects(self::never())->method('getBulkUserInfo');

		$response = $this->controller->getBulkUserInfo();

		self::assertSame(400, $response->getStatus());
		self::assertSame(['success' => false, 'message' => 'No contactpersoon IDs provided'], $response->getData());
	}//end testBulkUserInfoWithoutIdsAnswers400()

	/**
	 * Without a session the contact list is not answered.
	 *
	 * @return void
	 */
	public function testTheContactListNeedsASession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects(self::never())->method('searchObjectsPaginated');

		$response = $this->controller->getContactpersonen(self::ORG);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testTheContactListNeedsASession()

	/**
	 * The list holds only the organisation's contacts, matched on either spelling and on a
	 * nested reference, each with its account: the catalog groups only, and whether it is disabled.
	 *
	 * @return void
	 */
	public function testTheContactListCarriesEachContactsAccount(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->objectService->expects(self::once())
			->method('searchObjectsPaginated')
			->with(['organisation' => self::ORG, '_limit' => 100, '_schema' => 'contactPerson'])
			->willReturn([
				'results' => [
					$this->contact(data: ['organisation' => ['uuid' => self::ORG], 'username' => 'piet'], id: 1),
					$this->contact(data: ['organization' => ' ' . self::ORG . ' '], id: 2),
					$this->contact(data: ['organisation' => 'org-uuid-B', 'username' => 'vreemd'], id: 3),
					$this->contact(data: ['naam' => 'Zonder organisatie'], id: 4),
					$this->contact(data: ['organisation' => ['@self' => ['id' => self::ORG]], 'username' => 'weg'], id: 5),
				],
			]);
		$piet = $this->createMock(IUser::class);
		$piet->method('isEnabled')->willReturn(false);
		$this->userManager->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => $uid === 'piet' ? $piet : null
		);
		$this->groupManager->method('getUserGroups')->with($piet)->willReturn(
			[$this->group(gid: 'admin'), $this->group(gid: 'aanbod-beheerder'), $this->group(gid: 'gebruik-raadpleger')]
		);

		$response = $this->controller->getContactpersonen(self::ORG);
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(3, $data['total']);
		self::assertSame([1, 2, 5], array_column($data['contactpersonen'], 'id'));
		self::assertSame(
			['hasUser' => true, 'username' => 'piet', 'groups' => ['aanbod-beheerder', 'gebruik-raadpleger'], 'disabled' => true],
			$data['contactpersonen'][0]['user']
		);
		self::assertSame(['hasUser' => false, 'username' => null, 'groups' => [], 'disabled' => false], $data['contactpersonen'][1]['user']);
		self::assertSame(['hasUser' => true, 'username' => 'weg', 'groups' => [], 'disabled' => false], $data['contactpersonen'][2]['user']);
		self::assertSame('contact-2', $data['contactpersonen'][1]['uuid']);
		self::assertStringNotContainsString('vreemd', (string)json_encode($data));
	}//end testTheContactListCarriesEachContactsAccount()

	/**
	 * A failing search is logged and answered as a 500.
	 *
	 * @return void
	 */
	public function testAFailingContactSearchAnswers500(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->objectService->method('searchObjectsPaginated')->willThrowException(new RuntimeException('register gone'));
		$this->logger->expects(self::once())->method('error')->with('Failed to get contactpersonen: register gone', self::anything());

		$response = $this->controller->getContactpersonen(self::ORG);

		self::assertSame(500, $response->getStatus());
		self::assertSame(['success' => false, 'message' => 'Failed to retrieve contactpersonen: register gone'], $response->getData());
	}//end testAFailingContactSearchAnswers500()
}//end class
