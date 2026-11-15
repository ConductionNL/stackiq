<?php

/**
 * Unit tests for ContactpersonenController::convertToUser() and the
 * refusals of getBulkUserInfo().
 *
 * Asserts who may convert a contact person to an account, each refusal of
 * the convert flow (not found, already linked, another organisation, bad
 * email, no account created, a thrown failure), and on success the groups,
 * the organisation link, the persisted record and the answer.
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
use OCA\Stackiq\Kernel\Contracts\ContactPersonHandlerInterface;
use OCA\Stackiq\Kernel\Contracts\ContactPersonOrganisationGuardInterface;
use OCA\Stackiq\Kernel\Contracts\ContactpersoonServiceInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
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
use OCA\Stackiq\Tests\Support\LegacyObjectArgs;

/**
 * The convert-to-user flow of the contact persons controller.
 *
 * @spec openspec/specs/contactpersonen-api/spec.md
 */
#[CoversClass(ContactpersonenController::class)]
class ContactpersonenControllerConvertToUserTest extends TestCase {

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
	 * Contact person handler mock.
	 *
	 * @var ContactPersonHandlerInterface&MockObject
	 */
	private ContactPersonHandlerInterface&MockObject $handler;

	/**
	 * Organisation guard mock.
	 *
	 * @var ContactPersonOrganisationGuardInterface&MockObject
	 */
	private ContactPersonOrganisationGuardInterface&MockObject $guard;

	/**
	 * Contact person service mock.
	 *
	 * @var ContactpersoonServiceInterface&MockObject
	 */
	private ContactpersoonServiceInterface&MockObject $contactSvc;

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
		$this->groupManager  = $this->createMock(IGroupManager::class);
		$this->userSession   = $this->createMock(IUserSession::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->handler       = $this->createMock(ContactPersonHandlerInterface::class);
		$this->guard         = $this->createMock(ContactPersonOrganisationGuardInterface::class);
		$this->contactSvc    = $this->createMock(ContactpersoonServiceInterface::class);

		$this->controller = new ContactpersonenController(
			'stackiq',
			$this->createMock(IRequest::class),
			$this->createMock(RegisterConfigReaderInterface::class),
			$this->handler,
			$this->contactSvc,
			$this->createMock(IUserManager::class),
			$this->groupManager,
			$this->userSession,
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			objectService: $this->objectService,
			organisationService: $this->createMock(OrganisationService::class),
			organisationGuard: $this->guard,
		);
	}//end setUp()

