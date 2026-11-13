<?php

/**
 * Unit tests for the account paths of ContactpersonenController.
 *
 * Asserts how updateUserGroups synchronises the catalogue groups, how the
 * cross-tenant check reads organisation references and fails closed, how
 * getUserInfo builds a contact person's account summary, and how
 * changePassword answers each outcome of the password write.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Account paths of the contact persons controller.
 *
 * @spec openspec/specs/contactpersonen-api/spec.md
 */
#[CoversClass(ContactpersonenController::class)]
class ContactpersonenControllerAccountPathsTest extends TestCase {

	/**
	 * User manager mock.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * Group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var ContactpersonenController
	 */
	private ContactpersonenController $controller;

	/**
	 * Set up fresh mocks and the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->userManager   = $this->createMock(IUserManager::class);
		$this->groupManager  = $this->createMock(IGroupManager::class);
		$this->userSession   = $this->createMock(IUserSession::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger        = $this->createMock(LoggerInterface::class);

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
			$this->logger,
			objectService: $this->objectService,
			organisationService: $this->createMock(OrganisationService::class),
			organisationGuard: $this->createMock(ContactPersonOrganisationGuard::class),
		);
	}//end setUp()

	/**
	 * A user double with the given uid.
	 *
	 * @param string $uid     The uid.
	 * @param bool   $enabled Whether the account is enabled.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid, bool $enabled=true): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}//end user()

	/**
	 * A group double with the given id.
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
	 * A contact person entity whose data is the given array.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function entity(array $data): ObjectEntityInterface&MockObject {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Sign in a full administrator.
	 *
	 * @return void
	 */
	private function signInAdmin(): void {
		$this->userSession->method('getUser')->willReturn($this->user('admin'));
		$this->groupManager->method('isAdmin')->willReturn(true);
	}//end signInAdmin()

