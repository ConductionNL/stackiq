<?php

declare(strict_types=1);

/**
 * Converting a contact person into a user account, through the real controller.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contactpersonen-api/spec.md
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Stackiq\Tests\Unit;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Controller\ContactpersonenController;
use OCA\Stackiq\Service\Contactpersoon\ContactPersonOrganisationGuard;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contactpersonen#convertToUser from the signed-in caller to the saved contact person.
 *
 * @covers \OCA\Stackiq\Controller\ContactpersonenController
 */
class OrganisationUserWorkflowTest extends TestCase {

	private const CONTACT = 'c0000000-0000-4000-8000-000000000001';

	private const ORGANISATION = 'o0000000-0000-4000-8000-000000000001';

	private const OTHER_ORGANISATION = 'o0000000-0000-4000-8000-000000000002';

	/**
	 * The object access.
	 *
	 * @var ObjectServiceInterface|MockObject
	 */
	private ObjectServiceInterface|MockObject $objects;

	/**
	 * The contact person handler.
	 *
	 * @var ContactPersonHandler|MockObject
	 */
	private ContactPersonHandler|MockObject $handler;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager|MockObject $groups;

	/**
	 * The controller under test.
	 *
	 * @var ContactpersonenController
	 */
	private ContactpersonenController $controller;

	/**
	 * Build the controller with a signed-in caller named `caller`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->handler = $this->createMock(ContactPersonHandler::class);
		$this->groups  = $this->createMock(IGroupManager::class);
		$this->groups->method('getUserGroups')->willReturn([]);
		$session       = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user(uid: 'caller'));

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid): Organisation {
				$members = [
					self::ORGANISATION       => ['caller'],
					self::OTHER_ORGANISATION => ['someone'],
				];
				if (isset($members[$uuid]) === false) {
					throw new DoesNotExistException('no organisation');
				}

				$organisation = new Organisation();
				$organisation->setUuid($uuid);
				$organisation->setUsers($members[$uuid]);
				return $organisation;
			}
		);
		$guard = new ContactPersonOrganisationGuard(
			$session,
			$this->groups,
			$this->createMock(IUserManager::class),
			$mapper,
			$this->createMock(ObjectService::class),
			$this->createMock(SettingsService::class)
		);

		$this->controller = new ContactpersonenController(
			'stackiq',
			$this->createMock(IRequest::class),
			$this->createMock(SettingsService::class),
			$this->handler,
			$this->createMock(ContactpersoonService::class),
			$this->createMock(IUserManager::class),
			$this->groups,
			$session,
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			objectService: $this->objects,
			organisationService: $this->createMock(OrganisationService::class),
			organisationGuard: $guard,
		);
	}//end setUp()

	/**
	 * The account is made, gets its groups and its organisation, and its username is
	 * saved on the contact person; the contact is looked up within the caller's rights.
	 *
	 * @return void
	 */
	public function testAConvertedContactPersonGetsItsAccountGroupsOrganisationAndUsername(): void {
		$this->callerIsAdmin();
		$data    = ['email' => 'jan@example.org', 'organization' => self::ORGANISATION];
		$contact = $this->contact(data: $data);
		$user    = $this->user(uid: 'jan');

		$this->objects->expects($this->once())->method('find')
			->with(self::CONTACT, [], false, 'stackiq', 'contactPerson', true, true)
			->willReturn($contact);
		$this->handler->method('validateEmailForUsername')->with('jan@example.org')->willReturn(null);
		$this->handler->expects($this->once())->method('createUserAccount')->with($contact)->willReturn($user);
		$this->handler->expects($this->once())->method('updateUserGroupsFromContactData')->with($user, $data);
		$this->handler->expects($this->once())->method('addUserToOrganizationEntity')->with($contact, 'jan', self::ORGANISATION);

		$saved = [];
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function (...$args) use (&$saved, $contact) {
				$saved = $args;
				return $contact;
			}
		);