	/**
	 * A user double with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Sign in a caller with the given roles.
	 *
	 * @param bool         $admin  Whether the caller is a full administrator.
	 * @param list<string> $groups The caller's groups.
	 *
	 * @return void
	 */
	private function signIn(bool $admin, array $groups=[]): void {
		$this->userSession->method('getUser')->willReturn($this->user('caller'));
		$this->groupManager->method('isAdmin')->with('caller')->willReturn($admin);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, $groups, true)
		);
	}//end signIn()

	/**
	 * A stored contact person.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function contactPerson(array $data): ObjectEntityInterface&MockObject {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getRegister')->willReturn('5');
		$entity->method('getSchema')->willReturn('12');
		$entity->method('getUuid')->willReturn('cp-1');
		$entity->method('jsonSerialize')->willReturn(['id' => 'cp-1', 'voornaam' => 'Jan']);
		return $entity;
	}//end contactPerson()

	/**
	 * Without a session the convert is refused with 401 and nothing is read.
	 *
	 * @return void
	 */
	public function testConvertWithoutASessionIsUnauthorised(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects($this->never())->method('find');

		$response = $this->controller->convertToUser('cp-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testConvertWithoutASessionIsUnauthorised()

	/**
	 * A caller who is neither an administrator nor an organisation admin is refused with 403.
	 *
	 * @return void
	 */
	public function testConvertByAPlainUserIsForbidden(): void {
		$this->signIn(admin: false, groups: ['gebruik-raadpleger']);
		$this->objectService->expects($this->never())->method('find');

		$response = $this->controller->convertToUser('cp-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Insufficient permissions'], $response->getData());
	}//end testConvertByAPlainUserIsForbidden()

	/**
	 * The contact person is read in the caller's tenant; an unknown one answers 404.
	 *
	 * @return void
	 */
	public function testAnUnknownContactPersonIsNotFound(): void {
		$this->signIn(admin: false, groups: ['aanbod-beheerder']);
		$read = [];
		$this->objectService->expects($this->once())->method('find')->willReturnCallback(
			static function (...$args) use (&$read): ?ObjectEntityInterface {
				$args = LegacyObjectArgs::positional(method: 'find', args: $args);
				$read = $args;
				return null;
			}
		);

		$response = $this->controller->convertToUser('cp-x');

		// Positional: id, _extend, files, register, schema, _rbac, _multitenancy.
		$this->assertSame(['cp-x', [], false, 'stackiq', 'contactPerson', true, true], array_slice($read, 0, 7));

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Contactpersoon not found'], $response->getData());
	}//end testAnUnknownContactPersonIsNotFound()

	/**
	 * A contact person that already has an account is refused with 400.
	 *
	 * @return void
	 */
	public function testAContactPersonWithAnAccountIsRefused(): void {
		$this->signIn(admin: true);
		$this->objectService->method('find')->willReturn($this->contactPerson(['username' => 'jan']));
		$this->handler->expects($this->never())->method('createUserAccount');

		$response = $this->controller->convertToUser('cp-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Contactpersoon already has a user account', $response->getData()['message']);
	}//end testAContactPersonWithAnAccountIsRefused()

	/**
	 * A contact person of another organisation is refused with 403.
	 *
	 * @return void
	 */
	public function testAContactPersonTheGuardRefusesIsForbidden(): void {
		$this->signIn(admin: false, groups: ['gebruik-beheerder']);
		$data = ['voornaam' => 'Jan', 'organisation' => 'org-b'];
		$this->objectService->method('find')->willReturn($this->contactPerson($data));
		$this->guard->expects($this->once())->method('allows')->with($data)->willReturn(false);
		$this->handler->expects($this->never())->method('createUserAccount');

		$response = $this->controller->convertToUser('cp-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Insufficient permissions'], $response->getData());
	}//end testAContactPersonTheGuardRefusesIsForbidden()

	/**
	 * An email the handler rejects is refused with its message.
	 *
	 * @return void
	 */
	public function testAnInvalidEmailIsRefusedWithTheHandlersMessage(): void {
		$this->signIn(admin: true);
		$this->objectService->method('find')->willReturn($this->contactPerson(['e-mailadres' => 'not-an-email']));
		$this->guard->method('allows')->willReturn(true);
		$this->handler->expects($this->once())->method('validateEmailForUsername')->with('not-an-email')
			->willReturn('Invalid email address');
		$this->handler->expects($this->never())->method('createUserAccount');

		$response = $this->controller->convertToUser('cp-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Invalid email address'], $response->getData());
	}//end testAnInvalidEmailIsRefusedWithTheHandlersMessage()

	/**
	 * When no account is created the answer is 500 and nothing is saved.
	 *
	 * @return void
	 */
	public function testAFailedAccountCreationAnswers500(): void {
		$this->signIn(admin: true);
		$this->objectService->method('find')->willReturn($this->contactPerson(['email' => 'jan@example.org']));
		$this->guard->method('allows')->willReturn(true);
		$this->handler->method('validateEmailForUsername')->willReturn(null);
		$this->handler->method('createUserAccount')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller->convertToUser('cp-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Failed to create user account'], $response->getData());
	}//end testAFailedAccountCreationAnswers500()

	/**
	 * A thrown failure answers 500 with its message.
	 *
	 * @return void
	 */
	public function testAThrownFailureAnswers500WithItsMessage(): void {
		$this->signIn(admin: true);
		$this->objectService->method('find')->willThrowException(new RuntimeException('database gone'));

		$response = $this->controller->convertToUser('cp-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Failed to create user account: database gone'], $response->getData());
	}//end testAThrownFailureAnswers500WithItsMessage()

	/**
	 * A successful convert assigns the groups, links the organisation, saves
	 * the username without validation and answers with the catalogue groups.
	 *
	 * @return void
	 */
	public function testASuccessfulConvertLinksSavesAndAnswersWithTheCatalogueGroups(): void {
		$this->signIn(admin: false, groups: ['aanbod-beheerder']);
		$data = ['voornaam' => 'Jan', 'achternaam' => 42, 'email' => 'jan@example.org', 'organisation' => 'org-a'];
		$contactPerson = $this->contactPerson($data);
		$this->objectService->method('find')->willReturn($contactPerson);
		$this->guard->method('allows')->willReturn(true);
		$this->handler->method('validateEmailForUsername')->with('jan@example.org')->willReturn(null);
		$account = $this->user('jan.jansen');
		$this->handler->expects($this->once())->method('createUserAccount')->with($contactPerson)->willReturn($account);
		$this->handler->expects($this->once())->method('updateUserGroupsFromContactData')->with($account, $data);
		$this->handler->expects($this->once())->method('addUserToOrganizationEntity')->with($contactPerson, 'jan.jansen', 'org-a');
		$saved = [];
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$saved, $contactPerson): ObjectEntityInterface {
				$args = LegacyObjectArgs::positional(method: 'saveObject', args: $args);
				$saved = $args;
				return $contactPerson;
			}
		);
		$this->groupManager->method('getUserGroups')->with($account)->willReturn(
			[$this->group('aanbod-beheerder'), $this->group('admin'), $this->group('gebruik-raadpleger')]
		);

		$response = $this->controller->convertToUser('cp-1');

		// Positional: object, _extend, register, schema, uuid, _rbac, _multitenancy, silent, _validation.
		$this->assertSame(
			[
				['voornaam' => 'Jan', 'achternaam' => '42', 'email' => 'jan@example.org', 'organisation' => null, 'username' => 'jan.jansen'],
				[],
				'5',
				'12',
				'cp-1',
				true,
				true,
				true,
				false,
			],
			array_slice($saved, 0, 9)
		);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'success' => true,
				'message' => 'User account created successfully',
				'username' => 'jan.jansen',
				'contactPerson' => ['id' => 'cp-1', 'voornaam' => 'Jan', 'groups' => ['aanbod-beheerder', 'gebruik-raadpleger']],
			],
			$response->getData()
		);
	}//end testASuccessfulConvertLinksSavesAndAnswersWithTheCatalogueGroups()

	/**
	 * Without an organisation no groups are re-derived, and the link gets an empty override.
	 *
	 * @return void
	 */
	public function testAConvertWithoutAnOrganisationSkipsTheGroupUpdate(): void {
		$this->signIn(admin: true);
		$contactPerson = $this->contactPerson(['email' => 'jan@example.org']);
		$this->objectService->method('find')->willReturn($contactPerson);
		$this->guard->method('allows')->willReturn(true);
		$this->handler->method('validateEmailForUsername')->willReturn(null);
		$this->handler->method('createUserAccount')->willReturn($this->user('jan'));
		$this->handler->expects($this->never())->method('updateUserGroupsFromContactData');
		$this->handler->expects($this->once())->method('addUserToOrganizationEntity')->with($contactPerson, 'jan', '');
		$this->objectService->method('saveObject')->willReturn($contactPerson);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$response = $this->controller->convertToUser('cp-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['contactPerson']['groups']);
	}//end testAConvertWithoutAnOrganisationSkipsTheGroupUpdate()

	/**
	 * The bulk user info is refused without permission, and asks for ids without a body.
	 *
	 * @return void
	 */
	public function testBulkUserInfoRefusesAPlainUserAndAnEmptyRequest(): void {
		$this->signIn(admin: false);
		$this->contactSvc->expects($this->never())->method('getBulkUserInfo');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->getBulkUserInfo()->getStatus());
	}//end testBulkUserInfoRefusesAPlainUserAndAnEmptyRequest()

	/**
	 * An administrator without ids in the body gets a 400.
	 *
	 * @return void
	 */
	public function testBulkUserInfoWithoutIdsIsABadRequest(): void {
		$this->signIn(admin: true);
		$this->contactSvc->expects($this->never())->method('getBulkUserInfo');

		$response = $this->controller->getBulkUserInfo();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'No contactpersoon IDs provided'], $response->getData());
	}//end testBulkUserInfoWithoutIdsIsABadRequest()

	/**
	 * A group double.
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

}//end class