	/**
	 * Sign in an organisation administrator who is not a full administrator.
	 *
	 * @param string $uid The caller's uid.
	 *
	 * @return void
	 */
	private function signInOrgAdmin(string $uid): void {
		$this->userSession->method('getUser')->willReturn($this->user($uid));
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $user, string $group): bool => $user === $uid && $group === 'gebruik-beheerder'
		);
	}//end signInOrgAdmin()

	/**
	 * Answer the contact person search with one record per username.
	 *
	 * @param array<string, array<string, mixed>> $byUsername Object data keyed by username.
	 *
	 * @return void
	 */
	private function contactsByUsername(array $byUsername): void {
		$entities = [];
		foreach ($byUsername as $username => $data) {
			$entities[$username] = $this->entity($data);
		}

		$this->objectService->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query) use ($entities): array {
				$username = $query['username'] ?? '';
				if (isset($entities[$username]) === false) {
					return ['results' => []];
				}

				return ['results' => [$entities[$username]]];
			}
		);
	}//end contactsByUsername()

	/**
	 * The catalogue groups are brought in line with the request; other groups are untouched.
	 *
	 * @return void
	 */
	public function testGroupUpdateAddsAndRemovesOnlyCatalogueGroups(): void {
		$this->signInAdmin();
		$target = $this->user('jan');
		$this->userManager->method('get')->with('jan')->willReturn($target);

		$current = [$this->group('aanbod-beheerder'), $this->group('staff')];
		$this->groupManager->method('getUserGroups')->with($target)->willReturn($current);

		$aanbod = $this->group('aanbod-beheerder');
		$aanbod->method('inGroup')->with($target)->willReturn(true);
		$aanbod->expects($this->once())->method('removeUser')->with($target);
		$aanbod->expects($this->never())->method('addUser');

		$gebruik = $this->group('gebruik-beheerder');
		$gebruik->method('inGroup')->with($target)->willReturn(false);
		$gebruik->expects($this->once())->method('addUser')->with($target);
		$gebruik->expects($this->never())->method('removeUser');

		$this->groupManager->method('get')->willReturnCallback(
			static fn (string $gid): ?IGroup => match ($gid) {
				'aanbod-beheerder' => $aanbod,
				'gebruik-beheerder' => $gebruik,
				default => null,
			}
		);

		$response = $this->controller->updateUserGroups('jan', ['gebruik-beheerder', 'admin']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'success' => true,
				'message' => 'User groups updated successfully',
				'groups' => ['aanbod-beheerder', 'staff'],
			],
			$response->getData()
		);
	}//end testGroupUpdateAddsAndRemovesOnlyCatalogueGroups()

	/**
	 * A requested catalogue group that does not exist is skipped with a warning.
	 *
	 * @return void
	 */
	public function testGroupUpdateSkipsAMissingGroup(): void {
		$this->signInAdmin();
		$target = $this->user('jan');
		$this->userManager->method('get')->willReturn($target);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('get')->willReturn(null);

		$this->logger->expects($this->once())->method('warning')->with(
			'Group does not exist, skipping',
			['username' => 'jan', 'group' => 'gebruik-raadpleger']
		);

		$response = $this->controller->updateUserGroups('jan', ['gebruik-raadpleger']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['groups']);
	}//end testGroupUpdateSkipsAMissingGroup()

	/**
	 * A user who is already in a requested group is not added again.
	 *
	 * @return void
	 */
	public function testGroupUpdateDoesNotReAddAMember(): void {
		$this->signInAdmin();
		$target = $this->user('jan');
		$this->userManager->method('get')->willReturn($target);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$group = $this->group('gebruik-raadpleger');
		$group->method('inGroup')->willReturn(true);
		$group->expects($this->never())->method('addUser');
		$this->groupManager->method('get')->willReturn($group);

		$response = $this->controller->updateUserGroups('jan', ['gebruik-raadpleger']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testGroupUpdateDoesNotReAddAMember()

	/**
	 * An unknown target user answers 404.
	 *
	 * @return void
	 */
	public function testGroupUpdateForAnUnknownUserAnswers404(): void {
		$this->signInAdmin();
		$this->userManager->method('get')->willReturn(null);

		$response = $this->controller->updateUserGroups('ghost', ['gebruik-raadpleger']);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'User not found'], $response->getData());
	}//end testGroupUpdateForAnUnknownUserAnswers404()

	/**
	 * A failure while syncing groups answers 500 with the error.
	 *
	 * @return void
	 */
	public function testGroupUpdateFailureAnswers500(): void {
		$this->signInAdmin();
		$this->userManager->method('get')->willReturn($this->user('jan'));
		$this->groupManager->method('getUserGroups')->willThrowException(new RuntimeException('backend down'));

		$response = $this->controller->updateUserGroups('jan', ['gebruik-raadpleger']);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Failed to update user groups: backend down'],
			$response->getData()
		);
	}//end testGroupUpdateFailureAnswers500()

	/**
	 * When the organisation lookup throws, an organisation admin is refused.
	 *
	 * @return void
	 */
	public function testCrossTenantCheckFailsClosedOnALookupError(): void {
		$this->signInOrgAdmin('beheer@a.nl');
		$this->objectService->method('searchObjectsPaginated')->willThrowException(new RuntimeException('search down'));
		$this->userManager->expects($this->never())->method('get');

		$response = $this->controller->updateUserGroups('jan@b.nl', ['gebruik-raadpleger']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Forbidden: organisation scope could not be verified'],
			$response->getData()
		);
	}//end testCrossTenantCheckFailsClosedOnALookupError()

	/**
	 * Nested references and the `organization` spelling are read when comparing tenants.
	 *
	 * @return void
	 */
	public function testCrossTenantCheckReadsNestedAndAlternateReferences(): void {
		$this->signInOrgAdmin('beheer@a.nl');
		$this->contactsByUsername(
			[
				'beheer@a.nl' => ['organisation' => ['@self' => ['id' => ' org-a ']]],
				'jan@b.nl' => ['organisation' => '  ', 'organization' => ['uuid' => 'org-b']],
			]
		);
		$this->userManager->expects($this->never())->method('get');

		$response = $this->controller->updateUserGroups('jan@b.nl', ['gebruik-raadpleger']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Forbidden: target user belongs to a different organisation'],
			$response->getData()
		);
	}//end testCrossTenantCheckReadsNestedAndAlternateReferences()

	/**
	 * A reference without any usable identifier counts as no organisation, so the update proceeds.
	 *
	 * @return void
	 */
	public function testAnUnreadableReferenceDoesNotBlockTheUpdate(): void {
		$this->signInOrgAdmin('beheer@a.nl');
		$this->contactsByUsername(
			[
				'beheer@a.nl' => ['organisation' => 'org-a'],
				'jan@a.nl' => ['organisation' => ['name' => 'Org A', 'uuid' => ''], 'organization' => 42],
			]
		);
		$target = $this->user('jan@a.nl');
		$this->userManager->method('get')->with('jan@a.nl')->willReturn($target);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$response = $this->controller->updateUserGroups('jan@a.nl', []);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testAnUnreadableReferenceDoesNotBlockTheUpdate()

	/**
	 * User info reports the account, its catalogue groups, its state and the existing groups.
	 *
	 * @return void
	 */
	public function testUserInfoDescribesTheLinkedAccount(): void {
		$this->signInAdmin();
		$this->objectService->expects($this->once())->method('find')
			->with('cp-1', [], false, 'stackiq', 'contactPerson')
			->willReturn($this->entity(['username' => 'jan']));

		$account = $this->user('jan', false);
		$this->userManager->method('get')->with('jan')->willReturn($account);
		$this->groupManager->method('getUserGroups')->with($account)->willReturn(
			[$this->group('staff'), $this->group('gebruik-raadpleger')]
		);
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => $gid === 'aanbod-beheerder' ? $this->group($gid) : null
		);

		$response = $this->controller->getUserInfo('cp-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'success' => true,
				'userInfo' => [
					'hasUser' => true,
					'username' => 'jan',
					'groups' => ['gebruik-raadpleger'],
					'disabled' => true,
				],
				'availableGroups' => [
					['id' => 'aanbod-beheerder', 'name' => 'Aanbod Beheerder', 'description' => 'Manages software offerings and catalog content'],
				],
			],
			$response->getData()
		);
	}//end testUserInfoDescribesTheLinkedAccount()

	/**
	 * A contact person without an account reports no user.
	 *
	 * @return void
	 */
	public function testUserInfoWithoutAnAccount(): void {
		$this->signInAdmin();
		$this->objectService->method('find')->willReturn($this->entity(['naam' => 'Jan']));
		$this->userManager->expects($this->never())->method('get');

		$response = $this->controller->getUserInfo('cp-1');

		$this->assertSame(
			['hasUser' => false, 'username' => null, 'groups' => [], 'disabled' => false],
			$response->getData()['userInfo']
		);
	}//end testUserInfoWithoutAnAccount()

	/**
	 * A username whose account no longer exists reports the name without groups.
	 *
	 * @return void
	 */
	public function testUserInfoForADeletedAccount(): void {
		$this->signInAdmin();
		$this->objectService->method('find')->willReturn($this->entity(['username' => 'gone']));
		$this->userManager->method('get')->willReturn(null);

		$response = $this->controller->getUserInfo('cp-1');

		$this->assertSame(
			['hasUser' => true, 'username' => 'gone', 'groups' => [], 'disabled' => false],
			$response->getData()['userInfo']
		);
	}//end testUserInfoForADeletedAccount()

	/**
	 * An unknown contact person answers 404.
	 *
	 * @return void
	 */
	public function testUserInfoForAnUnknownContactAnswers404(): void {
		$this->signInAdmin();
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller->getUserInfo('cp-x');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Contactpersoon not found'], $response->getData());
	}//end testUserInfoForAnUnknownContactAnswers404()

	/**
	 * A failing lookup answers 500 with the error.
	 *
	 * @return void
	 */
	public function testUserInfoFailureAnswers500(): void {
		$this->signInAdmin();
		$this->objectService->method('find')->willThrowException(new RuntimeException('register offline'));

		$response = $this->controller->getUserInfo('cp-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Failed to get user info: register offline'],
			$response->getData()
		);
	}//end testUserInfoFailureAnswers500()

	/**
	 * A plain user may not read a contact person's account info.
	 *
	 * @return void
	 */
	public function testUserInfoRefusesAPlainUser(): void {
		$this->userSession->method('getUser')->willReturn($this->user('jan'));
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->objectService->expects($this->never())->method('find');

		$response = $this->controller->getUserInfo('cp-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testUserInfoRefusesAPlainUser()

	/**
	 * An administrator sets another user's password.
	 *
	 * @return void
	 */
	public function testAdminChangesAPassword(): void {
		$this->signInAdmin();
		$target = $this->user('jan');
		$target->expects($this->once())->method('setPassword')->with('a-long-passphrase')->willReturn(true);
		$this->userManager->method('get')->with('jan')->willReturn($target);

		$response = $this->controller->changePassword('jan', 'a-long-passphrase');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true, 'message' => 'Password changed successfully'], $response->getData());
	}//end testAdminChangesAPassword()

	/**
	 * A password the backend rejects answers 400.
	 *
	 * @return void
	 */
	public function testARejectedPasswordAnswers400(): void {
		$this->signInAdmin();
		$target = $this->user('jan');
		$target->method('setPassword')->willReturn(false);
		$this->userManager->method('get')->willReturn($target);

		$response = $this->controller->changePassword('jan', 'password1234');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			[
				'success' => false,
				'message' => 'Password was rejected: may be too common or violate the policy. Please choose another.',
			],
			$response->getData()
		);
	}//end testARejectedPasswordAnswers400()

	/**
	 * A failing password write answers 500 with the error.
	 *
	 * @return void
	 */
	public function testAFailingPasswordWriteAnswers500(): void {
		$this->signInAdmin();
		$target = $this->user('jan');
		$target->method('setPassword')->willThrowException(new RuntimeException('policy service down'));
		$this->userManager->method('get')->willReturn($target);

		$response = $this->controller->changePassword('jan', 'a-long-passphrase');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Failed to change password: policy service down'],
			$response->getData()
		);
	}//end testAFailingPasswordWriteAnswers500()
}//end class