		$response = $this->controller->convertToUser(self::CONTACT);

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
		self::assertSame('jan', $response->getData()['username']);
		self::assertSame('jan', $saved[0]['username']);
		self::assertSame(['reg-7', 'schema-9', self::CONTACT], array_slice($saved, 2, 3));
		self::assertSame([true, true, true, false], array_slice($saved, 5, 4));
	}//end testAConvertedContactPersonGetsItsAccountGroupsOrganisationAndUsername()

	/**
	 * A contact person that names no organisation gets no groups from one.
	 *
	 * @return void
	 */
	public function testAContactPersonWithoutAnOrganisationGetsNoGroupsFromOne(): void {
		$this->callerIsAdmin();
		$contact = $this->contact(data: ['email' => 'jan@example.org']);
		$this->objects->method('find')->willReturn($contact);
		$this->objects->method('saveObject')->willReturn($contact);
		$this->handler->method('createUserAccount')->willReturn($this->user(uid: 'jan'));
		$this->handler->expects($this->never())->method('updateUserGroupsFromContactData');

		self::assertSame(200, $this->controller->convertToUser(self::CONTACT)->getStatus());
	}//end testAContactPersonWithoutAnOrganisationGetsNoGroupsFromOne()

	/**
	 * Someone who is neither an administrator nor an organisation administrator is
	 * refused for who they are, before anything is looked up.
	 *
	 * @return void
	 */
	public function testAnOrdinaryUserIsRefusedBeforeAnythingIsLookedUp(): void {
		$this->groups->expects($this->once())->method('isAdmin')->with('caller')->willReturn(false);
		$this->groups->method('isInGroup')->willReturnCallback(
			static function (string $uid, string $group): bool {
				self::assertSame('caller', $uid);
				return false;
			}
		);
		$this->objects->expects($this->never())->method('find');
		$this->handler->expects($this->never())->method('createUserAccount');

		self::assertSame(403, $this->controller->convertToUser(self::CONTACT)->getStatus());
	}//end testAnOrdinaryUserIsRefusedBeforeAnythingIsLookedUp()

	/**
	 * A contact person the caller cannot find is a 404.
	 *
	 * @return void
	 */
	public function testAnUnknownContactPersonIs404(): void {
		$this->callerIsAdmin();
		$this->objects->method('find')->willReturn(null);
		$this->handler->expects($this->never())->method('createUserAccount');

		self::assertSame(404, $this->controller->convertToUser(self::CONTACT)->getStatus());
	}//end testAnUnknownContactPersonIs404()

	/**
	 * A contact person that already has an account does not get a second one.
	 *
	 * @return void
	 */
	public function testAContactPersonWithAnAccountIsRefused(): void {
		$this->callerIsAdmin();
		$this->objects->method('find')->willReturn($this->contact(data: ['email' => 'jan@example.org', 'username' => 'jan']));
		$this->handler->expects($this->never())->method('createUserAccount');
		$this->objects->expects($this->never())->method('saveObject');

		$response = $this->controller->convertToUser(self::CONTACT);

		self::assertSame(400, $response->getStatus());
		self::assertSame('Contactpersoon already has a user account', $response->getData()['message']);
	}//end testAContactPersonWithAnAccountIsRefused()

	/**
	 * An e-mail address that cannot become a username stops the conversion with the handler's reason.
	 *
	 * @return void
	 */
	public function testAnUnusableEmailAddressIsRefused(): void {
		$this->callerIsAdmin();
		$this->objects->method('find')->willReturn($this->contact(data: ['e-mailadres' => 'not an address']));
		$this->handler->method('validateEmailForUsername')->with('not an address')->willReturn('Invalid e-mail');
		$this->handler->expects($this->never())->method('createUserAccount');

		$response = $this->controller->convertToUser(self::CONTACT);

		self::assertSame(400, $response->getStatus());
		self::assertSame('Invalid e-mail', $response->getData()['message']);
	}//end testAnUnusableEmailAddressIsRefused()

	/**
	 * When no account could be made, nothing is linked or saved.
	 *
	 * @return void
	 */
	public function testNoAccountMeansNothingIsLinkedOrSaved(): void {
		$this->callerIsAdmin();
		$this->objects->method('find')->willReturn($this->contact(data: ['email' => 'jan@example.org', 'organization' => self::ORGANISATION]));
		$this->handler->method('createUserAccount')->willReturn(null);
		$this->handler->expects($this->never())->method('addUserToOrganizationEntity');
		$this->objects->expects($this->never())->method('saveObject');

		self::assertSame(500, $this->controller->convertToUser(self::CONTACT)->getStatus());
	}//end testNoAccountMeansNothingIsLinkedOrSaved()

	/**
	 * An organisation administrator converts a contact person of their own organisation.
	 *
	 * @return void
	 */
	public function testAnOrganisationAdministratorConvertsAContactOfTheOwnOrganisation(): void {
		$this->callerIsOrganisationAdministrator();
		$contact = $this->contact(data: ['email' => 'jan@example.org', 'organization' => self::ORGANISATION]);
		$this->objects->method('find')->willReturn($contact);
		$this->objects->method('saveObject')->willReturn($contact);
		$this->handler->expects($this->once())->method('createUserAccount')->willReturn($this->user(uid: 'jan'));

		self::assertSame(200, $this->controller->convertToUser(self::CONTACT)->getStatus());
	}//end testAnOrganisationAdministratorConvertsAContactOfTheOwnOrganisation()

	/**
	 * An organisation administrator cannot convert a contact person that names another organisation.
	 *
	 * @return void
	 */
	public function testAnOrganisationAdministratorCannotConvertAContactOfAnotherOrganisation(): void {
		$this->callerIsOrganisationAdministrator();
		$this->objects->method('find')->willReturn($this->contact(data: ['email' => 'jan@example.org', 'organization' => self::OTHER_ORGANISATION]));
		$this->handler->expects($this->never())->method('createUserAccount');
		$this->handler->expects($this->never())->method('addUserToOrganizationEntity');
		$this->objects->expects($this->never())->method('saveObject');

		self::assertSame(403, $this->controller->convertToUser(self::CONTACT)->getStatus());
	}//end testAnOrganisationAdministratorCannotConvertAContactOfAnotherOrganisation()

	/**
	 * The caller is an organisation administrator, not an instance administrator.
	 *
	 * @return void
	 */
	private function callerIsOrganisationAdministrator(): void {
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $uid === 'caller' && $group === 'gebruik-beheerder'
		);
	}//end callerIsOrganisationAdministrator()

	/**
	 * The caller is an administrator; the check is asked about the caller.
	 *
	 * @return void
	 */
	private function callerIsAdmin(): void {
		$this->groups->method('isAdmin')->with('caller')->willReturn(true);
	}//end callerIsAdmin()

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * A stored contact person double.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function contact(array $data): ObjectEntityInterface {
		$contact = $this->createMock(ObjectEntityInterface::class);
		$contact->method('getObject')->willReturn($data);
		$contact->method('getUuid')->willReturn(self::CONTACT);
		$contact->method('getRegister')->willReturn('reg-7');
		$contact->method('getSchema')->willReturn('schema-9');
		$contact->method('jsonSerialize')->willReturn($data);
		return $contact;
	}//end contact()
}//end class
